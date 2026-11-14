<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The host-owned account, session and key tables.
 *
 * ADR 0006 keeps accounts, sessions and roles host-owned; the core only ever
 * receives the authenticated id. The table is `account` rather than `user`
 * because `user` is a reserved word in PostgreSQL and would need quoting in
 * every statement of every adapter.
 */
final class Version202611140002HostSchema implements Migration
{
    public function version(): string
    {
        return '202611140002';
    }

    public function description(): string
    {
        return 'Create the host account, session, login-throttle and instance-key tables.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE account ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' handle VARCHAR(64) NOT NULL,'
            . ' email VARCHAR(255) NOT NULL,'
            . ' password_hash VARCHAR(255) NOT NULL,'
            . ' role VARCHAR(16) NOT NULL,'
            . ' display_name VARCHAR(255) NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' disabled_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute('CREATE UNIQUE INDEX account_handle ON account (handle)');
        $connection->execute('CREATE UNIQUE INDEX account_email ON account (email)');

        $connection->execute(
            'CREATE TABLE session ('
            . ' id_hash CHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NULL,'
            . ' csrf_token VARCHAR(64) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL,'
            . ' user_agent VARCHAR(255) NULL,'
            . ' ip_hash CHAR(64) NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX session_account ON session (account_id)');
        $connection->execute('CREATE INDEX session_expiry ON session (expires_at)');

        $connection->execute(
            'CREATE TABLE login_attempt ('
            . ' bucket VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' attempts INTEGER NOT NULL,'
            . ' window_started_at VARCHAR(20) NOT NULL'
            . ')',
        );

        $connection->execute(
            'CREATE TABLE instance_key ('
            . ' id VARCHAR(32) NOT NULL PRIMARY KEY,'
            . ' algorithm VARCHAR(16) NOT NULL,'
            . ' public_pem TEXT NOT NULL,'
            . ' private_pem TEXT NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
    }

    public function down(Connection $connection): void
    {
        foreach (['instance_key', 'login_attempt', 'session', 'account'] as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
