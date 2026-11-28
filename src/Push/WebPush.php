<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Push;

use AlohaSocial\Server\Community\CommunityException;

use function base64_decode;
use function base64_encode;
use function chunk_split;
use function hash_hmac;
use function hex2bin;
use function is_array;
use function is_string;
use function json_encode;
use function openssl_encrypt;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_pkey_new;
use function openssl_pkey_derive;
use function openssl_sign;
use function ord;
use function pack;
use function random_bytes;
use function str_pad;
use function str_repeat;
use function strlen;
use function strtr;
use function substr;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const OPENSSL_RAW_DATA;
use const STR_PAD_LEFT;

/**
 * Web Push wire format: the VAPID JWT (RFC 8292) and the aes128gcm payload
 * encryption (RFC 8291 message encryption + RFC 8188 content coding).
 *
 * Everything here is pure cryptography over bytes the caller supplies; no
 * I/O, so the vectors can be tested without a push service.
 */
final class WebPush
{
    private const INFO_PREFIX = 'WebPush: info';

    private const CEK_INFO = 'Content-Encoding: aes128gcm';

    private const NONCE_INFO = 'Content-Encoding: nonce';

    /**
     * ES256 JWT identifying this instance to the push service.
     *
     * @return string compact JWS
     */
    public static function vapidJwt(string $audience, string $subject, string $privatePem, int $issuedAt): string
    {
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false) {
            throw new \RuntimeException('The VAPID private key could not be loaded.');
        }

        $header = self::base64Url('{"typ":"JWT","alg":"ES256"}');
        $payload = self::base64Url((string) json_encode([
            'aud' => $audience,
            'exp' => $issuedAt + 12 * 3600,
            'sub' => $subject,
        ], JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $payload;

        $der = '';
        if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The VAPID assertion could not be signed.');
        }

        return $signingInput . '.' . self::base64Url(self::derToRawSignature($der));
    }

    /**
     * Encrypts a payload for one subscription per RFC 8291/8188.
     *
     * @return array{body: string, content_encoding: string}
     */
    public static function encrypt(string $p256dh, string $authSecret, string $plaintext): array
    {
        $uaPublic = self::base64UrlDecode($p256dh);
        $auth = self::base64UrlDecode($authSecret);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || $auth === '') {
            throw CommunityException::invalid('This subscription cannot receive encrypted pushes.');
        }

        $sender = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($sender === false) {
            throw new \RuntimeException('An ephemeral push key could not be generated.');
        }
        $details = openssl_pkey_get_details($sender);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('The ephemeral push public key could not be derived.');
        }
        // Coordinates arrive as minimal-length integers; pad to the fixed
        // field size so the point and the HKDF info stay well-formed.
        $asPublic = "\x04"
            . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $uaKey = openssl_pkey_get_public(self::publicKeyPem($uaPublic));
        if ($uaKey === false) {
            throw CommunityException::invalid('This subscription key is not a valid P-256 point.');
        }

        $ecdh = openssl_pkey_derive($uaKey, $sender);
        if (!is_string($ecdh) || strlen($ecdh) !== 32) {
            throw new \RuntimeException('The push key agreement failed.');
        }

        // RFC 8291 §3.3: IKM from the ECDH secret and the auth secret.
        $prkKey = hash_hmac('sha256', $ecdh, $auth, true);
        $keyInfo = self::INFO_PREFIX . "\x00" . $uaPublic . $asPublic;
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);

        // RFC 8188 §2.3: CEK and nonce from a fresh salt.
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', self::CEK_INFO . "\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', self::NONCE_INFO . "\x00\x01", $prk, true), 0, 12);

        // RFC 8188 §2.1 padding: delimiter 0x02 then zeros to a 16-octet edge.
        $pad = (16 - ((strlen($plaintext) + 1) % 16)) % 16;
        $padded = $plaintext . "\x02" . str_repeat("\x00", $pad);

        $tag = '';
        $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (!is_string($ciphertext)) {
            throw new \RuntimeException('The push payload could not be encrypted.');
        }

        return [
            'body' => $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $ciphertext . $tag,
            'content_encoding' => 'aes128gcm',
        ];
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * DER-encoded ECDSA signatures are what OpenSSL produces; JWS wants the
     * raw R||S pair.
     */
    private static function derToRawSignature(string $der): string
    {
        $offset = 0;
        $readLength = static function () use ($der, &$offset): int {
            $first = ord($der[$offset++]);
            if (($first & 0x80) === 0) {
                return $first;
            }
            $bytes = $first & 0x7f;
            $length = 0;
            for ($i = 0; $i < $bytes; ++$i) {
                $length = ($length << 8) | ord($der[$offset++]);
            }

            return $length;
        };

        // SEQUENCE
        if (ord($der[$offset++]) !== 0x30) {
            throw new \RuntimeException('The VAPID signature is malformed.');
        }
        $readLength();
        $parts = [];
        for ($i = 0; $i < 2; ++$i) {
            if (ord($der[$offset++]) !== 0x02) {
                throw new \RuntimeException('The VAPID signature is malformed.');
            }
            $length = $readLength();
            $integer = substr($der, $offset, $length);
            $offset += $length;
            // Strip the sign-padding byte, then left-pad to 32 octets.
            while (strlen($integer) > 1 && $integer[0] === "\x00") {
                $integer = substr($integer, 1);
            }
            $parts[] = str_pad($integer, 32, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    /**
     * SPKI DER for a P-256 public key is a fixed prefix plus the raw point.
     */
    private static function publicKeyPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        if (!is_string($der)) {
            throw new \RuntimeException('The push public key could not be encoded.');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
