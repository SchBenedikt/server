<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdateSchema;
use AlohaSocial\Core\Domain\Federation\RemotePollSchema;
use AlohaSocial\Core\Domain\Filter\FilterSchema;
use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\QuoteGrantSchema;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use Throwable;

/**
 * Brings an existing 0.7 installation up to the current core schema.
 *
 * The core ships `upgradeStatements()` for columns it added after 0.7; this
 * migration runs them together with the tables that were introduced later.
 * A fresh install already creates the new columns through the schema classes,
 * so every step checks first and is safe to run twice.
 */
final class Version202611140005CoreMainSchema implements Migration
{
    public function version(): string
    {
        return '202611140005';
    }

    public function description(): string
    {
        return 'Adopt the current core schema: reactions, quote grants, remote polls, filters, scheduling and post revisions.';
    }

    public function up(Connection $connection): void
    {
        foreach ($this->columnUpgrades() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!$this->columnExists($connection, $table, $column)) {
                    $connection->execute(
                        'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition,
                    );
                }
            }
        }

        if ($this->tableExists($connection, PostSchema::REVISION_TABLE)) {
            // Already created by the base migration on a fresh install.
        } else {
            foreach (PostSchema::statements() as $statement) {
                if (str_contains($statement, PostSchema::REVISION_TABLE)) {
                    $connection->execute($statement);
                }
            }
        }

        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS social_following_follow_activity'
            . ' ON social_following (follow_activity_id)',
        );

        $this->createTable($connection, ReactionSchema::TABLE, static fn () => ReactionSchema::create($connection));
        $this->createTable($connection, QuoteGrantSchema::TABLE, static fn () => QuoteGrantSchema::create($connection));
        $this->createTable($connection, RemotePollSchema::TABLE, static fn () => RemotePollSchema::create($connection));
        $this->createTable($connection, PendingRemoteUpdateSchema::TABLE, static fn () => PendingRemoteUpdateSchema::create($connection));
        $this->createTable($connection, FilterSchema::TABLE, static fn () => FilterSchema::create($connection));
        $this->createTable($connection, ScheduledPostSchema::TABLE, static fn () => ScheduledPostSchema::create($connection));
    }

    public function down(Connection $connection): void
    {
        foreach ([
            ScheduledPostSchema::TABLE,
            FilterSchema::TABLE,
            PendingRemoteUpdateSchema::TABLE,
            RemotePollSchema::VOTE_TABLE,
            RemotePollSchema::TABLE,
            QuoteGrantSchema::TABLE,
            ReactionSchema::TABLE,
            PostSchema::REVISION_TABLE,
        ] as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function columnUpgrades(): array
    {
        return [
            PostSchema::TABLE => [
                'revision' => 'INTEGER NOT NULL DEFAULT 1',
                'edited_at' => 'VARCHAR(20) NULL',
                'quoted_object_id' => 'VARCHAR(1024) NULL',
                'quote_state' => 'VARCHAR(16) NULL',
                'quote_authorization_id' => 'VARCHAR(1024) NULL',
                'quote_request_id' => 'VARCHAR(1024) NULL',
            ],
            \AlohaSocial\Core\Domain\Federation\RemotePostSchema::TABLE => [
                'updated_at' => 'VARCHAR(20) NULL',
                'object_type' => "VARCHAR(16) NOT NULL DEFAULT 'note'",
                'object_metadata' => 'TEXT NULL',
                'quoted_object_id' => 'VARCHAR(512) NULL',
            ],
            \AlohaSocial\Core\Domain\Media\AttachmentSchema::TABLE => [
                'media_kind' => "VARCHAR(16) NOT NULL DEFAULT 'image'",
                'duration_seconds' => 'INTEGER NOT NULL DEFAULT 0',
                'animated' => 'SMALLINT NOT NULL DEFAULT 0',
                'focus_x' => 'REAL NULL',
                'focus_y' => 'REAL NULL',
            ],
            \AlohaSocial\Core\Domain\Federation\FollowingSchema::TABLE => [
                'state' => "VARCHAR(16) NOT NULL DEFAULT 'accepted'",
                'follow_activity_id' => 'VARCHAR(512) NULL',
            ],
        ];
    }

    private function createTable(Connection $connection, string $table, callable $create): void
    {
        if (!$this->tableExists($connection, $table)) {
            $create();
        }
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        try {
            if ($connection->driver() === 'sqlite') {
                return $connection->fetchValue(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table],
                ) !== null;
            }

            return $connection->fetchValue(
                'SELECT table_name FROM information_schema.tables'
                . " WHERE table_schema = 'public' AND table_name = ?",
                [$table],
            ) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnExists(Connection $connection, string $table, string $column): bool
    {
        try {
            if ($connection->driver() === 'sqlite') {
                foreach ($connection->fetchAll('PRAGMA table_info(' . $table . ')') as $row) {
                    if (($row['name'] ?? null) === $column) {
                        return true;
                    }
                }

                return false;
            }

            return $connection->fetchValue(
                'SELECT column_name FROM information_schema.columns'
                . ' WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                ['public', $table, $column],
            ) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
