<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Scheduling\ScheduledPostSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Stores the poll spec on scheduled posts.
 *
 * The spec keeps its expiry unresolved (`expires_in` or `expires_at`) until
 * publication, when the core resolves it against the publish time. Rows
 * written before the column existed stay NULL, which reads as "no poll".
 */
final class Version202611280018ScheduledPostPoll implements Migration
{
    public function version(): string
    {
        return '202611280018';
    }

    public function description(): string
    {
        return 'Store the poll spec on scheduled post rows.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            ScheduledPostSchema::TABLE,
            'poll',
            'TEXT NULL',
        );
        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        // Dropping the column would erase poll specs users scheduled;
        // keeping it is safer than losing that state.
    }
}
