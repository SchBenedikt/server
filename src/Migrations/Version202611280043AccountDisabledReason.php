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
 * Records why an account is disabled.
 *
 * Registration can park an account before it may sign in: `verification`
 * while the mailbox link is outstanding and `approval` while an
 * administrator has not decided yet. Admin suspensions write `admin`; rows
 * predating the column keep NULL and are treated as suspensions. The
 * distinction lets the admin console offer approve/notify semantics instead
 * of a flat "deactivated" badge.
 */
final class Version202611280043AccountDisabledReason implements Migration
{
    public function version(): string
    {
        return '202611280043';
    }

    public function description(): string
    {
        return 'Add disabled_reason to account for approval/verification lifecycle.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            'account',
            'disabled_reason',
            'VARCHAR(30) NULL',
        );
    }

    public function down(Connection $connection): void
    {
        // The reason only annotates an existing disabled state; dropping it
        // would lose the approval/verification distinction, so it stays.
    }
}
