<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

use function array_values;
use function implode;
use function max;
use function min;
use function strcmp;

/**
 * A cheap change cursor for an open page, so a timeline can update itself
 * without reloading.
 *
 * The reader's browser asks every few seconds what appeared or disappeared
 * since the last cursor and receives only ids; the existing batch endpoint
 * hydrates them. The query is limited to the viewer's own posts and the
 * accounts they follow, so the poll never leaks another account's content and
 * never turns into a full timeline read.
 *
 * A cursor older than the window is clamped instead of answered with the whole
 * archive: an idle tab should resume, not flood the page with a backlog.
 */
final class LiveUpdates
{
    public const MAX_ITEMS = 40;

    public const MAX_LOOKBACK_SECONDS = 1800;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{since: string, cursor: string, created: list<string>, deleted: list<string>}
     */
    public function changes(string $accountId, ?string $since): array
    {
        $now = $this->clock->now();
        $cursor = Time::format($now);
        $floor = Time::format($now->modify('-' . self::MAX_LOOKBACK_SECONDS . ' seconds'));

        $start = $since === null ? '' : trim($since);
        if ($start === '' || strcmp($start, $floor) < 0) {
            $start = $floor;
        }

        $followed = 'SELECT actor_id FROM social_following'
            . " WHERE local_user_id = :follower_main AND state = 'accepted'"
            . ' UNION SELECT subject_did FROM ' . AtprotoFollowSchema::TABLE
            . " WHERE local_user_id = :follower_atproto AND state = 'active'";

        // Each statement gets exactly its own parameters: PDO rejects a
        // parameter set that names placeholders the statement does not use.
        $localParameters = [
            'since' => $start,
            'viewer' => $accountId,
            'follower_main' => $accountId,
            'follower_atproto' => $accountId,
        ];
        $remoteParameters = [
            'remote_since' => $start,
            'remote_follower_main' => $accountId,
            'remote_follower_atproto' => $accountId,
        ];

        $local = $this->db->fetchAll(
            'SELECT id FROM social_post'
            . ' WHERE published_at > :since AND deleted_at IS NULL'
            . " AND visibility IN ('public', 'unlisted')"
            . ' AND (author_id = :viewer OR author_id IN (' . $followed . '))'
            . ' ORDER BY published_at ASC LIMIT ' . self::MAX_ITEMS,
            $localParameters,
        );

        $remote = $this->db->fetchAll(
            'SELECT activity_id FROM social_remote_post'
            . " WHERE published_at > :remote_since AND deleted_at IS NULL AND kind = 'post'"
            . ' AND actor_id IN ('
            . ' SELECT actor_id FROM social_following'
            . " WHERE local_user_id = :remote_follower_main AND state = 'accepted'"
            . ' UNION SELECT subject_did FROM ' . AtprotoFollowSchema::TABLE
            . " WHERE local_user_id = :remote_follower_atproto AND state = 'active')"
            . ' ORDER BY published_at ASC LIMIT ' . self::MAX_ITEMS,
            $remoteParameters,
        );

        $deletedLocal = $this->db->fetchAll(
            'SELECT id FROM social_post WHERE deleted_at > :since LIMIT ' . self::MAX_ITEMS,
            ['since' => $start],
        );
        $deletedRemote = $this->db->fetchAll(
            'SELECT activity_id FROM social_remote_post WHERE deleted_at > :since LIMIT ' . self::MAX_ITEMS,
            ['since' => $start],
        );

        return [
            'since' => $start,
            'cursor' => $cursor,
            'created' => array_values(array_filter(array_merge(
                array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $local),
                array_map(static fn (array $row): string => (string) ($row['activity_id'] ?? ''), $remote),
            ), static fn (string $id): bool => $id !== '')),
            'deleted' => array_values(array_filter(array_merge(
                array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $deletedLocal),
                array_map(static fn (array $row): string => (string) ($row['activity_id'] ?? ''), $deletedRemote),
            ), static fn (string $id): bool => $id !== '')),
        ];
    }
}
