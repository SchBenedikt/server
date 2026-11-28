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
use function str_starts_with;
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
     * Record that a reader is looking at these objects.
     *
     * Read paths must not wait on origin fetches, so a visible card only
     * queues its id; the worker drains the queue before the generic
     * newest-first scan on its next engagement tick. Rows are capped so a
     * pathological client cannot grow the table without bound.
     *
     * @param list<string> $objectIds
     */
    public function requestRefresh(array $objectIds): void
    {
        $now = Time::format($this->clock->now());
        $seen = [];
        foreach ($objectIds as $objectId) {
            $objectId = is_string($objectId) ? trim($objectId) : '';
            if ($objectId === '' || mb_strlen($objectId) > 512 || isset($seen[$objectId])
                || !str_starts_with($objectId, 'at://')
                && !str_starts_with($objectId, 'http://')
                && !str_starts_with($objectId, 'https://')
            ) {
                continue;
            }
            $seen[$objectId] = true;

            $this->db->execute(
                'INSERT INTO remote_engagement_request (object_id, requested_at)'
                . ' VALUES (:object_id, :requested_at)'
                . ' ON CONFLICT (object_id) DO UPDATE SET requested_at = excluded.requested_at',
                ['object_id' => $objectId, 'requested_at' => $now],
            );
        }

        if ($seen === []) {
            return;
        }

        // Keep the newest requests; anything older is superseded by the
        // regular stale scan anyway.
        $keep = $this->db->fetchAll(
            'SELECT object_id FROM remote_engagement_request'
            . ' ORDER BY requested_at DESC LIMIT 500',
        );
        if (count($keep) < 500) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($keep), '?'));
        $this->db->execute(
            'DELETE FROM remote_engagement_request WHERE object_id NOT IN (' . $placeholders . ')',
            array_column($keep, 'object_id'),
        );
    }

    private function pruneFailures(int $now): void
    {
        foreach ($this->failedAt as $id => $at) {
            if ($now - $at >= self::FAILURE_BACKOFF_SECONDS) {
                unset($this->failedAt[$id]);
            }
        }
    }

    /**
     * Fetch one origin's counters for an object and store them.
     *
     * @return bool true when fresh counts were stored; false when the
     * origin failed (recorded for backoff) or returned nothing usable
     */
    private function attempt(string $objectId, int $now): bool
    {
        try {
            $counts = $this->directory->remoteCounts($objectId);
        } catch (Throwable) {
            $counts = null;
        }

        if ($counts === null) {
            // Unknown or unreachable: back off instead of asking the same
            // dead origin every tick. Existing counts stay on the card.
            $this->failedAt[$objectId] = $now;
            return false;
        }

        $this->engagement->rememberCounts($objectId, $counts);
        return true;
    }

    /**
     * Reader-visible posts first, then the regular newest-first scan.
     *
     * @return int number of posts whose counters were stored
     */
    public function refresh(int $limit = 10): int
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $now = time();
        $this->pruneFailures($now);

        $refreshed = 0;
        $attempts = 0;

        // The request queue holds ids readers are looking at right now;
        // serving them first keeps visible counters fresh without remote
        // I/O on the request path.
        try {
            $pending = $this->db->fetchAll(
                'SELECT object_id FROM remote_engagement_request'
                . ' ORDER BY requested_at ASC LIMIT ' . $limit,
            );
        } catch (DatabaseException) {
            $pending = [];
        }

        if ($pending !== []) {
            $seen = [];
            foreach ($pending as $row) {
                if ($attempts >= $limit) {
                    break;
                }
                $objectId = is_string($row['object_id'] ?? null) ? trim($row['object_id']) : '';
                if ($objectId === '' || isset($seen[$objectId]) || isset($this->failedAt[$objectId])) {
                    continue;
                }
                $seen[$objectId] = true;
                $attempts++;
                if ($this->attempt($objectId, $now)) {
                    $refreshed++;
                }
            }

            // Rows are consumed either way: a failed origin is in backoff
            // and the generic scan below still has its own budget.
            $this->db->execute(
                'DELETE FROM remote_engagement_request WHERE object_id IN ('
                . implode(', ', array_fill(0, count($pending), '?')) . ')',
                array_map(
                    static fn (array $row): string => is_string($row['object_id'] ?? null) ? $row['object_id'] : '',
                    $pending,
                ),
            );
        }

        $remaining = $limit - $attempts;
        if ($remaining <= 0) {
            return $refreshed;
        }

        $stale = Time::format($this->clock->now()->modify(
            '-' . $this->engagement->ttlSeconds() . ' seconds',
        ));

        // More candidates than attempts, so a post that is still in backoff
        // does not starve the rest of the queue.
        $candidates = min(self::MAX_LIMIT * 3, $remaining + count($this->failedAt));
        try {
            $rows = $this->db->fetchAll(
                'SELECT post.object_id FROM social_remote_post post'
                . ' LEFT JOIN social_remote_engagement cache ON cache.object_id = post.object_id'
                . " WHERE post.deleted_at IS NULL AND post.kind = 'post'"
                . ' AND (cache.object_id IS NULL OR cache.fetched_at < :stale)'
                . " AND (post.object_id LIKE 'http://%'"
                . " OR post.object_id LIKE 'https://%'"
                . " OR post.object_id LIKE 'at://%')"
                . ' ORDER BY post.published_at DESC LIMIT ' . max($remaining, $candidates),
                ['stale' => $stale],
            );
        } catch (DatabaseException) {
            return $refreshed;
        }

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

            if ($this->attempt($objectId, $now)) {
                $refreshed++;
            }
        }

        return $refreshed;
    }
}
