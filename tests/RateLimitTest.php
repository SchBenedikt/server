<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Http\RateLimiter;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
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
}
