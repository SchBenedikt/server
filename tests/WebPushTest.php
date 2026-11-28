<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Server\Push\PushSender;
use AlohaSocial\Server\Push\WebPush;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function hash_hmac;
use function is_string;
use function json_decode;
use function openssl_decrypt;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function openssl_pkey_new;
use function openssl_pkey_derive;
use function openssl_verify;
use function ord;
use function rtrim;
use function str_repeat;
use function strtr;
use function strlen;
use function substr;
use function unpack;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const OPENSSL_RAW_DATA;

/**
 * Web Push wire format: the VAPID JWT must verify under the instance public
 * key, and an aes128gcm body must decrypt back to the payload on the
 * receiver side (RFC 8291/8188). The sender maps push-service statuses to
 * sent/gone/failed and drops dead endpoints.
 */
final class WebPushTest extends TestCase
{
    /**
     * @return array{private_pem: string, public_point: string, public_key_b64: string}
     */
    private static function uaKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $point = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem));

        return [
            'private_pem' => $pem,
            'public_point' => $point,
            'public_key_b64' => WebPush::base64Url($point),
        ];
    }

    public function testVapidJwtVerifiesAgainstThePublicKey(): void
    {
        $ua = self::uaKeyPair();
        // The VAPID pair uses the same EC shape; reuse the helper's PEM.
        $jwt = WebPush::vapidJwt(
            'https://push.example.test',
            'mailto:ops@example.test',
            $ua['private_pem'],
            1_700_000_000,
        );

        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        $header = json_decode(self::b64($parts[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ES256', $header['alg']);

        $claims = json_decode(self::b64($parts[1]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://push.example.test', $claims['aud']);
        self::assertSame('mailto:ops@example.test', $claims['sub']);
        self::assertSame(1_700_000_000 + 12 * 3600, $claims['exp']);

        // Convert raw R||S back to DER for openssl_verify.
        $raw = self::b64($parts[2]);
        self::assertSame(64, strlen($raw));
        $der = self::rawToDer($raw);
        $public = openssl_pkey_get_public(self::publicKeyPem($ua['public_point']));
        self::assertNotFalse($public);
        self::assertSame(
            1,
            openssl_verify($parts[0] . '.' . $parts[1], $der, $public, OPENSSL_ALGO_SHA256),
        );
    }

    public function testEncryptedPayloadDecryptsOnTheReceiverSide(): void
    {
        $ua = self::uaKeyPair();
        $auth = WebPush::base64Url(random_bytes(16));
        $plaintext = '{"title":"New follower","body":"alice started following you"}';

        $encrypted = WebPush::encrypt($ua['public_key_b64'], $auth, $plaintext);
        self::assertSame('aes128gcm', $encrypted['content_encoding']);

        $decrypted = self::receiverDecrypt(
            $encrypted['body'],
            $ua['private_pem'],
            $ua['public_point'],
            $auth,
        );
        self::assertSame($plaintext, $decrypted);
    }

    public function testSenderDeliversAndMapsStatuses(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $login = $server->signIn('alice');

        $subscriptions = $server->runtime->pushSubscriptions;
        $subscriptions->subscribe('alice', 'https://push.example.test/sub/1', 'BP256dh-key', 'auth-one');
        $subscriptions->subscribe('alice', 'https://push.example.test/sub/2', 'BP256dh-key', 'auth-two');

        $http = new class implements BoundedHttpClient {
            /** @var list<array{url: string, headers: array<string, string>, body: string}> */
            public array $requests = [];

            public int $nextStatus = 201;

            public function request(string $method, string $url, array $headers = [], string $body = '', int $timeoutSeconds = 10): HttpResponse
            {
                return $this->requestBounded($method, $url, $headers, $body, $timeoutSeconds, 4096, []);
            }

            public function requestBounded(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
                int $maxBytes = 1_048_576,
                array $resolvedIps = [],
            ): HttpResponse {
                $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

                return new HttpResponse($this->nextStatus);
            }
        };

        $sender = new PushSender(
            $subscriptions,
            $http,
            new FederationTargetGuard(new class implements HostResolver {
                public function resolve(string $host): array
                {
                    return ['93.184.216.34'];
                }
            }),
            'https://social.example.test',
            'ops@example.test',
            $server->runtime->clock,
        );

        // p256dh 'BP256dh-key' is not a real 65-byte point, so delivery fails
        // at encryption — proving bad rows surface as failures, not crashes.
        $outcome = $sender->deliverAll('{"type":"notification"}');
        self::assertSame(['sent' => 0, 'gone' => 0, 'failed' => 2], $outcome);

        // With a real subscription key the POST goes out with VAPID headers.
        $ua = self::uaKeyPair();
        $subscriptions->subscribe('alice', 'https://push.example.test/sub/real', $ua['public_key_b64'], WebPush::base64Url(random_bytes(16)));
        $http->nextStatus = 201;
        $outcome = $sender->deliverAll('{"type":"notification"}');
        self::assertSame(1, $outcome['sent']);
        self::assertSame(2, $outcome['failed']);

        $last = $http->requests[array_key_last($http->requests)];
        self::assertSame('https://push.example.test/sub/real', $last['url']);
        self::assertStringStartsWith('vapid t=', (string) ($last['headers']['Authorization'] ?? ''));
        self::assertStringContainsString(', k=', (string) $last['headers']['Authorization']);
        self::assertSame('aes128gcm', $last['headers']['Content-Encoding']);
        self::assertNotSame('', $last['body']);

        // A 410 removes the endpoint instead of retrying forever — both
        // real-key subscriptions die, the malformed ones still fail early.
        $subscriptions->subscribe('alice', 'https://push.example.test/sub/dead', $ua['public_key_b64'], WebPush::base64Url(random_bytes(16)));
        $http->nextStatus = 410;
        $outcome = $sender->deliverAll('{"type":"notification"}');
        self::assertSame(2, $outcome['gone']);
        self::assertSame(2, $outcome['failed']);
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM push_subscription WHERE endpoint IN"
            . " ('https://push.example.test/sub/dead', 'https://push.example.test/sub/real')",
        ));

        // The CSRF-protected session API is unaffected by push internals.
        self::assertSame(200, $server->send('GET', '/api/v1/host/push/subscriptions', [
            'cookies' => $login['cookies'],
        ])->status);
    }

    private static function b64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        self::assertIsString($decoded);

        return $decoded;
    }

    /**
     * Receiver side of RFC 8291/8188, mirroring what a browser's push
     * service decrypts. Kept in the test so the production code has no
     * decrypt path that could be misused.
     */
    private static function receiverDecrypt(string $body, string $uaPrivatePem, string $uaPublicPoint, string $authB64): string
    {
        $salt = substr($body, 0, 16);
        $recordSize = unpack('N', substr($body, 16, 4))[1];
        self::assertSame(4096, $recordSize);
        $idLength = ord($body[20]);
        $asPublic = substr($body, 21, $idLength);
        $rest = substr($body, 21 + $idLength);
        $ciphertext = substr($rest, 0, -16);
        $tag = substr($rest, -16);

        $uaKey = openssl_pkey_get_private($uaPrivatePem);
        self::assertNotFalse($uaKey);
        $asKey = openssl_pkey_get_public(self::publicKeyPem($asPublic));
        self::assertNotFalse($asKey);
        $ecdh = openssl_pkey_derive($asKey, $uaKey);
        self::assertIsString($ecdh);

        $auth = base64_decode(strtr($authB64, '-_', '+/') . str_repeat('=', (4 - strlen($authB64) % 4) % 4), true);
        self::assertIsString($auth);

        $prkKey = hash_hmac('sha256', $ecdh, $auth, true);
        $keyInfo = 'WebPush: info' . "\x00" . $uaPublicPoint . $asPublic;
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', 'Content-Encoding: aes128gcm' . "\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', 'Content-Encoding: nonce' . "\x00\x01", $prk, true), 0, 12);

        $padded = openssl_decrypt($ciphertext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        self::assertIsString($padded);

        $unpadded = rtrim($padded, "\x00");
        self::assertSame("\x02", substr($unpadded, -1));

        return substr($unpadded, 0, -1);
    }

    private static function publicKeyPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        self::assertIsString($der);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Inverse of the production DER→raw conversion, for signature verify.
     */
    private static function rawToDer(string $raw): string
    {
        self::assertSame(64, strlen($raw));
        $encode = static function (string $int): string {
            $int = ltrim($int, "\x00");
            if ($int === '') {
                $int = "\x00";
            }
            if (ord($int[0]) > 0x7f) {
                $int = "\x00" . $int;
            }

            return "\x02" . chr(strlen($int)) . $int;
        };
        $body = $encode(substr($raw, 0, 32)) . $encode(substr($raw, 32));

        return "\x30" . chr(strlen($body)) . $body;
    }
}
