<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Server\Http\RateLimiter;
use AlohaSocial\Server\Migrations\Version202611140003RateLimitSchema;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The fixed-window limiter on PostgreSQL.
 *
 * CI runs this against PostgreSQL 17. Without `ALOHASOCIAL_TEST_PG_DSN` the
 * class skips, matching the existing PostgreSQL reset fixture.
 */
final class PostgresRateLimitTest extends TestCase
{
    private ?PDO $admin = null;

    private ?PdoConnection $db = null;

    private string $schema;

    private string $dsn;

    private ?string $user;

    private ?string $password;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql is not installed.');
        }

        $dsn = getenv('ALOHASOCIAL_TEST_PG_DSN');
        if (!is_string($dsn) || trim($dsn) === '') {
            self::markTestSkipped('Set ALOHASOCIAL_TEST_PG_DSN to run the PostgreSQL rate-limit tests.');
        }

        $this->dsn = $dsn;
        $user = getenv('ALOHASOCIAL_TEST_PG_USER');
        $password = getenv('ALOHASOCIAL_TEST_PG_PASSWORD');
        $this->user = is_string($user) && $user !== '' ? $user : null;
        $this->password = is_string($password) && $password !== '' ? $password : null;

        $this->admin = $this->adminConnection();
        $this->schema = 'rate_' . bin2hex(random_bytes(6));
        $this->admin->exec('CREATE SCHEMA "' . $this->schema . '"');
        $this->db = $this->connect();
        (new Version202611140003RateLimitSchema())->up($this->database());
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null && isset($this->schema)) {
            $this->admin->exec('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
        }
    }

    public function testBudgetWindowRestartAndCleanupUseOneAtomicBoundary(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T20:00:00Z'));
        $limiter = new RateLimiter($this->database(), $clock, static fn (): bool => true);

        self::assertNull($limiter->attempt('postgres.clock', 'alice', 2, 60));
        self::assertNull($limiter->attempt('postgres.clock', 'alice', 2, 60));
        self::assertSame(60, $limiter->attempt('postgres.clock', 'alice', 2, 60));

        // A new worker process reads the committed counter, not a fresh one.
        $restarted = new RateLimiter($this->database(), $clock, static fn (): bool => false);
        self::assertSame(60, $restarted->attempt('postgres.clock', 'alice', 2, 60));

        $clock->advance(59);
        self::assertSame(1, $limiter->attempt('postgres.clock', 'alice', 2, 60));
        $clock->advance(1);
        self::assertNull($limiter->attempt('postgres.clock', 'alice', 2, 60));
        self::assertSame(1, (int) $this->database()->fetchValue(
            'SELECT hits FROM rate_limit WHERE bucket = :bucket',
            ['bucket' => RateLimiter::bucket('postgres.clock', 'alice')],
        ));
    }

    public function testConcurrentBurstAdmitsExactlyTheBudget(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the multi-process test.');
        }

        $workers = 6;
        $limit = 3;
        $directory = sys_get_temp_dir() . '/alohasocial-pg-rate-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o770, true));
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T20:00:00Z'));

        // Never fork a live PostgreSQL handle: the child's PDO destructor
        // would terminate the shared backend. Each process connects itself.
        $this->db = null;
        $this->admin = null;
        gc_collect_cycles();

        $pids = [];
        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'The test process could not be forked.');

            if ($pid === 0) {
                try {
                    file_put_contents($directory . '/ready-' . $worker, '1');
                    $deadline = microtime(true) + 10;
                    while (!is_file($directory . '/go') && microtime(true) < $deadline) {
                        usleep(1000);
                    }

                    $limiter = new RateLimiter($this->connect(), $clock, static fn (): bool => false);
                    $retryAfter = $limiter->attempt('postgres.burst', 'shared', $limit, 60);
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

        $this->admin = $this->adminConnection();
        $this->db = $this->connect();

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
        self::assertSame($limit, (int) $this->database()->fetchValue(
            'SELECT hits FROM rate_limit WHERE bucket = :bucket',
            ['bucket' => RateLimiter::bucket('postgres.burst', 'shared')],
        ));

        $this->removeDirectory($directory);
    }

    private function database(): PdoConnection
    {
        if ($this->db === null) {
            throw new RuntimeException('The PostgreSQL test connection is not open.');
        }

        return $this->db;
    }

    private function adminConnection(): PDO
    {
        return new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function connect(): PdoConnection
    {
        $pdo = $this->adminConnection();
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec('SET search_path TO "' . $this->schema . '"');

        return new PdoConnection($pdo);
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
