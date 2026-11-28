<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Serializes schema migrations across processes.
 *
 * `bin/update.php`, `bin/migrate.php`, `bin/reset.php` and first-boot setup
 * can run while the web app and worker are alive; without a lock two
 * migrators could interleave DDL and leave `schema_migration` claiming
 * states the schema does not have. Each driver gets its native primitive:
 *
 * - PostgreSQL: a session-level advisory lock, released in `finally`.
 * - MySQL: a named lock via `GET_LOCK`, which also works across sessions.
 * - SQLite: the database file already allows a single writer; wrapping the
 *   whole run in one transaction makes a second migrator fail fast on the
 *   first write instead of racing statement-by-statement. As a bonus all
 *   pending migrations then commit atomically.
 */
final class MigrationLock
{
    /** Stable key for `pg_advisory_lock`; changing it would split the lock domain. */
    private const PG_LOCK_KEY = 727201;

    private const MYSQL_LOCK_NAME = 'alohasocial_migrate';

    private const MYSQL_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @template T
     * @param callable(Connection): T $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        return match ($this->connection->driver()) {
            'pgsql' => $this->withPostgresLock($work),
            'mysql' => $this->withMysqlLock($work),
            'sqlite' => $this->connection->transaction(
                static fn (Connection $connection): mixed => $work($connection),
            ),
            default => $work($this->connection),
        };
    }

    /**
     * @template T
     * @param callable(Connection): T $work
     * @return T
     */
    private function withPostgresLock(callable $work): mixed
    {
        $this->connection->fetchValue('SELECT pg_advisory_lock(:key)', ['key' => self::PG_LOCK_KEY]);

        try {
            return $work($this->connection);
        } finally {
            $this->connection->fetchValue('SELECT pg_advisory_unlock(:key)', ['key' => self::PG_LOCK_KEY]);
        }
    }

    /**
     * @template T
     * @param callable(Connection): T $work
     * @return T
     */
    private function withMysqlLock(callable $work): mixed
    {
        $acquired = $this->connection->fetchValue(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => self::MYSQL_LOCK_NAME, 'timeout' => self::MYSQL_TIMEOUT_SECONDS],
        );

        if ((int) $acquired !== 1) {
            throw new MigrationException(
                'Another migration is already running; wait for it to finish and retry.',
            );
        }

        try {
            return $work($this->connection);
        } finally {
            $this->connection->fetchValue('SELECT RELEASE_LOCK(:name)', ['name' => self::MYSQL_LOCK_NAME]);
        }
    }
}
