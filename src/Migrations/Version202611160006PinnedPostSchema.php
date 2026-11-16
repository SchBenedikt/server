<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Posts an account pins to the top of its profile.
 *
 * A pin is a host-owned marker, not a copy: the core still owns the post,
 * its visibility and its federation. Removing the row unpins the post, and a
 * deleted post simply stops resolving.
 */
final class Version202611160006PinnedPostSchema implements Migration
{
    public function version(): string
    {
        return '202611160006';
    }

    public function description(): string
    {
        return 'Create profile-pinned posts.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE pinned_post ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' position INTEGER NOT NULL DEFAULT 0,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        $connection->execute('CREATE INDEX pinned_post_account ON pinned_post (account_id, position)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS pinned_post');
    }
}
