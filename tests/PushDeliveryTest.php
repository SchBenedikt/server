<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Server\Push\NotificationPushSink;
use AlohaSocial\Server\Push\PushSender;
use AlohaSocial\Server\Push\PushService;
use AlohaSocial\Server\Push\WebPush;
use AlohaSocial\Server\Tests\Support\TestServer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function random_bytes;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_KEYTYPE_EC;

/**
 * The notification sink only queues; the worker drains push_delivery and the
 * sender resolves the recipient's subscriptions at send time. These tests pin
 * the queue lifecycle (due/retry/drop) and per-account fan-out.
 */
final class PushDeliveryTest extends TestCase
{
    public function testSinkEnqueuesAGenericPayloadForTheRecipient(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);

        $push = $server->runtime->pushSubscriptions;
        $sink = new NotificationPushSink($push, 'Test Instance');
        $sink->emit(new Notification(
            'n1',
            'alice',
            'actor-1',
            NotificationType::Follow,
            null,
            $server->runtime->clock->now(),
        ));

        $due = $push->dueDeliveries(10);
        self::assertCount(1, $due);
        self::assertSame('alice', $due[0]['account_id']);

        $payload = json_decode((string) $due[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Test Instance', $payload['title']);
        self::assertSame('#/notifications', $payload['url']);
        // The payload must never carry notification content.
        self::assertStringNotContainsString('actor-1', (string) $due[0]['payload']);
    }

    public function testQueueBackoffAndDropAfterFiveAttempts(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);

        // Rebuild the service on a frozen clock so backoff is deterministic;
        // the SecretBox only seals the VAPID key, unused by the queue methods.
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-28 12:00:00', new DateTimeZone('UTC')));
        $push = new PushService(
            $server->runtime->database,
            $clock,
            OpenSslSecretBox::fromBase64Key(OpenSslSecretBox::generateKey()),
        );
        $push->enqueueDelivery('alice', '{"title":"x"}');

        $due = $push->dueDeliveries(10);
        self::assertCount(1, $due);

        // First retry schedules +120s out, so nothing is due yet.
        $push->retryDelivery((string) $due[0]['id'], 0);
        self::assertCount(0, $push->dueDeliveries(10));

        // Five attempts drop the row instead of pinning the queue.
        $clock->advance(3600);
        for ($i = 1; $i <= 5; ++$i) {
            $rows = $push->dueDeliveries(10);
            if ($rows === []) {
                break;
            }
            $push->retryDelivery((string) $rows[0]['id'], (int) $rows[0]['attempts']);
            $clock->advance(3700);
        }
        self::assertCount(0, $push->dueDeliveries(10));
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM push_delivery WHERE account_id = :account',
            ['account' => 'alice'],
        ));
    }

    public function testDeliverForAccountOnlyFansOutToThatAccount(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $server->createAccount('bob', 'bob@example.test', TestServer::PASSWORD, true);

        $push = $server->runtime->pushSubscriptions;
        $ua = self::uaKeyPair();
        $push->subscribe('alice', 'https://push.example.test/alice', $ua['public_key_b64'], WebPush::base64Url(random_bytes(16)));
        $push->subscribe('bob', 'https://push.example.test/bob', $ua['public_key_b64'], WebPush::base64Url(random_bytes(16)));

        $http = new class implements BoundedHttpClient {
            /** @var list<string> */
            public array $urls = [];

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
                $this->urls[] = $url;

                return new HttpResponse(201);
            }
        };

        $sender = new PushSender(
            $push,
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

        $outcome = $sender->deliverForAccount('alice', '{"title":"x"}');
        self::assertSame(['sent' => 1, 'gone' => 0, 'failed' => 0], $outcome);
        self::assertSame(['https://push.example.test/alice'], $http->urls);
    }

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
}
