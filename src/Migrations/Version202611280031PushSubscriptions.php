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
 * Web Push foundation: the instance VAPID key pair and per-account browser
 * push subscriptions.
 */
final class Version202611280031PushSubscriptions implements Migration
{
    public function version(): string
    {
        return '202611280031';
    }

    public function description(): string
    {
        return 'Create the push_vapid and push_subscription tables.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE push_vapid ('
            . ' id VARCHAR(16) NOT NULL PRIMARY KEY,'
            . ' public_key VARCHAR(128) NOT NULL,'
            . ' private_pem TEXT NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE TABLE push_subscription ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' endpoint VARCHAR(2048) NOT NULL,'
            . ' p256dh VARCHAR(256) NOT NULL,'
            . ' auth VARCHAR(128) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' last_seen_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE UNIQUE INDEX push_subscription_endpoint ON push_subscription (endpoint)',
        );
        $connection->execute(
            'CREATE INDEX push_subscription_account ON push_subscription (account_id)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS push_subscription');
        $connection->execute('DROP TABLE IF EXISTS push_vapid');
    }
}
