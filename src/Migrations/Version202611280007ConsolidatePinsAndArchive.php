<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Post\PostPin;
use AlohaSocial\Core\Domain\Post\PostPinSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Consolidates the two host-owned duplicates onto the core state.
 *
 * `pinned_post` becomes `social_post_pin` and `archived_post` becomes
 * `social_post.archived_at`. The copy is deliberately conservative: only a
 * live, public post owned by the pinning account is carried over, and at most
 * the core's five pins per account. Archive markers for missing or foreign
 * posts are ignored. The old tables are dropped afterwards so there is no
 * second source of truth left to drift.
 */
final class Version202611280007ConsolidatePinsAndArchive implements Migration
{
    public function version(): string
    {
        return '202611280007';
    }

    public function description(): string
    {
        return 'Move host pins and archives onto the core post state.';
    }

    public function up(Connection $connection): void
    {
        // A very old installation may reach this migration without the core
        // tables it targets. Create the additive pieces first instead of
        // dropping legacy rows into nowhere.
        if (!SchemaUpgrade::tableExists($connection, PostPinSchema::TABLE)) {
            foreach (PostPinSchema::statements() as $statement) {
                $connection->execute($statement);
            }
        }
        if (SchemaUpgrade::tableExists($connection, PostSchema::TABLE)
            && !SchemaUpgrade::columnExists($connection, PostSchema::TABLE, 'archived_at')
        ) {
            SchemaUpgrade::addColumnIfMissing($connection, PostSchema::TABLE, 'archived_at', 'VARCHAR(20) NULL');
        }

        if (SchemaUpgrade::tableExists($connection, 'pinned_post')) {
            $this->copyPins($connection);
        }

        if (SchemaUpgrade::tableExists($connection, 'archived_post')
            && SchemaUpgrade::tableExists($connection, PostSchema::TABLE)
        ) {
            $this->copyArchives($connection);
        }

        $connection->execute('DROP TABLE IF EXISTS pinned_post');
        $connection->execute('DROP TABLE IF EXISTS archived_post');
    }

    public function down(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS pinned_post ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' position INTEGER NOT NULL DEFAULT 0,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        SchemaUpgrade::createIndexIfMissing($connection, 'pinned_post', 'pinned_post_account', 'account_id, position');
        $connection->execute(
            'INSERT INTO pinned_post (account_id, post_id, position, created_at)'
            . ' SELECT owner_id, post_id, position - 1, pinned_at FROM social_post_pin',
        );

        $connection->execute(
            'CREATE TABLE IF NOT EXISTS archived_post ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' archived_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        SchemaUpgrade::createIndexIfMissing($connection, 'archived_post', 'archived_post_account', 'account_id, archived_at');
        $connection->execute(
            'INSERT INTO archived_post (account_id, post_id, archived_at)'
            . ' SELECT author_id, id, archived_at FROM social_post WHERE archived_at IS NOT NULL',
        );
    }

    private function copyPins(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT account_id, post_id, position, created_at FROM pinned_post'
            . ' ORDER BY account_id ASC, position ASC, created_at ASC',
        );

        /** @var array<string, array<int, true>> $used */
        $used = [];
        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($rows as $row) {
            $owner = (string) $row['account_id'];
            $postId = (string) $row['post_id'];
            if (!isset($counts[$owner])) {
                $counts[$owner] = (int) $connection->fetchValue(
                    'SELECT COUNT(*) FROM social_post_pin WHERE owner_id = :owner_id',
                    ['owner_id' => $owner],
                );
                foreach ($connection->fetchAll(
                    'SELECT position FROM social_post_pin WHERE owner_id = :owner_id',
                    ['owner_id' => $owner],
                ) as $existing) {
                    $used[$owner][(int) $existing['position']] = true;
                }
            }
            if ($counts[$owner] >= PostPin::MAX_PINS) {
                continue;
            }

            $post = $connection->fetchOne(
                'SELECT 1 AS present FROM social_post'
                . " WHERE id = :post_id AND author_id = :owner_id AND deleted_at IS NULL"
                . " AND archived_at IS NULL AND visibility = 'public'",
                ['post_id' => $postId, 'owner_id' => $owner],
            );
            if ($post === null) {
                continue;
            }

            if ($connection->fetchOne(
                'SELECT 1 AS present FROM social_post_pin WHERE owner_id = :owner_id AND post_id = :post_id',
                ['owner_id' => $owner, 'post_id' => $postId],
            ) !== null) {
                continue;
            }

            $position = 1;
            while (isset($used[$owner][$position])) {
                ++$position;
            }
            $connection->execute(
                'INSERT INTO social_post_pin (owner_id, post_id, position, pinned_at)'
                . ' VALUES (:owner_id, :post_id, :position, :pinned_at)',
                [
                    'owner_id' => $owner,
                    'post_id' => $postId,
                    'position' => $position,
                    'pinned_at' => (string) $row['created_at'],
                ],
            );
            $used[$owner][$position] = true;
            $counts[$owner] = ($counts[$owner] ?? 0) + 1;
        }
    }

    private function copyArchives(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT account_id, post_id, archived_at FROM archived_post'
            . ' ORDER BY archived_at ASC, account_id ASC, post_id ASC',
        );
        foreach ($rows as $row) {
            $connection->execute(
                'UPDATE social_post SET archived_at = :archived_at'
                . ' WHERE id = :post_id AND author_id = :owner_id'
                . ' AND deleted_at IS NULL AND archived_at IS NULL',
                [
                    'archived_at' => (string) $row['archived_at'],
                    'post_id' => (string) $row['post_id'],
                    'owner_id' => (string) $row['account_id'],
                ],
            );
        }
    }
}
