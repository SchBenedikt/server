<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Cross-origin policy for the bearer-token client API (#176).
 *
 * Browser clients call the Mastodon compatibility surface, app registration
 * and the token endpoints from another origin. Those routes answer with a
 * wildcard origin and a 204 preflight; the session-authenticated first-party
 * API stays same-origin only.
 */
final class ClientApiCorsTest extends TestCase
{
    private const PREFIX = '/api/mastodon/v1';

    private TestServer $server;

    protected function setUp(): void
    {
        $this->server = new TestServer(['mastodon_api' => true]);
    }

    public function testPreflightAnswersWithoutAuthentication(): void
    {
        foreach ([self::PREFIX . '/timelines/home', '/api/v1/apps', '/oauth/token', '/oauth/revoke'] as $path) {
            $response = $this->server->send('OPTIONS', $path);
            self::assertSame(204, $response->status, $path);
            self::assertSame('*', $response->header('Access-Control-Allow-Origin'), $path);
            self::assertSame(
                'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                $response->header('Access-Control-Allow-Methods'),
                $path,
            );
            self::assertStringContainsString('Authorization', (string) $response->header('Access-Control-Allow-Headers'));
            self::assertStringContainsString('Idempotency-Key', (string) $response->header('Access-Control-Allow-Headers'));
            self::assertNull($response->header('Access-Control-Allow-Credentials'), $path);
        }
    }

    public function testScopedResponsesCarryCorsHeadersIncludingErrors(): void
    {
        // A missing bearer token still answers with the CORS headers so a
        // browser client can read why it was refused.
        $denied = $this->server->send('GET', self::PREFIX . '/timelines/home');
        self::assertContains($denied->status, [401, 403]);
        self::assertSame('*', $denied->header('Access-Control-Allow-Origin'));

        $public = $this->server->send('GET', self::PREFIX . '/instance');
        self::assertSame(200, $public->status, $public->body);
        self::assertSame('*', $public->header('Access-Control-Allow-Origin'));
        self::assertStringContainsString(
            'X-RateLimit-Limit',
            (string) $public->header('Access-Control-Expose-Headers'),
        );
    }

    public function testFirstPartyApiStaysSameOrigin(): void
    {
        $this->server->createAccount('alice');
        $alice = $this->server->signIn('alice');

        $response = $this->server->send('GET', '/api/v1/host/preferences', [
            'cookies' => $alice['cookies'],
        ]);
        self::assertSame(200, $response->status);
        self::assertNull($response->header('Access-Control-Allow-Origin'));

        // An unscoped OPTIONS is not a preflight answer.
        $preflight = $this->server->send('OPTIONS', '/api/v1/host/preferences');
        self::assertNotSame(204, $preflight->status);
        self::assertNull($preflight->header('Access-Control-Allow-Origin'));
    }

    public function testConfiguredPrefixIsRespected(): void
    {
        $custom = new TestServer([
            'mastodon_api' => true,
            'mastodon_prefix' => '/masto',
        ]);

        $response = $custom->send('OPTIONS', '/masto/timelines/home');
        self::assertSame(204, $response->status);
        self::assertSame('*', $response->header('Access-Control-Allow-Origin'));

        $outside = $custom->send('OPTIONS', self::PREFIX . '/timelines/home');
        self::assertNotSame(204, $outside->status);
        self::assertNull($outside->header('Access-Control-Allow-Origin'));
    }
}
