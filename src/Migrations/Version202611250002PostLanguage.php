<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use RuntimeException;

/** Preserve language tags on live, scheduled and edited posts. */
final class Version202611250002PostLanguage implements Migration
{
    private const TABLES = [
        PostSchema::TABLE,
        PostSchema::REVISION_TABLE,
        ScheduledPostSchema::TABLE,
    ];

    public function version(): string
    {
        return '202611250002';
    }

    public function description(): string
    {
        return 'Add language tags to posts, revisions and scheduled posts.';
    }

    public function up(Connection $connection): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->columnExists($connection, $table)) {
                $connection->execute('ALTER TABLE ' . $table . ' ADD COLUMN language VARCHAR(35) NULL');
            }
        }

        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if ($this->columnExists($connection, $table)) {
                $connection->execute('ALTER TABLE ' . $table . ' DROP COLUMN language');
            }
        }

        CoreSchemaFingerprint::store($connection);
    }

    private function columnExists(Connection $connection, string $table): bool
    {
        return match ($connection->driver()) {
            'sqlite' => $this->sqliteColumnExists($connection, $table),
            'mysql' => $connection->fetchValue(
                'SELECT 1 FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, 'language'],
            ) !== null,
            'pgsql' => $connection->fetchValue(
                'SELECT 1 FROM information_schema.columns'
                . ' WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, 'language'],
            ) !== null,
            'sqlsrv' => $connection->fetchValue(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS'
                . ' WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'language'],
            ) !== null,
            default => throw new RuntimeException(
                'The post language migration does not support the ' . $connection->driver() . ' driver.',
            ),
        };
    }

    private function sqliteColumnExists(Connection $connection, string $table): bool
    {
        foreach ($connection->fetchAll('PRAGMA table_info(' . $table . ')') as $row) {
            if (($row['name'] ?? null) === 'language') {
                return true;
            }
        }

        return false;
    }
}
