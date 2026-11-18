<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Which stories one account has already watched.
 *
 * A story disappears after 24 hours, so the marker is only meaningful while
 * the story exists. Rows are therefore scoped to `(story_id, account_id)` and
 * cleaned up lazily: deleting a story removes its markers, and an expired
 * story is simply never read again.
 */
final class Version202611180004StorySeenSchema implements Migration
{
    public function version(): string
    {
        return '202611180004';
    }

    public function description(): string
    {
        return 'Create the story seen marker table.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE story_seen ('
            . ' story_id VARCHAR(64) NOT NULL,'
            . ' account_id VARCHAR(255) NOT NULL,'
            . ' seen_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (story_id, account_id)'
            . ')',
        );
        $connection->execute('CREATE INDEX story_seen_account ON story_seen (account_id)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS story_seen');
    }
}
