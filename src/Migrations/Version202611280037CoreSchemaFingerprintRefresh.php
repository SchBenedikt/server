<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Refreshes the Core schema fingerprint after the 024/026/033 column additions.
 *
 * Those migrations apply Core schema changes but predate the boot-time
 * fingerprint assertion, so upgraded installations keep the hash stored by an
 * older Core while `current()` already reflects the new statements. Without
 * this step every request fails the schema check even though `update.php`
 * reports nothing pending. The step is idempotent and also covers fresh
 * installs.
 */
final class Version202611280037CoreSchemaFingerprintRefresh implements Migration
{
    public function version(): string
    {
        return '202611280037';
    }

    public function description(): string
    {
        return 'Refresh the Core schema fingerprint after the job, audience and moderation column migrations.';
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
