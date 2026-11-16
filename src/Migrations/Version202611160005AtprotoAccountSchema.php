<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * One Bluesky account connected to a local Aloha account.
 *
 * The tokens are stored sealed by the host's SecretBox, never in plain text,
 * and no API response ever exposes them. The table is host-owned because the
 * core deliberately knows nothing about OAuth sessions or app passwords.
 */
final class Version202611160005AtprotoAccountSchema implements Migration
{
    public function version(): string
    {
        return '202611160005';
    }

    public function description(): string
    {
        return 'Create connected AT Protocol / Bluesky accounts.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE atproto_account ('
            . ' account_id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' did VARCHAR(255) NOT NULL,'
            . ' handle VARCHAR(253) NOT NULL,'
            . ' pds_url VARCHAR(255) NOT NULL,'
            . ' access_token TEXT NOT NULL,'
            . ' refresh_token TEXT NOT NULL,'
            . ' expires_at INTEGER NOT NULL DEFAULT 0,'
            . ' active INTEGER NOT NULL DEFAULT 1,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE UNIQUE INDEX atproto_account_did ON atproto_account (did)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS atproto_account');
    }
}
