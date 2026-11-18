<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Bearer credentials for the optional native PDS.
 *
 * ATProto clients authenticate with an access and a refresh token, not with
 * the host's browser cookie. Only hashes are stored, so a database leak cannot
 * be replayed as a PDS session. A row is independent of the browser session
 * table; disabling an account still invalidates both on the next request.
 */
final class Version202611180003AtprotoSessionSchema implements Migration
{
    public function version(): string
    {
        return '202611180003';
    }

    public function description(): string
    {
        return 'Create the ATProto PDS access and refresh token table.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE atproto_session ('
            . ' access_hash CHAR(64) NOT NULL PRIMARY KEY,'
            . ' refresh_hash CHAR(64) NOT NULL,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' access_expires_at VARCHAR(20) NOT NULL,'
            . ' refresh_expires_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE UNIQUE INDEX atproto_session_refresh ON atproto_session (refresh_hash)');
        $connection->execute('CREATE INDEX atproto_session_account ON atproto_session (account_id)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS atproto_session');
    }
}
