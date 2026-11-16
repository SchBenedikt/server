<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Host-owned community surfaces.
 *
 * These are first-party features the core deliberately does not model:
 * instance announcements written by admins, hashtags an account features on
 * its profile, curated post collections and 24-hour stories. Posts and media
 * stay in the core; the tables here only reference their ids.
 */
final class Version202611160001CommunitySchema implements Migration
{
    public function version(): string
    {
        return '202611160001';
    }

    public function description(): string
    {
        return 'Create announcements, featured tags, collections and stories.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE announcement ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' author_id VARCHAR(64) NOT NULL,'
            . ' content VARCHAR(500) NOT NULL,'
            . ' active INTEGER NOT NULL DEFAULT 1,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX announcement_active ON announcement (active, created_at)');

        $connection->execute(
            'CREATE TABLE featured_tag ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' tag VARCHAR(100) NOT NULL,'
            . ' position INTEGER NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, tag)'
            . ')',
        );
        $connection->execute('CREATE INDEX featured_tag_account ON featured_tag (account_id, position)');

        $connection->execute(
            'CREATE TABLE collection ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' title VARCHAR(100) NOT NULL,'
            . ' description VARCHAR(500) NOT NULL DEFAULT \'\','
            . ' is_public INTEGER NOT NULL DEFAULT 1,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX collection_account ON collection (account_id, updated_at)');

        $connection->execute(
            'CREATE TABLE collection_item ('
            . ' collection_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' position INTEGER NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (collection_id, post_id)'
            . ')',
        );
        $connection->execute('CREATE INDEX collection_item_order ON collection_item (collection_id, position)');

        $connection->execute(
            'CREATE TABLE story ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' content VARCHAR(500) NOT NULL,'
            . ' background VARCHAR(32) NOT NULL DEFAULT \'ocean\','
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX story_expiry ON story (expires_at, created_at)');
        $connection->execute('CREATE INDEX story_account ON story (account_id, created_at)');
    }

    public function down(Connection $connection): void
    {
        foreach (['story', 'collection_item', 'collection', 'featured_tag', 'announcement'] as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
