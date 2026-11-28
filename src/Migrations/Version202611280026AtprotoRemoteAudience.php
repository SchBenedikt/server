<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Marks cached AT Protocol rows as public.
 *
 * The reconciler only stores app.bsky.feed.post/repost records, which the
 * public firehose and public repositories expose without a viewer gate.
 * Rows ingested before the audience column was populated on that path carry
 * NULL, which the read rule treats like followers-only; backfilling the DID
 * actors keeps them visible to the anonymous surfaces they always fed.
 * ActivityPub rows keep their stored audience — NULL there still fails
 * closed.
 */
final class Version202611280026AtprotoRemoteAudience implements Migration
{
    public function version(): string
    {
        return '202611280026';
    }

    public function description(): string
    {
        return 'Mark cached AT Protocol remote posts as public audience.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'UPDATE ' . RemotePostSchema::TABLE . " SET audience = 'public'"
            . " WHERE audience IS NULL AND (actor_id LIKE 'did:%' OR object_author_id LIKE 'did:%')",
        );
    }

    public function down(Connection $connection): void
    {
        // The previous NULL state was an ingestion gap, not a verified
        // audience; restoring it would only re-hide public content.
    }
}
