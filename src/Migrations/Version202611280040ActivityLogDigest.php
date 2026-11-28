<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Adds the replay-log digest column.
 *
 * Core #246 made authenticated inbound retries idempotent by storing a
 * SHA-256 digest next to each claimed activity id. Fresh installs get the
 * column from the base schema statements; upgraded installations need this
 * step or every `ActivityLog::claim` write fails and the inbox answers 500.
 * The fingerprint is refreshed because the schema statement set changed.
 */
final class Version202611280040ActivityLogDigest implements Migration
{
    public function version(): string
    {
        return '202611280040';
    }

    public function description(): string
    {
        return 'Add digest to social_activity_log for idempotent inbound retries.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            ActivityLogSchema::TABLE,
            'digest',
            'VARCHAR(64) NULL',
        );

        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        // The column only records evidence for replay decisions; dropping it
        // would make every stored claim look like a conflict, so it stays.
    }
}
