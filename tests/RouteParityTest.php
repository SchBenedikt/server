<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class RouteParityTest extends TestCase
{
    public function testServedRoutesEqualTheCoreContract(): void
    {
        $server = new TestServer();
        $expected = Routes::manifest(MastodonApiConfig::prefix($server->config->coreConfig()))->routes();
        $actual = $server->runtime->app->routes->routes();

        self::assertSame(count($expected), count($actual));

        foreach ($expected as $index => $route) {
            $served = $actual[$index];

            self::assertSame($route->name, $served->name);
            self::assertSame($route->method, $served->method);
            self::assertSame($route->path, $served->path);
            self::assertSame($route->handler, $served->handler);
            self::assertSame($route->requiresAuth, $served->requiresAuth);
            self::assertSame($route->rateLimit, $served->rateLimit);
            self::assertSame($route->requiredScopes, $served->requiredScopes);
        }
    }

    public function testHostPagesDoNotShadowACoreRoute(): void
    {
        $server = new TestServer();
        $hostPaths = ['/', '/login', '/logout', '/favicon.ico'];

        foreach ($server->runtime->app->routes->routes() as $route) {
            self::assertNotContains($route->path, $hostPaths, 'Host page shadows the core route ' . $route->name);
        }
    }

    public function testHealthAndInstanceEndpointsAnswer(): void
    {
        $server = new TestServer();

        $health = $server->send('GET', '/healthz');
        self::assertSame(200, $health->status);
        self::assertSame('{"status":"ok"}', $health->body);

        $ready = $server->send('GET', '/readyz');
        self::assertSame(200, $ready->status);
        $readiness = json_decode($ready->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($readiness['ready']);
        self::assertSame('ok', $readiness['checks']['database']['status']);
        self::assertArrayNotHasKey('dsn', $readiness['checks']['database']);

        $instance = $server->send('GET', '/api/v1/instance');
        self::assertSame(200, $instance->status);
        self::assertStringContainsString('Aloha Test', $instance->body);
    }

    public function testEveryReadRouteIsWired(): void
    {
        $server = new TestServer();

        foreach ($server->runtime->app->routes->routes() as $route) {
            if ($route->method !== 'GET') {
                continue;
            }

            $path = preg_replace('/\{[^}]+\}/', 'test', $route->path);
            self::assertIsString($path);

            $query = str_ends_with($route->path, '/webfinger')
                ? ['resource' => 'acct:nobody@localhost']
                : [];

            $response = $server->send('GET', $path, ['query' => $query]);

            self::assertNotSame(500, $response->status, 'Route ' . $route->name . ' raised an internal error.');
        }
    }
}
