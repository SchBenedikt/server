<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Migrations\Migration;

/**
 * Pending Web Push deliveries. The notification sink enqueues one row per
 * visible notification; the worker drains due rows with bounded retries.
 */
final class Version202611280032PushDelivery implements Migration
{
    public function version(): string
    {
        return '202611280032';
    }

    public function description(): string
    {
        return 'Create the push_delivery queue table.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE push_delivery ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' payload TEXT NOT NULL,'
            . ' attempts INTEGER NOT NULL DEFAULT 0,'
            . ' next_attempt_at VARCHAR(20) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX push_delivery_due ON push_delivery (next_attempt_at)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS push_delivery');
    }
}
