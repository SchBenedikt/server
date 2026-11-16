<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Per-account preferences and recommendation feedback.
 *
 * Preferences are what the composer and the reader default to: visibility,
 * quote policy, who may send a direct message and which notifications to
 * create. They are host-owned because the core deliberately keeps no user
 * profile settings. Recommendation feedback is a private "show me less/more of
 * this" marker that only the For You ranker reads.
 */
final class Version202611160003PreferencesSchema implements Migration
{
    public function version(): string
    {
        return '202611160003';
    }

    public function description(): string
    {
        return 'Create account preferences and recommendation feedback.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE account_preference ('
            . ' account_id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' default_visibility VARCHAR(16) NOT NULL DEFAULT \'public\','
            . ' default_quote_policy VARCHAR(16) NOT NULL DEFAULT \'public\','
            . ' dm_policy VARCHAR(16) NOT NULL DEFAULT \'everyone\','
            . ' show_boosts INTEGER NOT NULL DEFAULT 1,'
            . ' discoverable INTEGER NOT NULL DEFAULT 1,'
            . ' notify_follows INTEGER NOT NULL DEFAULT 1,'
            . ' notify_favourites INTEGER NOT NULL DEFAULT 1,'
            . ' notify_boosts INTEGER NOT NULL DEFAULT 1,'
            . ' notify_mentions INTEGER NOT NULL DEFAULT 1,'
            . ' updated_at VARCHAR(20) NOT NULL'
            . ')',
        );

        $connection->execute(
            'CREATE TABLE recommendation_feedback ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX recommendation_feedback_account ON recommendation_feedback (account_id, kind)',
        );
    }

    public function down(Connection $connection): void
    {
        foreach (['recommendation_feedback', 'account_preference'] as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
