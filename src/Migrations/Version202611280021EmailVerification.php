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
 * Hashed, single-use email verification tokens for registration, plus the
 * verified marker on the account. Only the hash is stored; the plain token
 * exists only inside the queued mail.
 */
final class Version202611280021EmailVerification implements Migration
{
    public function version(): string
    {
        return '202611280021';
    }

    public function description(): string
    {
        return 'Create email verification tokens and mark verified accounts.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE email_verification ('
            . ' token_hash CHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL,'
            . ' used_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX email_verification_account ON email_verification (account_id)',
        );
        SchemaUpgrade::addColumnIfMissing($connection, 'account', 'email_verified_at', 'VARCHAR(20) NULL');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE email_verification');
        // The account column is kept on rollback: dropping it would lose
        // verified state for accounts that already confirmed their address.
    }
}
