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
 * Hashed, single-use tokens for verified email-address changes, plus the
 * pending address reserved on the account. Only the hash is stored; the
 * plain token exists only inside the queued mail. `pending_email` holds the
 * candidate address until the mailed link is consumed — the account's
 * `email` (and with it password recovery) keeps pointing at the verified
 * address the whole time.
 */
final class Version202611280022EmailChange implements Migration
{
    public function version(): string
    {
        return '202611280022';
    }

    public function description(): string
    {
        return 'Create email change tokens and reserve pending addresses.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE email_change ('
            . ' token_hash CHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' new_email VARCHAR(255) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL,'
            . ' used_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX email_change_account ON email_change (account_id)',
        );
        SchemaUpgrade::addColumnIfMissing($connection, 'account', 'pending_email', 'VARCHAR(255) NULL');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE email_change');
        // The account column is kept on rollback: dropping it would lose
        // pending-change state for requests already mailed to members.
    }
}
