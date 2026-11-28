<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\FakeBoundedHttpClient;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The admin preflight splits local readiness from externally verified
 * reachability, so a loopback-only install is never reported as federated.
 */
final class FederationPreflightTest extends TestCase
{
    private function checks(array $body, string $id): array
    {
        foreach ($body['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
        self::fail('Missing check ' . $id);
    }

    public function testLocalChecksRunWithoutExternalFetching(): void
    {
        $http = new FakeBoundedHttpClient();
        $server = new TestServer([], $http);
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $session = $server->signIn('admin');
        $server->runtime->admin->heartbeat();

        $response = $server->send('GET', '/api/v1/host/admin/preflight', [
            'query' => ['external' => '0'],
            'cookies' => $session['cookies'],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ok', $this->checks($body, 'instance_url')['status']);
        self::assertSame('ok', $this->checks($body, 'database')['status']);
        self::assertSame('ok', $this->checks($body, 'schema')['status']);
        self::assertSame('ok', $this->checks($body, 'worker')['status']);
        self::assertSame('warn', $this->checks($body, 'mail')['status']);
        self::assertSame('skip', $this->checks($body, 'origin')['status']);
        self::assertSame('skip', $this->checks($body, 'nodeinfo')['status']);
        self::assertSame('skip', $this->checks($body, 'webfinger')['status']);
        self::assertSame([], $http->requests, 'external=0 must not touch the network');
    }

    public function testPreflightRequiresAnAdminSession(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $alice = $server->signIn('alice');

        self::assertSame(401, $server->send('GET', '/api/v1/host/admin/preflight')->status);
        self::assertSame(403, $server->send('GET', '/api/v1/host/admin/preflight', [
            'cookies' => $alice['cookies'],
        ])->status);
    }

    public function testExternalChecksVerifyTheConfiguredOrigin(): void
    {
        $http = new FakeBoundedHttpClient();
        $server = new TestServer([], $http);
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $session = $server->signIn('admin');

        $base = 'http://localhost:3000';
        $http->html($base . '/', '<html>ok</html>', 200);
        $http->json($base . '/.well-known/nodeinfo', [
            'links' => [
                ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => $base . '/nodeinfo/2.1'],
            ],
        ]);
        $http->json($base . '/nodeinfo/2.1', [
            'software' => ['name' => 'Aloha Social', 'version' => '1.0.0'],
        ]);
        $http->json(
            $base . '/.well-known/webfinger?resource=acct%3Aadmin%40localhost',
            ['subject' => 'acct:admin@localhost'],
        );

        $response = $server->send('GET', '/api/v1/host/admin/preflight', [
            'cookies' => $session['cookies'],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        // The test origin is loopback, so reachability is reported but never
        // claimed as public federation readiness.
        self::assertSame('warn', $this->checks($body, 'origin')['status']);
        self::assertSame('ok', $this->checks($body, 'nodeinfo')['status']);
        self::assertSame('Aloha Social', $this->checks($body, 'nodeinfo')['detail']);
        self::assertSame('ok', $this->checks($body, 'webfinger')['status']);
        self::assertContains($base . '/.well-known/nodeinfo', $http->requests);
        self::assertContains(
            $base . '/.well-known/webfinger?resource=acct%3Aadmin%40localhost',
            $http->requests,
        );
    }

    public function testUnreachableOriginFailsExternally(): void
    {
        $server = new TestServer([], new FakeBoundedHttpClient());
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $session = $server->signIn('admin');

        // The fake client answers 404 for everything: the origin still
        // "answers", but NodeInfo is missing and WebFinger 404s a real
        // account — both external failures an operator must see.
        $response = $server->send('GET', '/api/v1/host/admin/preflight', [
            'cookies' => $session['cookies'],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('fail', $this->checks($body, 'nodeinfo')['status']);
        self::assertSame('fail', $this->checks($body, 'webfinger')['status']);
        self::assertGreaterThanOrEqual(2, $body['summary']['fail']);
    }
}
