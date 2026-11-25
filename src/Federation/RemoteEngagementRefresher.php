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

use function count;
use function is_string;
use function max;
use function min;
use function time;
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

    /** Origins that failed recently are retried at most every ten minutes. */
    private const FAILURE_BACKOFF_SECONDS = 600;

    /** @var array<string, int> object id to the last failed attempt */
    private array $failedAt = [];

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

        $now = time();
        foreach ($this->failedAt as $id => $at) {
            if ($now - $at >= self::FAILURE_BACKOFF_SECONDS) {
                unset($this->failedAt[$id]);
            }
        }

        // More candidates than attempts, so a post that is still in backoff
        // does not starve the rest of the queue.
        $candidates = min(self::MAX_LIMIT * 3, $limit + count($this->failedAt));
        try {
            $rows = $this->db->fetchAll(
                'SELECT post.object_id FROM social_remote_post post'
                . ' LEFT JOIN social_remote_engagement cache ON cache.object_id = post.object_id'
                . " WHERE post.deleted_at IS NULL AND post.kind = 'post'"
                . ' AND (cache.object_id IS NULL OR cache.fetched_at < :stale)'
                . " AND (post.object_id LIKE 'http://%'"
                . " OR post.object_id LIKE 'https://%'"
                . " OR post.object_id LIKE 'at://%')"
                . ' ORDER BY post.published_at DESC LIMIT ' . max($limit, $candidates),
                ['stale' => $stale],
            );
        } catch (DatabaseException) {
            return 0;
        }

        $refreshed = 0;
        $attempts = 0;
        $seen = [];
        foreach ($rows as $row) {
            if ($attempts >= $limit) {
                break;
            }

            $objectId = is_string($row['object_id'] ?? null) ? trim($row['object_id']) : '';
            if ($objectId === '' || isset($seen[$objectId]) || isset($this->failedAt[$objectId])) {
                continue;
            }
            $seen[$objectId] = true;
            $attempts++;

            try {
                $counts = $this->directory->remoteCounts($objectId);
            } catch (Throwable) {
                $counts = null;
            }

            if ($counts === null) {
                // Unknown or unreachable: back off instead of asking the same
                // dead origin every tick. Existing counts stay on the card.
                $this->failedAt[$objectId] = $now;
                continue;
            }

            $this->engagement->rememberCounts($objectId, $counts);
            $refreshed++;
        }

        return $refreshed;
    }
}
