<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Platform\Clock\Clock;
use ReflectionClass;
use Throwable;

/**
 * The server's migration mechanism.
 *
 * `schema_migration` records what ran, when and with which checksum. A changed
 * checksum on an applied migration is a hard error: editing history would make
 * two installations silently diverge. DDL runs inside a transaction where the
 * driver supports it (PostgreSQL and SQLite do; MySQL does not).
 */
final class MigrationRunner
{
    public const TABLE = 'schema_migration';

    /** @var list<Migration> */
    private readonly array $migrations;

    /**
     * @param list<Migration> $migrations
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
        array $migrations,
    ) {
        $this->migrations = array_values($migrations);
    }

    /**
     * @return list<string> the versions applied by this run
     */
    public function migrate(): array
    {
        $this->ensureTable();

        $applied = $this->applied();
        $done = [];

        foreach ($this->migrations as $migration) {
            $version = $migration->version();
            $checksum = self::checksum($migration);

            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new MigrationException(
                        'Migration ' . $version . ' changed after it was applied (checksum mismatch).'
                        . ' Add a new migration instead of editing this one.',
                    );
                }

                continue;
            }

            $run = function () use ($migration, $version, $checksum): void {
                $this->prepare($migration);
                $migration->up($this->connection);
                $this->connection->execute(
                    'INSERT INTO ' . self::TABLE . ' (version, applied_at, checksum) VALUES (:version, :applied_at, :checksum)',
                    [
                        'version' => $version,
                        'applied_at' => Time::format($this->clock->now()),
                        'checksum' => $checksum,
                    ],
                );
            };

            if (in_array($this->connection->driver(), ['sqlite', 'pgsql'], true)) {
                $this->connection->transaction($run);
            } else {
                $run();
            }

            $done[] = $version;
        }

        return $done;
    }

    /**
     * @return array<string, string> version => checksum
     */
    public function applied(): array
    {
        $rows = $this->connection->fetchAll('SELECT version, checksum FROM ' . self::TABLE . ' ORDER BY version ASC');

        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['version']] = (string) $row['checksum'];
        }

        return $applied;
    }

    public function isInstalled(): bool
    {
        try {
            $this->connection->fetchValue('SELECT version FROM ' . self::TABLE . ' LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Version 008 is checksum-frozen and already applied on installations.
     * Its legacy view copy cannot be changed, so prepare its input in
     * application code instead of editing the migration.
     */
    private function prepare(Migration $migration): void
    {
        if ($migration->version() !== LegacyStoryViewCollisionGuard::MIGRATION_VERSION) {
            return;
        }

        (new LegacyStoryViewCollisionGuard($this->connection))->prepare();
    }

    public static function checksum(Migration $migration): string
    {
        $file = (new ReflectionClass($migration))->getFileName();
        if (!is_string($file) || !is_file($file)) {
            throw new MigrationException('Cannot checksum the migration ' . $migration::class . '.');
        }

        $hash = hash_file('sha256', $file);
        if (!is_string($hash)) {
            throw new MigrationException('Cannot read the migration file ' . $file . '.');
        }

        return $hash;
    }

    private function ensureTable(): void
    {
        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . ' version VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' applied_at VARCHAR(20) NOT NULL,'
            . ' checksum CHAR(64) NOT NULL'
            . ')',
        );
    }
}
