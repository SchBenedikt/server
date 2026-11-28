<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Indexes the notification a `notification.created` stream event belongs to.
 *
 * Account erasure must remove stream copies of notifications that mention
 * the erased account — including copies sitting in other users' replay logs.
 * Those copies are only findable through the payload today, which meant
 * decoding every stored event. The column makes the lookup indexed; the
 * backfill covers events still inside their seven-day retention window.
 */
final class Version202611280019StreamEventNotificationId implements Migration
{
    private const TABLE = 'mastodon_stream_event';

    public function version(): string
    {
        return '202611280019';
    }

    public function description(): string
    {
        return 'Index the notification id on Mastodon stream events.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing($connection, self::TABLE, 'notification_id', 'VARCHAR(64) NULL');
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS mastodon_stream_event_notification_idx'
            . ' ON ' . self::TABLE . ' (notification_id)',
        );

        if ($connection->driver() === 'pgsql') {
            $connection->execute(
                'UPDATE ' . self::TABLE . " SET notification_id = payload_json::jsonb ->> 'notification_id'"
                . " WHERE event_name = 'notification.created' AND notification_id IS NULL",
            );

            return;
        }

        $connection->execute(
            "UPDATE " . self::TABLE . " SET notification_id = json_extract(payload_json, '$.notification_id')"
            . " WHERE event_name = 'notification.created' AND notification_id IS NULL",
        );
    }

    public function down(Connection $connection): void
    {
        // Keeping the column is safer than re-scanning payloads on rollback.
    }
}
