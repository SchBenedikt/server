<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Inbound ActivityPub replies to local posts.
 *
 * The core stores a remote Create as a standalone RemotePost, but that table
 * does not keep the `inReplyTo` edge. The host therefore keeps the minimum
 * needed to count replies to local posts. Only the public ActivityPub object
 * id, its author and its local parent are stored; the reply content remains in
 * the core's remote-post store.
 */
final class Version202611180002RemoteReplySchema implements Migration
{
    public function version(): string
    {
        return '202611180002';
    }

    public function description(): string
    {
        return 'Create the inbound remote reply index.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE social_remote_reply ('
            . ' activity_id VARCHAR(512) NOT NULL PRIMARY KEY,'
            . ' object_id VARCHAR(512) NOT NULL,'
            . ' in_reply_to_id VARCHAR(64) NOT NULL,'
            . ' actor_id VARCHAR(255) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE UNIQUE INDEX social_remote_reply_object ON social_remote_reply (object_id)');
        $connection->execute('CREATE INDEX social_remote_reply_parent ON social_remote_reply (in_reply_to_id)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS social_remote_reply');
    }
}
