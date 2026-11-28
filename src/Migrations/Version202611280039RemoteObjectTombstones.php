<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\RemoteObjectTombstoneSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Creates the core remote-object tombstone store.
 *
 * Core #247 introduced owner-scoped tombstones for deleted remote objects and
 * the inbox now consults the store on every inbound Create/Update/Delete, so
 * an installation without the table answers 500 to delivered activities.
 * The step is idempotent and refreshes the schema fingerprint because the
 * schema class joins the fingerprinted set.
 */
final class Version202611280039RemoteObjectTombstones implements Migration
{
    public function version(): string
    {
        return '202611280039';
    }

    public function description(): string
    {
        return 'Create social_remote_object_tombstone for owner-scoped remote deletes.';
    }

    public function up(Connection $connection): void
    {
        if (!SchemaUpgrade::tableExists($connection, RemoteObjectTombstoneSchema::TABLE)) {
            RemoteObjectTombstoneSchema::create($connection);
        }

        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        // Tombstones are the only durable record that a remote object was
        // deleted; dropping them could resurrect deleted content, so the
        // table stays. The fingerprint already covers it.
    }
}
