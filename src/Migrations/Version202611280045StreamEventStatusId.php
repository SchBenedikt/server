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
 * Indexes the post a `status.*` stream event belongs to.
 *
 * The `hashtag` channel must match events against
 * `social_post_hashtag` at read time; decoding `payload_json` per event
 * would make every poll a full scan. The column makes the join indexed;
 * the backfill covers events still inside their retention window.
 */
final class Version202611280045StreamEventStatusId implements Migration
{
    private const TABLE = 'mastodon_stream_event';

    public function version(): string
    {
        return '202611280045';
    }

    public function description(): string
    {
        return 'Index the status id on Mastodon stream events for hashtag streams.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing($connection, self::TABLE, 'status_id', 'VARCHAR(64) NULL');
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS mastodon_stream_event_status_idx'
            . ' ON ' . self::TABLE . ' (status_id)',
        );

        if ($connection->driver() === 'pgsql') {
            $connection->execute(
                "UPDATE " . self::TABLE . " SET status_id = payload_json::jsonb ->> 'status_id'"
                . " WHERE event_name IN ('status.created', 'status.updated', 'status.deleted') AND status_id IS NULL",
            );

            return;
        }

        $connection->execute(
            "UPDATE " . self::TABLE . " SET status_id = json_extract(payload_json, '$.status_id')"
            . " WHERE event_name IN ('status.created', 'status.updated', 'status.deleted') AND status_id IS NULL",
        );
    }

    public function down(Connection $connection): void
    {
        // Keeping the column is safer than re-scanning payloads on rollback.
    }
}
