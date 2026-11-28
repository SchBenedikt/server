<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Durable outbox for transactional mail.
 *
 * Password reset links must survive an SMTP outage: the token row and the
 * queued message commit together, and the worker retries delivery with
 * bounded backoff instead of failing the HTTP request. The payload column
 * holds the recipient, subject and body sealed with the instance secret box,
 * so a database dump does not expose reset tokens or addresses in clear.
 */
final class Version202611280020MailOutbox implements Migration
{
    public function version(): string
    {
        return '202611280020';
    }

    public function description(): string
    {
        return 'Create the durable mail outbox for transactional email.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE mail_outbox ('
            . ' id CHAR(32) NOT NULL PRIMARY KEY,'
            . ' kind VARCHAR(48) NOT NULL,'
            . ' account_id VARCHAR(64) NULL,'
            . ' dedup_key VARCHAR(128) NOT NULL,'
            . ' payload TEXT NOT NULL,'
            . ' status VARCHAR(16) NOT NULL,'
            . ' attempts INTEGER NOT NULL,'
            . ' max_attempts INTEGER NOT NULL,'
            . ' next_attempt_at VARCHAR(20) NOT NULL,'
            . ' last_error VARCHAR(200) NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' sent_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute(
            'CREATE UNIQUE INDEX mail_outbox_dedup ON mail_outbox (kind, dedup_key)',
        );
        $connection->execute(
            'CREATE INDEX mail_outbox_due ON mail_outbox (status, next_attempt_at)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE mail_outbox');
    }
}
