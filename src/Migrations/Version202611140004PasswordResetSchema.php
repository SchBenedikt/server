<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * One-time password reset tokens.
 *
 * Only the SHA-256 of a token is stored, so a database dump cannot be used to
 * reset an account. A row is single-use and expires; the account id points at
 * the host-owned account table.
 */
final class Version202611140004PasswordResetSchema implements Migration
{
    public function version(): string
    {
        return '202611140004';
    }

    public function description(): string
    {
        return 'Create the password_reset table for emailed reset links.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE password_reset ('
            . ' token_hash VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL,'
            . ' used_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX password_reset_account ON password_reset (account_id)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS password_reset');
    }
}
