<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use Throwable;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function parse_url;
use function strtolower;
use function trim;

/**
 * The display profile of remote actors seen in the federated timeline.
 *
 * A cached remote post carries only the actor URL. Rendering a card with a
 * display name and avatar would otherwise need one actor request per author
 * and page, which makes the feed depend on peer latency. This cache is filled
 * by the worker from the same guarded fetcher as every other remote read.
 *
 * It is display-only and never an authorization source: a missing or stale
 * row only means a card falls back to the handle derived from the actor URL.
 * Successful rows live for a week; a failed fetch backs off for six hours so
 * an unreachable peer cannot be retried on every worker tick.
 */
final class RemoteActorProfiles
{
    private const TTL_SECONDS = 604_800;

    private const FAILURE_BACKOFF_SECONDS = 21_600;

    private const MAX_ACTORS = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly RemoteDirectory $directory,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<string> $actorIds
     *
     * @return array<string, array{handle: ?string, display_name: ?string, avatar_url: ?string, fetched_at: string}>
     */
    public function profiles(array $actorIds): array
    {
        $actorIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? trim($id) : '', $actorIds),
            static fn (string $id): bool => $id !== '' && mb_strlen($id) <= 255,
        )));
        if ($actorIds === []) {
            return [];
        }

        $parameters = [];
        $placeholders = [];
        foreach (array_slice($actorIds, 0, self::MAX_ACTORS) as $index => $actorId) {
            $key = 'actor_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $actorId;
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT actor_id, handle, display_name, avatar_url, fetched_at'
                . ' FROM social_remote_actor_profile'
                . ' WHERE actor_id IN (' . implode(', ', $placeholders) . ')',
                $parameters,
            );
        } catch (Throwable) {
            // A missing table on an older deployment only loses the enriched
            // author; the timeline still renders its derived handle.
            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            $actorId = (string) ($row['actor_id'] ?? '');
            if ($actorId === '') {
                continue;
            }

            $profiles[$actorId] = [
                'handle' => $this->text($row['handle'] ?? null, 255),
                'display_name' => $this->text($row['display_name'] ?? null, 255),
                'avatar_url' => $this->avatarUrl($row['avatar_url'] ?? null),
                'fetched_at' => (string) ($row['fetched_at'] ?? ''),
            ];
        }

        return $profiles;
    }

    /**
     * Fetches the least recently cached authors of stored remote posts.
     *
     * The order is newest post first, so a reader scrolling the federated
     * timeline gets names and avatars for the cards they are most likely to
     * see. Authors that failed recently are skipped until their backoff ends.
     *
     * @return int how many profiles were refreshed
     */
    public function refreshStale(int $limit = 10): int
    {
        $limit = max(1, min(50, $limit));
        $current = $this->clock->now();
        $now = Time::format(Time::toSecondPrecision($current));
        $cutoff = Time::format($current->modify('-' . self::TTL_SECONDS . ' seconds'));
        $retry = Time::format($current->modify('-' . self::FAILURE_BACKOFF_SECONDS . ' seconds'));

        try {
            $rows = $this->db->fetchAll(
                'SELECT authors.author_id AS actor_id FROM ('
                . ' SELECT actor_id AS author_id, published_at AS newest FROM social_remote_post'
                . ' WHERE deleted_at IS NULL'
                . ' UNION ALL'
                . ' SELECT object_author_id AS author_id, published_at AS newest FROM social_remote_post'
                . ' WHERE deleted_at IS NULL AND object_author_id IS NOT NULL'
                . ') authors'
                . ' LEFT JOIN social_remote_actor_profile profile ON profile.actor_id = authors.author_id'
                . " WHERE authors.author_id LIKE 'http%'"
                . ' AND (profile.fetched_at IS NULL OR profile.fetched_at < :cutoff)'
                . ' AND (profile.failed_at IS NULL OR profile.failed_at < :retry)'
                . ' GROUP BY authors.author_id'
                . ' ORDER BY MAX(authors.newest) DESC'
                . ' LIMIT ' . $limit,
                ['cutoff' => $cutoff, 'retry' => $retry],
            );
        } catch (Throwable) {
            return 0;
        }

        $refreshed = 0;
        foreach ($rows as $row) {
            $actorId = trim((string) ($row['actor_id'] ?? ''));
            if ($actorId === '' || mb_strlen($actorId) > 255) {
                continue;
            }

            try {
                $profile = $this->directory->actorProfile($actorId);
                if ($profile === null) {
                    $this->recordFailure($actorId, $now);

                    continue;
                }

                $this->remember($actorId, $profile, $now);
                $refreshed++;
            } catch (Throwable) {
                $this->recordFailure($actorId, $now);
            }
        }

        return $refreshed;
    }

    /**
     * @param array<string, mixed> $profile an ActivityPub profile from RemoteDirectory
     */
    private function remember(string $actorId, array $profile, string $now): void
    {
        $handle = $this->text($profile['handle'] ?? null, 255);
        $displayName = $this->text($profile['display_name'] ?? null, 255);
        $avatarUrl = $this->avatarUrl($profile['avatar'] ?? null);

        if ($this->exists($actorId)) {
            $this->db->execute(
                'UPDATE social_remote_actor_profile SET handle = :handle, display_name = :display_name,'
                . ' avatar_url = :avatar_url, fetched_at = :fetched_at, failed_at = NULL, failures = 0'
                . ' WHERE actor_id = :actor_id',
                [
                    'handle' => $handle,
                    'display_name' => $displayName,
                    'avatar_url' => $avatarUrl,
                    'fetched_at' => $now,
                    'actor_id' => $actorId,
                ],
            );

            return;
        }

        $this->db->execute(
            'INSERT INTO social_remote_actor_profile'
            . ' (actor_id, handle, display_name, avatar_url, fetched_at, failed_at, failures)'
            . ' VALUES (:actor_id, :handle, :display_name, :avatar_url, :fetched_at, NULL, 0)',
            [
                'actor_id' => $actorId,
                'handle' => $handle,
                'display_name' => $displayName,
                'avatar_url' => $avatarUrl,
                'fetched_at' => $now,
            ],
        );
    }

    private function recordFailure(string $actorId, string $now): void
    {
        if ($this->exists($actorId)) {
            $this->db->execute(
                'UPDATE social_remote_actor_profile SET failed_at = :failed_at, failures = failures + 1'
                . ' WHERE actor_id = :actor_id',
                ['failed_at' => $now, 'actor_id' => $actorId],
            );

            return;
        }

        $this->db->execute(
            'INSERT INTO social_remote_actor_profile'
            . ' (actor_id, handle, display_name, avatar_url, fetched_at, failed_at, failures)'
            . ' VALUES (:actor_id, NULL, NULL, NULL, :fetched_at, :failed_at, 1)',
            ['actor_id' => $actorId, 'fetched_at' => $now, 'failed_at' => $now],
        );
    }

    private function exists(string $actorId): bool
    {
        try {
            return $this->db->fetchValue(
                'SELECT 1 FROM social_remote_actor_profile WHERE actor_id = :actor_id',
                ['actor_id' => $actorId],
            ) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function avatarUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 1024) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }
}
