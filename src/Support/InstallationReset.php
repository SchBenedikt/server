<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Platform\Db\Connection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

use function array_map;
use function is_dir;
use function preg_match;
use function rmdir;
use function scandir;
use function unlink;

/**
 * Drops every application table so the installer can start over.
 *
 * The reset command backs up first; this class only performs the destructive
 * part. It deliberately has no "keep some tables" switch: a half-reset
 * instance is harder to reason about than a clean one.
 */
final class InstallationReset
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<string> the tables that were dropped
     */
    public function dropAllTables(): array
    {
        $tables = $this->tables();
        if ($tables === []) {
            return [];
        }

        $driver = $this->connection->driver();
        if ($driver === 'sqlite') {
            $this->connection->execute('PRAGMA foreign_keys = OFF');
        }

        try {
            $this->connection->transaction(function () use ($tables, $driver): void {
                foreach ($tables as $table) {
                    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                        throw new RuntimeException('Refusing to drop an unexpected table name: ' . $table);
                    }

                    $suffix = $driver === 'pgsql' ? ' CASCADE' : '';
                    $this->connection->execute(
                        'DROP TABLE IF EXISTS "' . $table . '"' . $suffix,
                    );
                }
            });
        } finally {
            if ($driver === 'sqlite') {
                $this->connection->execute('PRAGMA foreign_keys = ON');
            }
        }

        return $tables;
    }

    /**
     * Removes a directory's contents but keeps the directory itself.
     *
     * @return int the number of entries removed
     */
    public static function clearDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Cannot read the directory: ' . $directory);
        }

        $removed = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $removed += self::removeTree($path);
                continue;
            }

            if (!unlink($path)) {
                throw new RuntimeException('Cannot remove the file: ' . $path);
            }
            $removed++;
        }

        return $removed;
    }

    private static function removeTree(string $directory): int
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            $removed = 0;
            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                if ($entry->isDir()) {
                    if (!rmdir($entry->getPathname())) {
                        throw new RuntimeException('Cannot remove the directory: ' . $entry->getPathname());
                    }
                } elseif (!unlink($entry->getPathname())) {
                    throw new RuntimeException('Cannot remove the file: ' . $entry->getPathname());
                }
                $removed++;
            }

            if (!rmdir($directory)) {
                throw new RuntimeException('Cannot remove the directory: ' . $directory);
            }

            return $removed + 1;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Cannot clear the directory: ' . $directory, 0, $exception);
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        if ($this->connection->driver() === 'sqlite') {
            $rows = $this->connection->fetchAll(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            );
        } else {
            $rows = $this->connection->fetchAll(
                "SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename",
            );
        }

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }
}
