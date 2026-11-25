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

use function array_filter;
use function array_map;
use function array_merge;
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

    private readonly SocialGraph $graph;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        ?SocialGraph $graph = null,
    ) {
        $this->graph = $graph ?? new SocialGraph($db);
    }

    /**
     * @return array{
     *     since: string,
     *     cursor: string,
     *     created: list<string>,
     *     deleted: list<string>,
     *     engagement: list<array{id: string, object_id: string, likes_count: int, boosts_count: int, replies_count: int}>
     * }
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

        $localFollowed = $this->graph->localFollowingSubquery($accountId, 'actor_id', 'live_local');

        // Each statement gets exactly its own parameters: PDO rejects a
        // parameter set that names placeholders the statement does not use.
        $localParameters = array_merge([
            'since' => $start,
            'viewer' => $accountId,
        ], $localFollowed['params']);
        $remoteParameters = [
            'remote_since' => $start,
            'remote_follower_main' => $accountId,
            'remote_follower_atproto' => $accountId,
        ];

        $local = $this->db->fetchAll(
            'SELECT id FROM social_post'
            . ' WHERE published_at > :since AND deleted_at IS NULL'
            . " AND visibility IN ('public', 'unlisted')"
            . ' AND (author_id = :viewer OR author_id IN (' . $localFollowed['sql'] . '))'
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
            'SELECT id FROM social_post WHERE deleted_at > :since'
            . ' AND (author_id = :viewer OR author_id IN (' . $localFollowed['sql'] . '))'
            . ' LIMIT ' . self::MAX_ITEMS,
            array_merge(['since' => $start, 'viewer' => $accountId], $localFollowed['params']),
        );
        $deletedRemote = $this->db->fetchAll(
            'SELECT activity_id FROM social_remote_post WHERE deleted_at > :since LIMIT ' . self::MAX_ITEMS,
            ['since' => $start],
        );

        // A counter refresh changes no post row, so the page would otherwise
        // keep showing the numbers from its first load until a reload. The
        // cache timestamp is the change marker; only followed actors are
        // included, exactly like the created list above.
        $engagement = $this->db->fetchAll(
            'SELECT remote.activity_id, cache.object_id, cache.likes, cache.boosts, cache.replies'
            . ' FROM social_remote_engagement cache'
            . ' INNER JOIN social_remote_post remote ON remote.object_id = cache.object_id'
            . ' WHERE cache.fetched_at > :engagement_since AND remote.deleted_at IS NULL'
            . ' AND remote.actor_id IN ('
            . ' SELECT actor_id FROM social_following'
            . " WHERE local_user_id = :engagement_follower_main AND state = 'accepted'"
            . ' UNION SELECT subject_did FROM ' . AtprotoFollowSchema::TABLE
            . " WHERE local_user_id = :engagement_follower_atproto AND state = 'active')"
            . ' ORDER BY cache.fetched_at ASC LIMIT ' . self::MAX_ITEMS,
            [
                'engagement_since' => $start,
                'engagement_follower_main' => $accountId,
                'engagement_follower_atproto' => $accountId,
            ],
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
            'engagement' => array_values(array_filter(array_map(
                static fn (array $row): array => [
                    'id' => (string) ($row['activity_id'] ?? ''),
                    'object_id' => (string) ($row['object_id'] ?? ''),
                    'likes_count' => max(0, (int) ($row['likes'] ?? 0)),
                    'boosts_count' => max(0, (int) ($row['boosts'] ?? 0)),
                    'replies_count' => max(0, (int) ($row['replies'] ?? 0)),
                ],
                $engagement,
            ), static fn (array $entry): bool => $entry['id'] !== '' && $entry['object_id'] !== '')),
        ];
    }
}
