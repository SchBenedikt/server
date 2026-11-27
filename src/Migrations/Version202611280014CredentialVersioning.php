<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Binds reset tokens to a credential generation and permits one token per
 * account.
 *
 * A password change increments `account.credential_version`; a reset token
 * records the version it was issued for. A token that survived a password
 * change on another path (admin or CLI) therefore cannot reset the new
 * credential. The unique account index makes concurrent issuance converge on
 * a single live token even on drivers where the application-level account
 * lock is weaker.
 */
final class Version202611280014CredentialVersioning implements Migration
{
    public function version(): string
    {
        return '202611280014';
    }

    public function description(): string
    {
        return 'Version account credentials and keep one password reset token per account.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'ALTER TABLE account ADD COLUMN credential_version INTEGER NOT NULL DEFAULT 1',
        );
        $connection->execute(
            'ALTER TABLE password_reset ADD COLUMN credential_version INTEGER NOT NULL DEFAULT 1',
        );

        // Pre-existing installations could hold several rows after two
        // concurrent requests. Keep the newest token and discard only those
        // older reset links before installing the uniqueness invariant.
        $connection->execute(
            'DELETE FROM password_reset WHERE token_hash NOT IN ('
            . ' SELECT token_hash FROM ('
            . '  SELECT token_hash, ROW_NUMBER() OVER ('
            . '   PARTITION BY account_id ORDER BY created_at DESC, token_hash DESC'
            . '  ) AS rank_position FROM password_reset'
            . ' ) ranked WHERE rank_position = 1'
            . ')',
        );
        $connection->execute(
            'CREATE UNIQUE INDEX password_reset_account_unique ON password_reset (account_id)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP INDEX IF EXISTS password_reset_account_unique');
        $connection->execute('ALTER TABLE password_reset DROP COLUMN credential_version');
        $connection->execute('ALTER TABLE account DROP COLUMN credential_version');
    }
}
