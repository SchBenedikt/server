<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Stores the remote reply target on local posts.
 *
 * A reply to a remote post keeps the remote object's canonical id and its
 * author separately from `in_reply_to_id`, which only names local posts.
 * The dedicated columns and index mirror `PostSchema::upgradeStatements()`
 * for installations created before remote replies existed.
 */
final class Version202611280016LocalPostRemoteReplyEdge implements Migration
{
    public function version(): string
    {
        return '202611280016';
    }

    public function description(): string
    {
        return 'Store remote reply targets on local posts.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            PostSchema::TABLE,
            'in_reply_to_remote_object_id',
            'VARCHAR(512) NULL',
        );
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            PostSchema::TABLE,
            'in_reply_to_remote_author',
            'VARCHAR(512) NULL',
        );
        SchemaUpgrade::createIndexIfMissing(
            $connection,
            PostSchema::TABLE,
            PostSchema::TABLE . '_remote_reply',
            'in_reply_to_remote_object_id, published_at',
        );
        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        // The columns carry reply relationships; dropping them would corrupt
        // the thread graph, so the migration stays additive.
    }
}
