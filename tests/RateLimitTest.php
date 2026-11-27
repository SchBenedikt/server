<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Http\RateLimiter;
use AlohaSocial\Server\Tests\Support\TestServer;
use AlohaSocial\Server\Tests\Support\UnavailableConnection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Throwable;

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

    public function testWindowRolloverAndRetryAfterUseTheSameFixedBoundary(): void
    {
        $server = new TestServer();
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T20:00:00Z'));
        $limiter = new RateLimiter($server->runtime->database, $clock, static fn (): bool => false);

        self::assertNull($limiter->attempt('clock.test', 'alice', 2, 60));
        self::assertNull($limiter->attempt('clock.test', 'alice', 2, 60));
        self::assertSame(60, $limiter->attempt('clock.test', 'alice', 2, 60));

        // A restarted worker reads the same row and must not reset the budget.
        $restarted = new RateLimiter($server->runtime->database, $clock, static fn (): bool => false);
        self::assertSame(60, $restarted->attempt('clock.test', 'alice', 2, 60));

        $clock->advance(59);
        self::assertSame(1, $limiter->attempt('clock.test', 'alice', 2, 60));

        $clock->advance(1);
        self::assertNull($limiter->attempt('clock.test', 'alice', 2, 60));
        self::assertSame(1, (int) $server->runtime->database->fetchValue(
            'SELECT hits FROM rate_limit WHERE bucket = :bucket',
            ['bucket' => RateLimiter::bucket('clock.test', 'alice')],
        ));
    }

    public function testSweepAndReservationDoNotLoseTheNewWindow(): void
    {
        $server = new TestServer();
        $db = $server->runtime->database;
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T20:00:00Z'));
        $bucket = RateLimiter::bucket('cleanup.race', 'alice');
        $db->execute(
            'INSERT INTO rate_limit (bucket, hits, window_started_at) VALUES (:bucket, 10, :started)',
            [
                'bucket' => $bucket,
                'started' => Time::format($clock->now()->modify('-' . (RateLimiter::MAX_WINDOW_SECONDS + 1) . ' seconds')),
            ],
        );

        $limiter = new RateLimiter($db, $clock, static fn (): bool => true);
        self::assertNull($limiter->attempt('cleanup.race', 'alice', 2, 60));

        $row = $db->fetchOne(
            'SELECT hits, window_started_at FROM rate_limit WHERE bucket = :bucket',
            ['bucket' => $bucket],
        );
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['hits']);
        self::assertSame(Time::format($clock->now()), $row['window_started_at']);
    }

    public function testDatabaseOutageFailsClosedInsteadOfAdmitting(): void
    {
        $limiter = new RateLimiter(
            new UnavailableConnection(),
            new FrozenClock(new DateTimeImmutable('2026-11-27T20:00:00Z')),
            static fn (): bool => false,
        );

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('The test database is unavailable.');
        $limiter->attempt('outage.test', 'alice', 10, 60);
    }

    public function testConcurrentBurstAdmitsExactlyTheBudget(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the multi-process test.');
        }

        $server = new TestServer();
        $server->preserveFiles();
        $directory = $server->config->varDirectory . '/burst-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0o770, true));

        $workers = 6;
        $limit = 3;
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T20:00:00Z'));
        $pids = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'The test process could not be forked.');

            if ($pid === 0) {
                $server->preserveFiles();

                try {
                    file_put_contents($directory . '/ready-' . $worker, '1');
                    $deadline = microtime(true) + 10;
                    while (!is_file($directory . '/go') && microtime(true) < $deadline) {
                        usleep(1000);
                    }

                    $limiter = new RateLimiter(PdoFactory::connect($server->config), $clock, static fn (): bool => false);
                    $retryAfter = $limiter->attempt('burst.test', 'shared', $limit, 60);
                    file_put_contents(
                        $directory . '/result-' . $worker,
                        json_encode(['retry_after' => $retryAfter], JSON_THROW_ON_ERROR),
                    );
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents(
                        $directory . '/result-' . $worker,
                        json_encode(['error' => $exception::class . ': ' . $exception->getMessage()], JSON_THROW_ON_ERROR),
                    );
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        $deadline = microtime(true) + 10;
        while (count(glob($directory . '/ready-*') ?: []) < $workers && microtime(true) < $deadline) {
            usleep(1000);
        }
        self::assertCount($workers, glob($directory . '/ready-*') ?: []);
        file_put_contents($directory . '/go', '1');

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status), 'A burst worker did not exit normally.');
            self::assertSame(0, pcntl_wexitstatus($status), 'A burst worker failed.');
        }

        $admitted = 0;
        $blocked = 0;
        for ($worker = 0; $worker < $workers; $worker++) {
            /** @var array{retry_after?: int|null, error?: string} $result */
            $result = json_decode((string) file_get_contents($directory . '/result-' . $worker), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $result);

            if (($result['retry_after'] ?? null) === null) {
                $admitted++;
            } else {
                $blocked++;
                self::assertGreaterThanOrEqual(1, $result['retry_after']);
                self::assertLessThanOrEqual(60, $result['retry_after']);
            }
        }

        self::assertSame($limit, $admitted);
        self::assertSame($workers - $limit, $blocked);
        self::assertSame($limit, (int) $server->runtime->database->fetchValue(
            'SELECT hits FROM rate_limit WHERE bucket = :bucket',
            ['bucket' => RateLimiter::bucket('burst.test', 'shared')],
        ));
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
