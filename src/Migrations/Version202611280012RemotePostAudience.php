<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Stores the audience a remote post was delivered with.
 *
 * A public or unlisted remote post may be liked or boosted without following
 * its author. Persisting the audience is what makes that decision safe:
 * followers-only objects still require an accepted follow, and legacy rows
 * with no stored audience keep the old follow gate.
 */
final class Version202611280012RemotePostAudience implements Migration
{
    public function version(): string
    {
        return '202611280012';
    }

    public function description(): string
    {
        return 'Store the audience of remote posts for safe interaction authorization.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            RemotePostSchema::TABLE,
            'audience',
            'VARCHAR(16) NULL',
        );
    }

    public function down(Connection $connection): void
    {
        // Dropping the column would erase the only evidence that a legacy
        // remote row was public; keeping it is safer than losing that state.
    }
}
