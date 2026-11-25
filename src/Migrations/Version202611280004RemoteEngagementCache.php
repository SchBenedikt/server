<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Engagement counts of federated posts, as the origin reported them.
 *
 * A cached remote post has no local favourites or boosts, so a card that only
 * counts local rows always shows zero. The host asks the origin for the real
 * counters when a post is opened or first rendered and keeps them here for a
 * short while, so later cards render without another network call.
 */
final class Version202611280004RemoteEngagementCache implements Migration
{
    public function version(): string
    {
        return '202611280004';
    }

    public function description(): string
    {
        return 'Cache federated post engagement counts.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE social_remote_engagement ('
            . ' object_id VARCHAR(512) NOT NULL PRIMARY KEY,'
            . ' likes INTEGER NOT NULL DEFAULT 0,'
            . ' boosts INTEGER NOT NULL DEFAULT 0,'
            . ' replies INTEGER NOT NULL DEFAULT 0,'
            . ' fetched_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX social_remote_engagement_fetched ON social_remote_engagement (fetched_at)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS social_remote_engagement');
    }
}
