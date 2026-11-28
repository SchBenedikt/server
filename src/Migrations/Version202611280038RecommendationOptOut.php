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
 * Per-account opt-out for personalized recommendations. Opting out also
 * clears the stored snapshot, feedback and impression rows so no derived
 * interest profile survives the choice.
 */
final class Version202611280038RecommendationOptOut implements Migration
{
    public function version(): string
    {
        return '202611280038';
    }

    public function description(): string
    {
        return 'Add the per-account recommendation opt-out flag.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            'account_preference',
            'recommendations_opt_out',
            'INTEGER NOT NULL DEFAULT 0',
        );
    }

    public function down(Connection $connection): void
    {
        // Dropping the flag would silently re-enable personalization for
        // accounts that opted out; keeping the column is safer.
    }
}
