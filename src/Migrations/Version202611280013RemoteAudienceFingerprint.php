<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Refreshes the Core schema fingerprint after the audience column.
 *
 * Migration 012 may already have been applied on an installation that ran the
 * first revision of the audience change. This separate, idempotent step makes
 * the stored hash match the current Core schema on upgrades as well as fresh
 * installs, so app and worker boot instead of reporting an unexplained schema
 * difference.
 */
final class Version202611280013RemoteAudienceFingerprint implements Migration
{
    public function version(): string
    {
        return '202611280013';
    }

    public function description(): string
    {
        return 'Refresh the Core schema fingerprint after the remote audience column.';
    }

    public function up(Connection $connection): void
    {
        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        // The fingerprint must keep matching the installed Core schema.
    }
}
