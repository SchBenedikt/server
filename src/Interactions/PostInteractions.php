<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Interactions;

use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Domain\Post\Favourite;
use AlohaSocial\Core\Domain\Post\PdoBoostRepository;
use AlohaSocial\Core\Domain\Post\PdoFavouriteRepository;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\PostService;

/**
 * Read and write the viewer-specific interaction layer for one or more posts.
 *
 * The core owns the favourite and boost rows but its timeline projection is
 * deliberately anonymous. The shared UI needs the counts and whether the
 * signed-in account already reacted, so this host adapter reads the same
 * tables without introducing a second social model.
 */
final class PostInteractions
{
    public function __construct(
        private readonly PostService $posts,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param list<string> $postIds
     *
     * @return array<string, array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int}>
     */
    public function forPosts(array $postIds, ?string $viewerId): array
    {
        $postIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? trim($id) : '', $postIds),
            static fn (string $id): bool => $id !== '' && mb_strlen($id) <= 64,
        )));

        if ($postIds === []) {
            return [];
        }

        $favourites = new PdoFavouriteRepository($this->db);
        $boosts = new PdoBoostRepository($this->db);
        $favouriteCounts = $favourites->countsForPosts($postIds);

        // A remote Like is stored in the core's reaction table, not in the
        // local favourite table. Both are one like from the reader's point of
        // view, so the card counter has to add them together.
        $remoteLikeCounts = $this->remoteLikeCounts($postIds);
        foreach ($remoteLikeCounts as $postId => $count) {
            $favouriteCounts[$postId] = ($favouriteCounts[$postId] ?? 0) + $count;
        }
        $boostCounts = $boosts->countsForPosts($postIds);
        $favourited = $viewerId === null ? [] : $favourites->existsForPosts($viewerId, $postIds);
        $reblogged = $viewerId === null ? [] : $boosts->existsForPosts($viewerId, $postIds);
        $replyCounts = $this->replyCounts($postIds, $viewerId);
        $emoji = $this->emojiReactions($postIds);

        $result = [];
        foreach ($postIds as $postId) {
            $result[$postId] = [
                'favourites_count' => $favouriteCounts[$postId] ?? 0,
                'favourited' => $favourited[$postId] ?? false,
                'reblogs_count' => $boostCounts[$postId] ?? 0,
                'reblogged' => $reblogged[$postId] ?? false,
                'replies_count' => $replyCounts[$postId] ?? 0,
                'emoji_reactions' => $emoji[$postId] ?? [],
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $postIds
     *
     * @return array<string, int>
     */
    private function remoteLikeCounts(array $postIds): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($postIds as $index => $postId) {
            $key = 'like_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $postId;
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT local_post_id, COUNT(*) AS total FROM ' . ReactionSchema::TABLE
                . " WHERE kind = 'like' AND direction = 'in' AND local_post_id IN (" . implode(', ', $placeholders) . ')'
                . ' GROUP BY local_post_id',
                $parameters,
            );
        } catch (DatabaseException) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $postId = is_string($row['local_post_id'] ?? null) ? $row['local_post_id'] : '';
            if ($postId !== '') {
                $counts[$postId] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Inbound emoji reactions per post, as `[{emoji, count}]`.
     *
     * The table is the core's canonical reaction store. Reading it here keeps
     * the projection in one place instead of making the UI call another route
     * for every card.
     *
     * @param list<string> $postIds
     *
     * @return array<string, list<array{emoji: string, count: int}>>
     */
    private function emojiReactions(array $postIds): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($postIds as $index => $postId) {
            $key = 'emoji_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $postId;
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT local_post_id, emoji, COUNT(*) AS total FROM ' . ReactionSchema::TABLE
                . " WHERE kind = 'emoji' AND direction = 'in' AND local_post_id IN (" . implode(', ', $placeholders) . ')'
                . ' GROUP BY local_post_id, emoji ORDER BY total DESC, emoji ASC',
                $parameters,
            );
        } catch (DatabaseException) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $postId = is_string($row['local_post_id'] ?? null) ? $row['local_post_id'] : '';
            $value = is_string($row['emoji'] ?? null) ? $row['emoji'] : '';
            if ($postId === '' || $value === '') {
                continue;
            }
            $result[$postId][] = ['emoji' => $value, 'count' => (int) ($row['total'] ?? 0)];
        }

        return $result;
    }

    /**
     * Add the viewer's like. Repeating it is idempotent; the owner receives a
     * local notification the first time the edge is stored.
     *
     * @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int}
     *
     * @throws PostNotFoundException when the post is missing or not visible
     */
    public function like(string $viewerId, string $postId): array
    {
        $post = $this->posts->getVisible($postId, $viewerId);
        $favourites = new PdoFavouriteRepository($this->db);

        if (!$favourites->exists($viewerId, $post->id)) {
            try {
                $favourites->insert(new Favourite(
                    userId: $viewerId,
                    postId: $post->id,
                    createdAt: $this->clock->now(),
                ));
            } catch (DatabaseException $exception) {
                if (!$favourites->exists($viewerId, $post->id)) {
                    throw $exception;
                }
            }

            $this->notifications->recordFavourite($post->authorId, $viewerId, $post->id);
        }

        return $this->forPosts([$post->id], $viewerId)[$post->id];
    }

    /**
     * @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int}
     *
     * @throws PostNotFoundException when the post is missing or not visible
     */
    public function unlike(string $viewerId, string $postId): array
    {
        $post = $this->posts->getVisible($postId, $viewerId);
        (new PdoFavouriteRepository($this->db))->remove($viewerId, $post->id);

        return $this->forPosts([$post->id], $viewerId)[$post->id];
    }

    /**
     * Only replies the viewer may read contribute to the count. A public
     * reply is readable by anybody; an unlisted reply is readable by link; a
     * follower-only or direct reply is not counted for an anonymous caller.
     * The author always sees their own replies.
     *
     * @param list<string> $postIds
     *
     * @return array<string, int>
     */
    private function replyCounts(array $postIds, ?string $viewerId): array
    {
        $placeholders = [];
        $parameters = ['viewer' => $viewerId ?? ''];
        foreach ($postIds as $index => $postId) {
            $key = 'reply_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $postId;
        }

        $rows = $this->db->fetchAll(
            'SELECT in_reply_to_id, COUNT(*) AS total FROM ' . PostSchema::TABLE
            . ' WHERE in_reply_to_id IN (' . implode(', ', $placeholders) . ')'
            . " AND deleted_at IS NULL AND (visibility IN ('public', 'unlisted') OR author_id = :viewer)"
            . ' GROUP BY in_reply_to_id',
            $parameters,
        );

        $counts = array_fill_keys($postIds, 0);
        foreach ($rows as $row) {
            $postId = (string) $row['in_reply_to_id'];
            if (isset($counts[$postId])) {
                $counts[$postId] = (int) $row['total'];
            }
        }

        return $counts;
    }
}
