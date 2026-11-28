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
 * Per-account UI locale. NULL means "follow the browser", matching the
 * previous behaviour before the preference existed.
 */
final class Version202611280041UiLocale implements Migration
{
    public function version(): string
    {
        return '202611280041';
    }

    public function description(): string
    {
        return 'Add the per-account UI locale preference.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            'account_preference',
            'ui_locale',
            'VARCHAR(35) NULL',
        );
    }

    public function down(Connection $connection): void
    {
        // Dropping the column would silently reset every account to the
        // browser locale; keeping it is safer.
    }
}
