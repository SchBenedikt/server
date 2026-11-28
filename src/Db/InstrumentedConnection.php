<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Db;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Platform\Db\TransactionRunner;
use AlohaSocial\Server\Support\Metrics;
use Throwable;

/**
 * Times every query the process runs and feeds the numbers into
 * {@see Metrics} for the `/metrics` scrape.
 *
 * The decorator adds no labels that could grow without bound: `op` is one
 * of the four `Connection` entry points and nothing derived from SQL text,
 * parameters or row data ever leaves the process. Slow queries (over 100 ms)
 * get their own counter so contention shows up before latency averages do.
 *
 * Each process instruments its own connection — the app reports request-path
 * database time on `/metrics`; the worker's numbers stay in-process.
 */
final class InstrumentedConnection implements Connection, TransactionRunner
{
    private const SLOW_QUERY_SECONDS = 0.1;

    public function __construct(
        private readonly PdoConnection $inner,
        private readonly Metrics $metrics,
    ) {
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return $this->timed('execute', fn (): int => $this->inner->execute($sql, $parameters));
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        return $this->timed('fetch_all', fn (): array => $this->inner->fetchAll($sql, $parameters));
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        return $this->timed('fetch_one', fn (): ?array => $this->inner->fetchOne($sql, $parameters));
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        return $this->timed('fetch_value', fn (): mixed => $this->inner->fetchValue($sql, $parameters));
    }

    public function transaction(callable $work): mixed
    {
        // Nested work receives this decorator, not the inner connection, so
        // queries inside a transaction stay instrumented. The inner runner
        // still owns depth tracking and rollback semantics.
        return $this->inner->transaction(
            fn (Connection $connection): mixed => $work($this),
        );
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function timed(string $op, callable $call): mixed
    {
        $start = microtime(true);
        try {
            return $call();
        } catch (Throwable $exception) {
            $this->metrics->increment('aloha_db_query_errors_total', ['op' => $op]);
            throw $exception;
        } finally {
            $elapsed = microtime(true) - $start;
            $this->metrics->increment('aloha_db_queries_total', ['op' => $op]);
            $this->metrics->observe('aloha_db_query_seconds', $elapsed, ['op' => $op]);
            if ($elapsed >= self::SLOW_QUERY_SECONDS) {
                $this->metrics->increment('aloha_db_slow_queries_total', ['op' => $op]);
            }
        }
    }
}
