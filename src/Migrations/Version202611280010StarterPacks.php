<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Curated account sets that can be followed in one step.
 *
 * A starter pack is a host-local directory entry: the pack stores the
 * canonical identity of each member (a local account id, an ActivityPub actor
 * URL or an AT Protocol DID) together with the display data needed to render
 * the list without a second network lookup. It never duplicates the follow
 * graph, so following a pack still creates ordinary follow edges through the
 * existing services.
 */
final class Version202611280010StarterPacks implements Migration
{
    public function version(): string
    {
        return '202611280010';
    }

    public function description(): string
    {
        return 'Add starter packs with curated account members.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE social_starter_pack ('
            . ' id VARCHAR(24) NOT NULL PRIMARY KEY,'
            . ' owner_id VARCHAR(64) NOT NULL,'
            . ' title VARCHAR(80) NOT NULL,'
            . ' description VARCHAR(500) NOT NULL DEFAULT \'\','
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX social_starter_pack_owner ON social_starter_pack (owner_id, updated_at)',
        );
        $connection->execute(
            'CREATE TABLE social_starter_pack_member ('
            . ' id VARCHAR(24) NOT NULL PRIMARY KEY,'
            . ' pack_id VARCHAR(24) NOT NULL,'
            . ' member_id VARCHAR(512) NOT NULL,'
            . ' network VARCHAR(16) NOT NULL,'
            . ' handle VARCHAR(255) NOT NULL DEFAULT \'\','
            . ' display_name VARCHAR(255) NULL,'
            . ' avatar_url VARCHAR(1024) NULL,'
            . ' inbox_url VARCHAR(1024) NULL,'
            . ' position INTEGER NOT NULL DEFAULT 0,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX social_starter_pack_member_pack ON social_starter_pack_member (pack_id, position)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS social_starter_pack_member');
        $connection->execute('DROP TABLE IF EXISTS social_starter_pack');
    }
}
