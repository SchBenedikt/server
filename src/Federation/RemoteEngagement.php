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

use function array_filter;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function str_starts_with;
use function trim;

/**
 * The public engagement counters of a federated post.
 *
 * The local favourite and boost rows only describe this instance. A Mastodon
 * or Bluesky post carries its own counters, and the thread view already loads
 * them through RemoteDirectory. This service keeps those numbers for a short
 * time so feed cards show the real totals instead of zero, and so a second
 * card for the same post costs no request.
 *
 * Counts are read-only snapshots: they are never used for access decisions,
 * only for display. A stale snapshot is refreshed by the next post view.
 */
final class RemoteEngagement
{
    private const TTL_SECONDS = 900;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<string> $objectIds
     *
     * @return array<string, array{likes: int, boosts: int, replies: int, fetched_at: string}>
     */
    public function counts(array $objectIds): array
    {
        $objectIds = array_values(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? trim($id) : '', $objectIds),
            static fn (string $id): bool => $id !== '' && mb_strlen($id) <= 512,
        ));
        if ($objectIds === []) {
            return [];
        }

        $parameters = [];
        $placeholders = [];
        foreach ($objectIds as $index => $objectId) {
            $key = 'object_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $objectId;
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT object_id, likes, boosts, replies, fetched_at FROM social_remote_engagement'
                . ' WHERE object_id IN (' . implode(', ', $placeholders) . ')',
                $parameters,
            );
        } catch (DatabaseException) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $objectId = is_string($row['object_id'] ?? null) ? $row['object_id'] : '';
            if ($objectId === '') {
                continue;
            }
            $counts[$objectId] = [
                'likes' => max(0, (int) ($row['likes'] ?? 0)),
                'boosts' => max(0, (int) ($row['boosts'] ?? 0)),
                'replies' => max(0, (int) ($row['replies'] ?? 0)),
                'fetched_at' => (string) ($row['fetched_at'] ?? ''),
            ];
        }

        return $counts;
    }

    /**
     * Stores the counters of a freshly loaded remote thread.
     *
     * The caller passes the RemoteDirectory payload, so this class owns no
     * HTTP client and cannot make an unbounded request of its own.
     *
     * @param array<string, mixed> $payload
     */
    public function remember(string $objectId, array $payload): void
    {
        $objectId = trim($objectId);
        $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
        if ($objectId === '' || $post === []) {
            return;
        }

        $stamp = Time::format($this->clock->now());

        try {
            $this->db->execute(
                'INSERT INTO social_remote_engagement (object_id, likes, boosts, replies, fetched_at)'
                . ' VALUES (:object_id, :likes, :boosts, :replies, :fetched_at)'
                . ' ON CONFLICT (object_id) DO UPDATE SET'
                . ' likes = excluded.likes, boosts = excluded.boosts,'
                . ' replies = excluded.replies, fetched_at = excluded.fetched_at',
                [
                    'object_id' => $objectId,
                    'likes' => max(0, (int) ($post['favourites_count'] ?? 0)),
                    'boosts' => max(0, (int) ($post['reblogs_count'] ?? 0)),
                    'replies' => max(0, (int) ($post['replies_count'] ?? 0)),
                    'fetched_at' => $stamp,
                ],
            );
        } catch (DatabaseException) {
            // A display counter must never turn a readable post into an error.
        }
    }

    /**
     * Object ids that look federated and may therefore have origin counters.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function federatedIds(array $ids): array
    {
        $federated = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ($id === '' || mb_strlen($id) > 512) {
                continue;
            }
            if (str_starts_with($id, 'at://') || str_starts_with($id, 'http://') || str_starts_with($id, 'https://')) {
                $federated[] = $id;
            }
        }

        return $federated;
    }

    public function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
