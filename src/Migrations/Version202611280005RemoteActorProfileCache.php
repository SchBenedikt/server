<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Display profiles of remote actors, cached for the federated timeline.
 *
 * A remote post stores only the canonical actor URL. Reading the actor
 * document for every card would make a feed page depend on peer latency, so
 * the worker fills this small cache and the timeline renders from it. The
 * row is deliberately not an access decision: it only supplies a handle,
 * display name and avatar.
 */
final class Version202611280005RemoteActorProfileCache implements Migration
{
    public function version(): string
    {
        return '202611280005';
    }

    public function description(): string
    {
        return 'Cache remote actor display profiles.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE social_remote_actor_profile ('
            . ' actor_id VARCHAR(255) NOT NULL PRIMARY KEY,'
            . ' handle VARCHAR(255) NULL,'
            . ' display_name VARCHAR(255) NULL,'
            . ' avatar_url VARCHAR(1024) NULL,'
            . ' fetched_at VARCHAR(20) NOT NULL,'
            . ' failed_at VARCHAR(20) NULL,'
            . ' failures INTEGER NOT NULL DEFAULT 0'
            . ')',
        );
        $connection->execute(
            'CREATE INDEX social_remote_actor_profile_fetched ON social_remote_actor_profile (fetched_at)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS social_remote_actor_profile');
    }
}
