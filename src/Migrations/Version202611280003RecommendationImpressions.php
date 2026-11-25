<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * What one reader actually saw, and for how long.
 *
 * The For You ranker already had explicit "less/more of this" markers. This
 * table adds the implicit side: a post the reader skipped after 300 ms is a
 * weaker interest signal than one they read for eight seconds, and a post that
 * was already shown should not lead the next page. Rows are per reader and
 * post, so re-seeing a post only raises its counter instead of duplicating it.
 *
 * `post_id` is sized for federated activity ids, which are much longer than
 * local post ids.
 */
final class Version202611280003RecommendationImpressions implements Migration
{
    public function version(): string
    {
        return '202611280003';
    }

    public function description(): string
    {
        return 'Create the ranked-feed impression log.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE recommendation_impression ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(255) NOT NULL,'
            . ' author_id VARCHAR(255) NOT NULL,'
            . ' seen_at VARCHAR(20) NOT NULL,'
            . ' dwell_ms INTEGER NOT NULL DEFAULT 0,'
            . ' times INTEGER NOT NULL DEFAULT 1,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX recommendation_impression_account ON recommendation_impression (account_id, seen_at)',
        );
        $connection->execute(
            'CREATE INDEX recommendation_impression_author ON recommendation_impression (account_id, author_id)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS recommendation_impression');
    }
}
