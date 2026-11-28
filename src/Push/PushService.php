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
use function array_values;
use function base64_decode;
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
use function str_pad;
use function str_repeat;
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
     *
     * First installs and repairs race on this single row, so writes are
     * insert-if-absent or compare-and-swap against the observed row — the
     * first persisted valid identity wins and every caller re-reads it.
     * A caller never returns a key it failed to persist.
     */
    public function vapidPublicKey(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $this->db->fetchOne(
                'SELECT public_key, private_pem FROM push_vapid WHERE id = :id',
                ['id' => self::ID],
            );

            $stored = $row['public_key'] ?? null;
            if (is_string($stored) && self::validVapidKey($stored)) {
                return $stored;
            }

            if ($row === null) {
                $this->installKeyPair();
            } else {
                $this->repairKeyPair((string) ($row['private_pem'] ?? ''));
            }

            $winner = $this->db->fetchOne(
                'SELECT public_key FROM push_vapid WHERE id = :id',
                ['id' => self::ID],
            );
            $winnerKey = $winner['public_key'] ?? null;
            if (is_string($winnerKey) && self::validVapidKey($winnerKey)) {
                return $winnerKey;
            }
        }

        throw new \RuntimeException('The VAPID public key could not be stabilized.');
    }

    /**
     * The VAPID identity for signing: public key plus the unsealed private
     * PEM. Only the sender needs this — never expose it over HTTP.
     *
     * @return array{public_key: string, private_pem: string}
     */
    public function vapidKeyPair(): array
    {
        $this->vapidPublicKey();
        $row = $this->db->fetchOne(
            'SELECT public_key, private_pem FROM push_vapid WHERE id = :id',
            ['id' => self::ID],
        );
        if ($row === null) {
            throw new \RuntimeException('The VAPID key pair is missing.');
        }

        return [
            'public_key' => (string) $row['public_key'],
            'private_pem' => $this->box->open((string) $row['private_pem']),
        ];
    }

    /**
     * First install: insert-if-absent. A concurrent initializer wins the
     * row; the loser's freshly generated pair is discarded and the caller
     * re-reads the winner's key.
     */
    private function installKeyPair(): void
    {
        [$publicKey, $sealedPem] = $this->generateKeyPair();

        $this->db->execute(
            'INSERT INTO push_vapid (id, public_key, private_pem, created_at)'
            . ' VALUES (:id, :public_key, :private_pem, :created_at)'
            . ' ON CONFLICT (id) DO NOTHING',
            [
                'id' => self::ID,
                'public_key' => $publicKey,
                'private_pem' => $sealedPem,
                'created_at' => Time::format($this->clock->now()),
            ],
        );
    }

    /**
     * Repair of a malformed row, compare-and-swapped on the observed
     * private half so a concurrent repair or rotation is never overwritten.
     *
     * The private PEM is authoritative — the VAPID JWT is signed with it —
     * so a recoverable key yields the correct public point and the identity
     * (and its subscriptions) survives. Only an unreadable private half
     * forces rotation.
     */
    private function repairKeyPair(string $observedPem): void
    {
        $repaired = $this->publicKeyFromPrivatePem($observedPem);
        if ($repaired !== null) {
            $this->db->execute(
                'UPDATE push_vapid SET public_key = :public_key'
                . ' WHERE id = :id AND private_pem = :observed',
                [
                    'public_key' => $repaired,
                    'id' => self::ID,
                    'observed' => $observedPem,
                ],
            );

            return;
        }

        [$publicKey, $sealedPem] = $this->generateKeyPair();
        $this->db->execute(
            'UPDATE push_vapid SET public_key = :public_key, private_pem = :private_pem'
            . ' WHERE id = :id AND private_pem = :observed',
            [
                'public_key' => $publicKey,
                'private_pem' => $sealedPem,
                'id' => self::ID,
                'observed' => $observedPem,
            ],
        );
    }

    /**
     * @return array{0: string, 1: string} base64url public point, sealed PEM
     */
    private function generateKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new \RuntimeException('The VAPID key pair could not be generated.');
        }

        $details = openssl_pkey_get_details($key);
        $public = $this->publicPoint(is_array($details) ? $details : []);
        if ($public === null) {
            throw new \RuntimeException('The VAPID public key could not be derived.');
        }

        $privatePem = '';
        if (!openssl_pkey_export($key, $privatePem)) {
            throw new \RuntimeException('The VAPID private key could not be exported.');
        }

        $publicKey = $this->base64Url($public);
        if (!self::validVapidKey($publicKey)) {
            throw new \RuntimeException('The VAPID public key could not be derived.');
        }

        return [$publicKey, $this->box->seal($privatePem)];
    }

    /**
     * The public point a sealed private PEM implies, or null when the PEM
     * cannot be opened or is not an EC key.
     */
    private function publicKeyFromPrivatePem(string $sealedPem): ?string
    {
        try {
            $pem = $this->box->open($sealedPem);
        } catch (\Throwable) {
            return null;
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        $public = $this->publicPoint(is_array($details) ? $details : []);

        if ($public === null) {
            return null;
        }

        $encoded = $this->base64Url($public);

        return self::validVapidKey($encoded) ? $encoded : null;
    }

    /**
     * OpenSSL reports the coordinates as minimal-length integers, so a
     * leading zero byte must be restored or the point is not 65 bytes.
     *
     * @param array<string, mixed> $details openssl_pkey_get_details() result
     */
    private function publicPoint(array $details): ?string
    {
        $ec = $details['ec'] ?? null;
        if (!is_array($ec) || !isset($ec['x'], $ec['y'])
            || !is_string($ec['x']) || !is_string($ec['y'])
        ) {
            return null;
        }

        return "\x04"
            . str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);
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
     * Subscriptions with their encryption keys, for the delivery worker.
     * Never serialize this shape into an API response.
     *
     * @return list<array<string, mixed>>
     */
    public function deliverableSubscriptions(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, account_id, endpoint, p256dh, auth FROM push_subscription ORDER BY created_at',
        );

        return array_values(array_filter(array_map(static function (array $row): ?array {
            $endpoint = $row['endpoint'] ?? null;
            $p256dh = $row['p256dh'] ?? null;
            $auth = $row['auth'] ?? null;
            if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)) {
                return null;
            }

            return [
                'id' => (string) $row['id'],
                'account_id' => (string) $row['account_id'],
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
            ];
        }, $rows)));
    }

    /**
     * Deliverable subscriptions for one account — the worker resolves the
     * recipient's endpoints at send time so revoked rows never fire.
     *
     * @return list<array<string, mixed>>
     */
    public function deliverableForAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, account_id, endpoint, p256dh, auth FROM push_subscription'
            . ' WHERE account_id = :account ORDER BY created_at',
            ['account' => $accountId],
        );

        return array_values(array_filter(array_map(static function (array $row): ?array {
            $endpoint = $row['endpoint'] ?? null;
            $p256dh = $row['p256dh'] ?? null;
            $auth = $row['auth'] ?? null;
            if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)) {
                return null;
            }

            return [
                'id' => (string) $row['id'],
                'account_id' => (string) $row['account_id'],
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
            ];
        }, $rows)));
    }

    /**
     * Queue one push payload for an account. Called by the notification
     * sink; delivery happens on the worker so request latency never depends
     * on a push service.
     */
    public function enqueueDelivery(string $accountId, string $payload): void
    {
        $now = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO push_delivery (id, account_id, payload, attempts, next_attempt_at, created_at)'
            . ' VALUES (:id, :account, :payload, 0, :next, :created)',
            [
                'id' => bin2hex(random_bytes(16)),
                'account' => $accountId,
                'payload' => $payload,
                'next' => $now,
                'created' => $now,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dueDeliveries(int $limit): array
    {
        $now = Time::format($this->clock->now());
        $rows = $this->db->fetchAll(
            'SELECT id, account_id, payload, attempts FROM push_delivery'
            . ' WHERE next_attempt_at <= :now ORDER BY next_attempt_at LIMIT :limit',
            ['now' => $now, 'limit' => $limit],
        );

        return array_values(array_filter(array_map(static function (array $row): ?array {
            $id = $row['id'] ?? null;
            $account = $row['account_id'] ?? null;
            $payload = $row['payload'] ?? null;
            if (!is_string($id) || !is_string($account) || !is_string($payload)) {
                return null;
            }

            return [
                'id' => $id,
                'account_id' => $account,
                'payload' => $payload,
                'attempts' => (int) $row['attempts'],
            ];
        }, $rows)));
    }

    public function completeDelivery(string $id): void
    {
        $this->db->execute('DELETE FROM push_delivery WHERE id = :id', ['id' => $id]);
    }

    /**
     * Failed sends back off exponentially and are dropped after five tries —
     * a permanently broken endpoint must not pin the queue.
     */
    public function retryDelivery(string $id, int $attempts): void
    {
        if ($attempts >= 5) {
            $this->db->execute('DELETE FROM push_delivery WHERE id = :id', ['id' => $id]);

            return;
        }

        $delay = min(3600, 60 * (1 << $attempts));
        $next = Time::format($this->clock->now()->modify('+' . $delay . ' seconds'));
        $this->db->execute(
            'UPDATE push_delivery SET attempts = :attempts, next_attempt_at = :next WHERE id = :id',
            ['attempts' => $attempts + 1, 'next' => $next, 'id' => $id],
        );
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

    /**
     * A stored key written before coordinates were padded can decode to 64
     * bytes; such a key is not a usable P-256 point and must be replaced.
     */
    private static function validVapidKey(string $key): bool
    {
        $decoded = base64_decode(
            strtr($key, '-_', '+/') . str_repeat('=', (4 - strlen($key) % 4) % 4),
            true,
        );

        return is_string($decoded) && strlen($decoded) === 65 && $decoded[0] === "\x04";
    }
}
