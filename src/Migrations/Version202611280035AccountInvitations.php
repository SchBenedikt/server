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
 * Administrator-issued registration invitations: hashed single-purpose
 * tokens with a use cap, optional expiry and revocation.
 */
final class Version202611280035AccountInvitations implements Migration
{
    public function version(): string
    {
        return '202611280035';
    }

    public function description(): string
    {
        return 'Create the account_invitation table for registration invites.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE account_invitation ('
            . ' id VARCHAR(32) NOT NULL PRIMARY KEY,'
            . ' code_hash CHAR(64) NOT NULL,'
            . ' code_prefix VARCHAR(16) NOT NULL,'
            . ' created_by VARCHAR(64) NOT NULL,'
            . ' max_uses INT NOT NULL,'
            . ' uses INT NOT NULL DEFAULT 0,'
            . ' expires_at VARCHAR(20) NULL,'
            . ' note VARCHAR(255) NULL,'
            . ' revoked_at VARCHAR(20) NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE UNIQUE INDEX account_invitation_code ON account_invitation (code_hash)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS account_invitation');
    }
}
