<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Http\RateLimiter;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testPeriodicSweepRemovesExpiredRowsButPreservesLiveBudgets(): void
    {
        $server = new TestServer();
        $db = $server->runtime->database;
        $now = $server->runtime->clock->now();
        $expired = RateLimiter::bucket('public.search', '198.51.100.10');
        $live = RateLimiter::bucket('public.search', '198.51.100.11');
        $db->execute(
            'INSERT INTO rate_limit (bucket, hits, window_started_at) VALUES (:expired, 1, :expired_at), (:live, 1, :live_at)',
            [
                'expired' => $expired,
                'expired_at' => Time::format($now->modify('-' . (RateLimiter::MAX_WINDOW_SECONDS + 1) . ' seconds')),
                'live' => $live,
                'live_at' => Time::format($now->modify('-' . (RateLimiter::MAX_WINDOW_SECONDS - 1) . ' seconds')),
            ],
        );

        $limiter = new RateLimiter($db, $server->runtime->clock, static fn (): bool => true);
        self::assertNull($limiter->attempt('public.search', '198.51.100.12', 10, 60));
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM rate_limit WHERE bucket = :bucket', ['bucket' => $expired]));
        self::assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM rate_limit WHERE bucket = :bucket', ['bucket' => $live]));
    }

    public function testDeclaredRouteWindowsFitWithinTheCleanupHorizon(): void
    {
        $server = new TestServer();
        foreach ($server->runtime->app->routes->routes() as $route) {
            if ($route->rateLimit === null) {
                continue;
            }

            [, $window] = explode('/', $route->rateLimit, 2);
            self::assertLessThanOrEqual(
                RateLimiter::MAX_WINDOW_SECONDS,
                (int) $window,
                'Rate-limit cleanup horizon is too short for ' . $route->name,
            );
        }

        self::assertNull($server->runtime->rateLimiter->attempt('host.signup', 'signup-probe', 5, 3600));
    }

    public function testExhaustedBudgetAnswersWithRetryAfter(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $session = $server->signIn();

        $server->runtime->database->execute(
            'INSERT INTO rate_limit (bucket, hits, window_started_at) VALUES (:bucket, 30, :now)',
            [
                'bucket' => RateLimiter::bucket('posts.create', 'alice'),
                'now' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        );

        $blocked = $server->json('POST', '/api/v1/posts', [
            'content' => 'This write must be refused',
            'visibility' => 'public',
        ], $session['cookies'], $session['csrf']);

        self::assertSame(429, $blocked->status);
        self::assertStringContainsString('rate_limited', $blocked->body);
        self::assertNotNull($blocked->header('Retry-After'));

        // The budget belongs to one route, not to the whole API.
        self::assertSame(200, $server->send('GET', '/api/v1/timelines/public')->status);
    }

    public function testAnExpiredWindowAllowsTheRequestAgain(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $session = $server->signIn();

        $server->runtime->database->execute(
            'INSERT INTO rate_limit (bucket, hits, window_started_at) VALUES (:bucket, 30, :now)',
            [
                'bucket' => RateLimiter::bucket('posts.create', 'alice'),
                'now' => gmdate('Y-m-d\TH:i:s\Z', time() - 1000),
            ],
        );

        $created = $server->json('POST', '/api/v1/posts', [
            'content' => 'After the window',
            'visibility' => 'public',
        ], $session['cookies'], $session['csrf']);

        self::assertSame(201, $created->status);
    }

    public function testPostBoostAndLikeActionsEnforceIndependentBudgets(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $session = $server->signIn();
        $created = $server->json('POST', '/api/v1/posts', [
            'content' => 'Interaction rate limit target',
            'visibility' => 'public',
        ], $session['cookies'], $session['csrf']);
        /** @var array{post: array{id: string}} $createdBody */
        $createdBody = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);
        $postId = $createdBody['post']['id'];
        $now = gmdate('Y-m-d\\TH:i:s\\Z');

        $server->runtime->database->execute(
            'INSERT INTO rate_limit (bucket, hits, window_started_at) VALUES (:bucket, 60, :now)',
            ['bucket' => RateLimiter::bucket('host.post_boost', 'alice'), 'now' => $now],
        );
        $blockedBoost = $server->json('POST', '/api/v1/posts/' . $postId . '/boost', [], $session['cookies'], $session['csrf']);
        self::assertSame(429, $blockedBoost->status);
        self::assertStringContainsString('rate_limited', $blockedBoost->body);
        self::assertNotNull($blockedBoost->header('Retry-After'));

        $likeStillAvailable = $server->json('POST', '/api/v1/posts/' . $postId . '/like', [], $session['cookies'], $session['csrf']);
        self::assertSame(200, $likeStillAvailable->status);

        $server->runtime->database->execute(
            'UPDATE rate_limit SET hits = 60, window_started_at = :now WHERE bucket = :bucket',
            ['bucket' => RateLimiter::bucket('host.post_like', 'alice'), 'now' => $now],
        );
        $blockedLike = $server->json('POST', '/api/v1/posts/' . $postId . '/like', [], $session['cookies'], $session['csrf']);
        self::assertSame(429, $blockedLike->status);
    }
}
