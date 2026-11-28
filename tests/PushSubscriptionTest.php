<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Push\WebPush;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Web Push subscription storage: VAPID identity, endpoint validation and
 * per-account lifecycle. Delivery itself is a separate worker concern.
 */
final class PushSubscriptionTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies;

    private string $aliceCsrf;

    /** @var array<string, string> */
    private array $bobCookies;

    private string $bobCsrf;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $this->server->createAccount('bob', 'bob@example.test');

        $alice = $this->server->signIn('alice');
        $this->aliceCookies = $alice['cookies'];
        $this->aliceCsrf = $alice['csrf'];

        $bob = $this->server->signIn('bob');
        $this->bobCookies = $bob['cookies'];
        $this->bobCsrf = $bob['csrf'];
    }

    public function testEndpointsRequireAuthentication(): void
    {
        self::assertSame(401, $this->server->send('GET', '/api/v1/host/push/vapid-key')->status);
        self::assertSame(401, $this->server->send('GET', '/api/v1/host/push/subscriptions')->status);
        self::assertSame(
            401,
            $this->server->json('POST', '/api/v1/host/push/subscriptions', [
                'endpoint' => 'https://push.example.test/sub/1',
                'keys' => ['p256dh' => 'k', 'auth' => 'a'],
            ])->status,
        );
    }

    public function testVapidKeyIsStableAndWellFormed(): void
    {
        $first = $this->server->json('GET', '/api/v1/host/push/vapid-key', [], $this->aliceCookies);
        self::assertSame(200, $first->status);
        $key = (string) json_decode($first->body, true)['key'];

        // 65-byte uncompressed P-256 point, base64url without padding.
        $decoded = base64_decode(strtr($key, '-_', '+/') . str_repeat('=', (4 - strlen($key) % 4) % 4), true);
        self::assertSame(65, strlen((string) $decoded));
        self::assertSame("\x04", $decoded[0]);

        $second = $this->server->json('GET', '/api/v1/host/push/vapid-key', [], $this->bobCookies);
        self::assertSame($key, (string) json_decode($second->body, true)['key']);
    }

    public function testMalformedStoredVapidKeyIsReplaced(): void
    {
        // Keys written before coordinates were padded to 32 bytes decode to
        // 64 bytes and are not usable P-256 points.
        $shortPoint = "\x04" . random_bytes(63);
        $this->server->runtime->database->execute(
            'INSERT INTO push_vapid (id, public_key, private_pem, created_at)'
            . ' VALUES (:id, :public_key, :private_pem, :created_at)',
            [
                'id' => 'instance',
                'public_key' => WebPush::base64Url($shortPoint),
                'private_pem' => 'sealed',
                'created_at' => '2026-01-01T00:00:00+00:00',
            ],
        );

        $key = $this->server->runtime->pushSubscriptions->vapidPublicKey();
        $decoded = base64_decode(strtr($key, '-_', '+/') . str_repeat('=', (4 - strlen($key) % 4) % 4), true);
        self::assertSame(65, strlen((string) $decoded));
        self::assertSame("\x04", $decoded[0]);

        $stored = $this->server->runtime->database->fetchValue(
            'SELECT public_key FROM push_vapid WHERE id = :id',
            ['id' => 'instance'],
        );
        self::assertSame($key, $stored);
    }

    public function testSubscribeListAndUnsubscribe(): void
    {
        $created = $this->server->json('POST', '/api/v1/host/push/subscriptions', [
            'endpoint' => 'https://push.example.test/sub/abc',
            'keys' => ['p256dh' => 'BP256dh-key-material', 'auth' => 'auth-secret'],
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(201, $created->status, $created->body);
        $subscription = json_decode($created->body, true)['subscription'];
        self::assertSame('https://push.example.test/sub/abc', $subscription['endpoint']);
        self::assertArrayNotHasKey('p256dh', $subscription);
        self::assertArrayNotHasKey('auth', $subscription);

        $list = $this->server->json('GET', '/api/v1/host/push/subscriptions', [], $this->aliceCookies);
        self::assertCount(1, json_decode($list->body, true)['subscriptions']);

        // Bob sees nothing of Alice's subscription.
        $bobList = $this->server->json('GET', '/api/v1/host/push/subscriptions', [], $this->bobCookies);
        self::assertSame([], json_decode($bobList->body, true)['subscriptions']);

        // Bob cannot delete Alice's endpoint.
        $foreign = $this->server->json('DELETE', '/api/v1/host/push/subscriptions', [
            'endpoint' => 'https://push.example.test/sub/abc',
        ], $this->bobCookies, $this->bobCsrf);
        self::assertFalse(json_decode($foreign->body, true)['removed']);

        $removed = $this->server->json('DELETE', '/api/v1/host/push/subscriptions', [
            'endpoint' => 'https://push.example.test/sub/abc',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertTrue(json_decode($removed->body, true)['removed']);
        self::assertSame([], json_decode(
            $this->server->json('GET', '/api/v1/host/push/subscriptions', [], $this->aliceCookies)->body,
            true,
        )['subscriptions']);
    }

    public function testResubscribeMovesEndpointToCurrentAccount(): void
    {
        $payload = [
            'endpoint' => 'https://push.example.test/sub/shared',
            'keys' => ['p256dh' => 'key-one', 'auth' => 'auth-one'],
        ];
        $this->server->json('POST', '/api/v1/host/push/subscriptions', $payload, $this->aliceCookies, $this->aliceCsrf);
        $this->server->json('POST', '/api/v1/host/push/subscriptions', [
            'endpoint' => 'https://push.example.test/sub/shared',
            'keys' => ['p256dh' => 'key-two', 'auth' => 'auth-two'],
        ], $this->bobCookies, $this->bobCsrf);

        $alice = json_decode(
            $this->server->json('GET', '/api/v1/host/push/subscriptions', [], $this->aliceCookies)->body,
            true,
        )['subscriptions'];
        $bob = json_decode(
            $this->server->json('GET', '/api/v1/host/push/subscriptions', [], $this->bobCookies)->body,
            true,
        )['subscriptions'];
        self::assertCount(0, $alice);
        self::assertCount(1, $bob);
    }

    public function testEndpointValidationRejectsUnsafeUrls(): void
    {
        foreach ([
            'http://push.example.test/sub/1',
            'https://127.0.0.1/sub/1',
            'https://10.0.0.5/sub/1',
            'https://localhost/sub/1',
            'https://user:pass@push.example.test/sub/1',
            'not-a-url',
        ] as $endpoint) {
            $response = $this->server->json('POST', '/api/v1/host/push/subscriptions', [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => 'k', 'auth' => 'a'],
            ], $this->aliceCookies, $this->aliceCsrf);
            self::assertSame(422, $response->status, $endpoint . ' → ' . $response->status);
        }

        $missingKeys = $this->server->json('POST', '/api/v1/host/push/subscriptions', [
            'endpoint' => 'https://push.example.test/sub/1',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $missingKeys->status);
    }

    public function testErasureRemovesSubscriptionsButKeepsVapid(): void
    {
        $this->server->json('GET', '/api/v1/host/push/vapid-key', [], $this->aliceCookies);
        $this->server->json('POST', '/api/v1/host/push/subscriptions', [
            'endpoint' => 'https://push.example.test/sub/gc',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ], $this->aliceCookies, $this->aliceCsrf);

        $this->server->runtime->accountErasureCleanup->erase('alice');

        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM push_subscription WHERE account_id = :account',
            ['account' => 'alice'],
        ));
        self::assertSame(1, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM push_vapid',
        ));
    }
}
