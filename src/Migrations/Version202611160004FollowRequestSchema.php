<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Protected profiles and the follow requests they collect.
 *
 * A protected account publishes posts to its followers only in spirit: the
 * host keeps new local follows pending until the owner approves them. The
 * request is a host row because a pending follow is not an edge yet; only an
 * approved request becomes a follower row in the core graph.
 */
final class Version202611160004FollowRequestSchema implements Migration
{
    public function version(): string
    {
        return '202611160004';
    }

    public function description(): string
    {
        return 'Create follow requests and the protected-profile flag.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE follow_request ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' target_account_id VARCHAR(64) NOT NULL,'
            . ' actor_id VARCHAR(255) NOT NULL,'
            . ' actor_handle VARCHAR(255) NOT NULL,'
            . ' is_local INTEGER NOT NULL DEFAULT 1,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE UNIQUE INDEX follow_request_pair ON follow_request (target_account_id, actor_id)',
        );
        $connection->execute(
            'CREATE INDEX follow_request_target ON follow_request (target_account_id, created_at)',
        );

        $connection->execute(
            'ALTER TABLE account_preference ADD COLUMN locked INTEGER NOT NULL DEFAULT 0',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS follow_request');
    }
}
