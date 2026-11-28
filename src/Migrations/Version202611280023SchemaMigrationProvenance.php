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
 * Records which Core build applied each migration. Rows applied before this
 * migration keep NULL — their provenance is unknown rather than guessed.
 */
final class Version202611280023SchemaMigrationProvenance implements Migration
{
    public function version(): string
    {
        return '202611280023';
    }

    public function description(): string
    {
        return 'Record Core version provenance on applied migrations.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing($connection, 'schema_migration', 'core_version', 'VARCHAR(64) NULL');
        SchemaUpgrade::addColumnIfMissing($connection, 'schema_migration', 'core_reference', 'VARCHAR(64) NULL');
    }

    public function down(Connection $connection): void
    {
        // Provenance columns are kept on rollback: they are additive metadata
        // and dropping them would erase the audit trail they exist for.
    }
}
