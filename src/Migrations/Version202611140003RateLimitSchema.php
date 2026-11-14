<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Per-route request budgets.
 *
 * The core's manifest declares a rate limit for most routes; the host stores
 * the counters. A row is one bucket (route + user or address) inside one
 * fixed window.
 */
final class Version202611140003RateLimitSchema implements Migration
{
    public function version(): string
    {
        return '202611140003';
    }

    public function description(): string
    {
        return 'Create the rate_limit table for the declared route budgets.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE rate_limit ('
            . ' bucket VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' hits INTEGER NOT NULL,'
            . ' window_started_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX rate_limit_window ON rate_limit (window_started_at)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS rate_limit');
    }
}
