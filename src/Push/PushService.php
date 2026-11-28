<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Push;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Community\CommunityException;

use function array_map;
use function base64_encode;
use function bin2hex;
use function filter_var;
use function is_array;
use function is_string;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function parse_url;
use function random_bytes;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtr;
use function strlen;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_IP;
use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const OPENSSL_KEYTYPE_EC;

/**
 * Web Push subscriptions and the instance VAPID identity.
 *
 * A browser hands the server an opaque push endpoint plus two encryption
 * keys; the server stores them per account and later delivers through the
 * endpoint. The endpoint is only ever contacted by the worker over HTTPS —
 * subscribing validates the shape up front so a hostile page cannot park an
 * internal URL here for later SSRF. VAPID keys identify this instance to
 * push services; the private half is sealed at rest like the instance key.
 */
final class PushService
{
    private const MAX_ENDPOINT = 2048;

    private const MAX_KEY = 256;

    private const ID = 'instance';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SecretBox $box,
    ) {
    }

    /**
     * The base64url-encoded uncompressed P-256 point browsers pass to
     * `pushManager.subscribe()`. Generated once and kept stable: rotating it
     * silently breaks every existing subscription.
     */
    public function vapidPublicKey(): string
    {
        $row = $this->db->fetchOne(
            'SELECT public_key FROM push_vapid WHERE id = :id',
            ['id' => self::ID],
        );
        if ($row !== null && is_string($row['public_key']) && $row['public_key'] !== '') {
            return $row['public_key'];
        }

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new \RuntimeException('The VAPID key pair could not be generated.');
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('The VAPID public key could not be derived.');
        }

        $public = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        $publicKey = $this->base64Url($public);
        $privatePem = '';
        if (!openssl_pkey_export($key, $privatePem)) {
            throw new \RuntimeException('The VAPID private key could not be exported.');
        }

        $this->db->execute(
            'INSERT INTO push_vapid (id, public_key, private_pem, created_at)'
            . ' VALUES (:id, :public_key, :private_pem, :created_at)'
            . ' ON CONFLICT (id) DO NOTHING',
            [
                'id' => self::ID,
                'public_key' => $publicKey,
                'private_pem' => $this->box->seal($privatePem),
                'created_at' => Time::format($this->clock->now()),
            ],
        );

        // A concurrent request may have won the insert; always answer with
        // the stored value so every client sees the same key.
        $stored = $this->db->fetchOne(
            'SELECT public_key FROM push_vapid WHERE id = :id',
            ['id' => self::ID],
        );

        return is_string($stored['public_key'] ?? null) ? $stored['public_key'] : $publicKey;
    }

    /**
     * Registers or refreshes a browser subscription. Re-subscribing with the
     * same endpoint moves it to the current account — a shared browser must
     * not keep delivering to the previous sign-in.
     *
     * @return array<string, mixed>
     */
    public function subscribe(string $accountId, mixed $endpoint, mixed $p256dh, mixed $auth): array
    {
        $endpoint = $this->endpoint($endpoint);
        $p256dh = $this->key($p256dh, 'p256dh');
        $auth = $this->key($auth, 'auth');
        $now = Time::format($this->clock->now());
        $id = bin2hex(random_bytes(16));

        $this->db->execute(
            'INSERT INTO push_subscription (id, account_id, endpoint, p256dh, auth, created_at, last_seen_at)'
            . ' VALUES (:id, :account, :endpoint, :p256dh, :auth, :created, :seen)'
            . ' ON CONFLICT (endpoint) DO UPDATE SET'
            . ' account_id = excluded.account_id, p256dh = excluded.p256dh, auth = excluded.auth,'
            . ' last_seen_at = excluded.last_seen_at',
            [
                'id' => $id,
                'account' => $accountId,
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'created' => $now,
                'seen' => $now,
            ],
        );

        $row = $this->db->fetchOne(
            'SELECT * FROM push_subscription WHERE endpoint = :endpoint',
            ['endpoint' => $endpoint],
        );

        return $this->hydrate($row ?? []);
    }

    public function unsubscribe(string $accountId, mixed $endpoint): bool
    {
        $endpoint = $this->endpoint($endpoint);

        return $this->db->execute(
            'DELETE FROM push_subscription WHERE endpoint = :endpoint AND account_id = :account',
            ['endpoint' => $endpoint, 'account' => $accountId],
        ) > 0;
    }

    /**
     * Removes a subscription regardless of owner — used when a push service
     * answers 404/410 so dead endpoints stop receiving work.
     */
    public function removeEndpoint(string $endpoint): bool
    {
        return $this->db->execute(
            'DELETE FROM push_subscription WHERE endpoint = :endpoint',
            ['endpoint' => $endpoint],
        ) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function subscriptionsFor(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM push_subscription WHERE account_id = :account ORDER BY created_at',
            ['account' => $accountId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM push_subscription ORDER BY created_at');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'endpoint' => (string) ($row['endpoint'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
        ];
    }

    private function endpoint(mixed $value): string
    {
        $endpoint = is_string($value) ? trim($value) : '';
        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT) {
            throw CommunityException::invalid(
                'This push endpoint is invalid.',
                ['endpoint' => 'Push endpoints are at most ' . self::MAX_ENDPOINT . ' characters.'],
            );
        }

        $parts = parse_url($endpoint);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
        if ($scheme !== 'https' || $host === ''
            || (isset($parts['user']) || isset($parts['pass']))
        ) {
            throw CommunityException::invalid(
                'Push endpoints must be plain HTTPS URLs.',
                ['endpoint' => 'Use the https:// URL the browser reported.'],
            );
        }

        // The worker will POST to this URL later, so refuse anything that
        // obviously points back inside the network: IP literals in private
        // or reserved ranges, and loopback names.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw CommunityException::invalid(
                'This push endpoint is not allowed.',
                ['endpoint' => 'Push services must be publicly reachable.'],
            );
        }
        if (str_contains(strtolower($host), 'localhost') || $host === '::1' || str_starts_with($host, '127.')) {
            throw CommunityException::invalid(
                'This push endpoint is not allowed.',
                ['endpoint' => 'Push services must be publicly reachable.'],
            );
        }

        return $endpoint;
    }

    private function key(mixed $value, string $field): string
    {
        $key = is_string($value) ? trim($value) : '';
        if ($key === '' || strlen($key) > self::MAX_KEY) {
            throw CommunityException::invalid(
                'This subscription is incomplete.',
                [$field => 'The browser did not provide this key.'],
            );
        }

        return $key;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
