<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Records the Core schema definitions the existing database was built from.
 * Later Core updates must ship a new host migration that stores a new
 * fingerprint; the boot check then refuses an unexplained schema change.
 */
final class Version202611180001CoreSchemaFingerprint implements Migration
{
    public function version(): string
    {
        return '202611180001';
    }

    public function description(): string
    {
        return 'Record the Core schema fingerprint.';
    }

    public function up(Connection $connection): void
    {
        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        $connection->execute(
            'DELETE FROM runtime_state WHERE name = :name',
            ['name' => CoreSchemaFingerprint::STATE_NAME],
        );
    }
}
