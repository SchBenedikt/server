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
 * Per-account timeline density. NULL means the comfortable default.
 */
final class Version202611280044UiDensity implements Migration
{
    public function version(): string
    {
        return '202611280044';
    }

    public function description(): string
    {
        return 'Add the per-account timeline density preference.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            'account_preference',
            'ui_density',
            'VARCHAR(16) NULL',
        );
    }

    public function down(Connection $connection): void
    {
        // Dropping the column would reset every account to the default
        // density; keeping it is safer.
    }
}
