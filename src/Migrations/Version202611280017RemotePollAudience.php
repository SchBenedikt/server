<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\RemotePollSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Stores the delivered audience on imported remote Questions.
 *
 * Remote poll reads and votes are authorized by the same rule as remote
 * posts. Persisting the audience the Question arrived with is what makes
 * that safe for rows written before the column existed: they stay NULL,
 * which the shared policy treats as unknown and therefore followers-gated,
 * never public.
 */
final class Version202611280017RemotePollAudience implements Migration
{
    public function version(): string
    {
        return '202611280017';
    }

    public function description(): string
    {
        return 'Store the delivered audience on remote poll rows.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            RemotePollSchema::TABLE,
            'audience',
            'VARCHAR(16) NULL',
        );
        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        // Dropping the column would erase the only audience evidence for
        // imported polls; keeping it is safer than losing that state.
    }
}
