<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use Throwable;

use function array_unique;
use function is_array;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Refreshes the public counters of cached federated posts.
 *
 * Feed cards can only show a like, boost and reply total that somebody has
 * already fetched. Opening a thread fills the cache for that one post, which
 * left every other remote card at zero. The worker therefore walks the newest
 * followed posts whose snapshot is missing or older than the TTL and asks the
 * origin once. The per-run limit and the swallowed per-post failure keep a
 * slow or hostile origin from stalling the queue.
 */
final class RemoteEngagementRefresher
{
    private const MAX_LIMIT = 25;

    public function __construct(
        private readonly Connection $db,
        private readonly RemoteEngagement $engagement,
        private readonly RemoteDirectory $directory,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return int number of posts whose counters were stored
     */
    public function refresh(int $limit = 10): int
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $stale = Time::format($this->clock->now()->modify(
            '-' . $this->engagement->ttlSeconds() . ' seconds',
        ));

        try {
            $rows = $this->db->fetchAll(
                'SELECT post.object_id FROM social_remote_post post'
                . ' LEFT JOIN social_remote_engagement cache ON cache.object_id = post.object_id'
                . " WHERE post.deleted_at IS NULL AND post.kind = 'post'"
                . ' AND (cache.object_id IS NULL OR cache.fetched_at < :stale)'
                . " AND (post.object_id LIKE 'http://%'"
                . " OR post.object_id LIKE 'https://%'"
                . " OR post.object_id LIKE 'at://%')"
                . ' ORDER BY post.published_at DESC LIMIT ' . $limit,
                ['stale' => $stale],
            );
        } catch (DatabaseException) {
            return 0;
        }

        $refreshed = 0;
        $seen = [];
        foreach ($rows as $row) {
            $objectId = is_string($row['object_id'] ?? null) ? trim($row['object_id']) : '';
            if ($objectId === '' || isset($seen[$objectId])) {
                continue;
            }
            $seen[$objectId] = true;

            try {
                $thread = $this->directory->remotePost($objectId);
            } catch (Throwable) {
                // A removed, private or unreachable post keeps its old
                // snapshot; the next tick retries with the next candidates.
                continue;
            }

            if (!is_array($thread['post'] ?? null)) {
                continue;
            }

            $this->engagement->remember($objectId, $thread);
            $refreshed++;
        }

        return $refreshed;
    }
}
