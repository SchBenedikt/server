<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Drafts, archived posts and the signals the For You ranker reads.
 *
 * A draft is one unsent composition per account and context, so reopening the
 * composer for the same reply restores exactly that draft. Archiving is a
 * private marker on an own post: the post itself stays in the core and is
 * never copied. The ranker uses the existing favourite/boost/follow/hashtag
 * tables; it needs no new signal table.
 */
final class Version202611160002EngagementSchema implements Migration
{
    public function version(): string
    {
        return '202611160002';
    }

    public function description(): string
    {
        return 'Create drafts and archived posts.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE draft ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' context VARCHAR(200) NOT NULL DEFAULT \'\','
            . ' content TEXT NOT NULL,'
            . ' content_warning VARCHAR(255) NULL,'
            . ' visibility VARCHAR(16) NOT NULL DEFAULT \'public\','
            . ' attachment_ids TEXT NOT NULL DEFAULT \'[]\','
            . ' in_reply_to_id VARCHAR(64) NULL,'
            . ' quoted_post_id VARCHAR(64) NULL,'
            . ' poll_options TEXT NULL,'
            . ' poll_multiple INTEGER NOT NULL DEFAULT 0,'
            . ' poll_hide_totals INTEGER NOT NULL DEFAULT 0,'
            . ' poll_expires_in INTEGER NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE UNIQUE INDEX draft_context ON draft (account_id, context)');
        $connection->execute('CREATE INDEX draft_account ON draft (account_id, updated_at)');

        $connection->execute(
            'CREATE TABLE archived_post ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' archived_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        $connection->execute('CREATE INDEX archived_post_account ON archived_post (account_id, archived_at)');
    }

    public function down(Connection $connection): void
    {
        foreach (['archived_post', 'draft'] as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
