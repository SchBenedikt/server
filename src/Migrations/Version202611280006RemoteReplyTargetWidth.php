<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * A local post may answer a remote post, and that target is the remote object
 * URL rather than a 64-character local id. SQLite does not enforce the
 * declared length, PostgreSQL does, so the column is widened there. The core's
 * own schema keeps creating the shorthand definition; this migration runs
 * after it on every install.
 */
final class Version202611280006RemoteReplyTargetWidth implements Migration
{
    public function version(): string
    {
        return '202611280006';
    }

    public function description(): string
    {
        return 'Allow remote object URLs as a local post reply target.';
    }

    public function up(Connection $connection): void
    {
        if ($connection->driver() === 'pgsql') {
            $connection->execute(
                'ALTER TABLE social_post ALTER COLUMN in_reply_to_id TYPE VARCHAR(512)',
            );
        }
    }

    public function down(Connection $connection): void
    {
        // Existing remote reply targets cannot be represented in 64
        // characters, so narrowing again would corrupt the thread graph.
    }
}
