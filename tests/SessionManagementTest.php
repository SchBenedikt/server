<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class SessionManagementTest extends TestCase
{
    private function sessions(TestServer $server, array $cookies): array
    {
        $response = $server->send('GET', '/api/v1/host/sessions', ['cookies' => $cookies]);
        self::assertSame(200, $response->status, $response->body);

        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['sessions'];
    }

    private function csrfFor(TestServer $server, array $cookies): string
    {
        $home = $server->send('GET', '/', ['cookies' => $cookies]);
        $csrf = $server->csrfFrom($home->body);
        self::assertNotNull($csrf);

        return $csrf;
    }

    public function testListsSessionsWithCurrentFlag(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $first = $server->signIn('alice');
        $server->login('alice');

        $sessions = $this->sessions($server, $first['cookies']);

        self::assertCount(2, $sessions);
        self::assertSame(1, count(array_filter($sessions, static fn ($s) => $s['current'] === true)));
        self::assertNotEmpty($sessions[0]['user_agent']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sessions[0]['id']);
    }

    public function testRevokesASingleSession(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $first = $server->signIn('alice');
        $second = $server->login('alice');

        $sessions = $this->sessions($server, $first['cookies']);
        $other = array_values(array_filter($sessions, static fn ($s) => $s['current'] !== true))[0];

        $response = $server->send('DELETE', '/api/v1/host/sessions/' . $other['id'], [
            'cookies' => $first['cookies'],
            'headers' => ['X-CSRF-Token' => $first['csrf']],
        ]);
        self::assertSame(200, $response->status, $response->body);

        self::assertCount(1, $this->sessions($server, $first['cookies']));
        // The revoked cookie no longer authenticates.
        $denied = $server->send('GET', '/api/v1/host/sessions', ['cookies' => $second['cookies']]);
        self::assertSame(401, $denied->status);
    }

    public function testCannotRevokeAnotherAccountsSession(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $server->createAccount('bob');
        $alice = $server->signIn('alice');
        $bob = $server->signIn('bob');

        $aliceSessions = $this->sessions($server, $alice['cookies']);
        $victim = $aliceSessions[0]['id'];

        $response = $server->send('DELETE', '/api/v1/host/sessions/' . $victim, [
            'cookies' => $bob['cookies'],
            'headers' => ['X-CSRF-Token' => $bob['csrf']],
        ]);
        self::assertSame(404, $response->status, $response->body);

        // Alice's session survived.
        self::assertCount(1, $this->sessions($server, $alice['cookies']));
    }

    public function testRevokeOthersKeepsOnlyTheCurrentSession(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $first = $server->signIn('alice');
        $second = $server->login('alice');
        $third = $server->login('alice');

        $response = $server->json('POST', '/api/v1/host/sessions/revoke-others', [], $first['cookies'], $first['csrf']);
        self::assertSame(200, $response->status, $response->body);
        self::assertSame(2, json_decode($response->body, true)['revoked']);

        $sessions = $this->sessions($server, $first['cookies']);
        self::assertCount(1, $sessions);
        self::assertTrue($sessions[0]['current']);

        foreach ([$second['cookies'], $third['cookies']] as $cookies) {
            $denied = $server->send('GET', '/api/v1/host/sessions', ['cookies' => $cookies]);
            self::assertSame(401, $denied->status);
        }
    }

    public function testRequiresSignInAndCsrf(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $auth = $server->signIn('alice');

        $anonymous = $server->send('GET', '/api/v1/host/sessions');
        self::assertSame(401, $anonymous->status);

        // Mutating endpoints sit behind the global session CSRF check.
        $noCsrf = $server->send('POST', '/api/v1/host/sessions/revoke-others', ['cookies' => $auth['cookies']]);
        self::assertSame(403, $noCsrf->status);
    }
}
