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
 * Priority hints for the remote engagement refresh.
 *
 * Read paths never fetch origin counters inline: a card view records the
 * object id here and the worker drains the queue on its next engagement
 * tick, so visible posts are refreshed before the generic newest-first
 * scan without adding remote latency to the API response.
 */
final class Version202611280027RemoteEngagementRequest implements Migration
{
    public function version(): string
    {
        return '202611280027';
    }

    public function description(): string
    {
        return 'Queue visible remote posts for engagement counter refresh.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE remote_engagement_request ('
            . ' object_id VARCHAR(512) NOT NULL PRIMARY KEY,'
            . ' requested_at VARCHAR(20) NOT NULL'
            . ')',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS remote_engagement_request');
    }
}
