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
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountNotConnectedException;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionService;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionRejectedException;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Server\Community\Preferences;

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
        private readonly string $instanceUrl,
        private readonly ?AtprotoInteractionService $atproto = null,
        private readonly ?Preferences $preferences = null,
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
     * @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int, atproto_mirror?: array{state: string, retryable: bool, reason?: string}}
     *
     * @throws PostNotFoundException when the post is missing or not visible
     */
    public function like(string $viewerId, string $postId, ?string $accountDid = null): array
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

        $mirror = $this->mirrorLike($viewerId, $post->id, true, $accountDid);

        $result = $this->forPosts([$post->id], $viewerId)[$post->id];
        if ($mirror !== null) {
            $result['atproto_mirror'] = $mirror;
        }

        return $result;
    }

    /**
     * @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int, atproto_mirror?: array{state: string, retryable: bool, reason?: string}}
     *
     * @throws PostNotFoundException when the post is missing or not visible
     */
    public function unlike(string $viewerId, string $postId, ?string $accountDid = null): array
    {
        $post = $this->posts->getVisible($postId, $viewerId);
        (new PdoFavouriteRepository($this->db))->remove($viewerId, $post->id);
        $mirror = $this->mirrorLike($viewerId, $post->id, false, $accountDid);

        $result = $this->forPosts([$post->id], $viewerId)[$post->id];
        if ($mirror !== null) {
            $result['atproto_mirror'] = $mirror;
        }

        return $result;
    }

    /**
     * Mirror a local favourite as an AT Protocol like when the post was
     * published there.
     *
     * The local favourite is the source of truth and must succeed even when
     * the PDS is down, the account has no ATProto identity or the post was
     * never published. The remote record is therefore best effort: a failure
     * is swallowed here and the next like attempt rewrites the same record key.
     */
    private function mirrorLike(string $viewerId, string $postId, bool $liked, ?string $accountDid = null): ?array
    {
        if ($this->preferences?->forAccount($viewerId)['mirror_likes_to_atproto'] !== true) {
            return null;
        }
        if ($this->atproto === null || !$this->atproto->isEnabled()) {
            return ['state' => 'unavailable', 'retryable' => false, 'reason' => 'atproto_disabled'];
        }

        try {
            $accountDid = $this->interactionAccountDid($viewerId, $accountDid);
            if ($liked) {
                $this->atproto->like($viewerId, $postId, $accountDid);
            } else {
                $this->atproto->unlike($viewerId, $postId, $accountDid);
            }
            return ['state' => $liked ? 'published' : 'removed', 'retryable' => false];
        } catch (AtprotoInteractionRejectedException $exception) {
            return ['state' => 'failed', 'retryable' => false, 'reason' => $exception->reason];
        } catch (AtprotoAccountNotConnectedException) {
            return ['state' => 'failed', 'retryable' => false, 'reason' => 'atproto_account_not_connected'];
        } catch (AtprotoPublishFailedException $exception) {
            return [
                'state' => 'failed',
                'retryable' => $this->retryablePdsStatus($exception->status),
                'reason' => 'atproto_publish_failed',
            ];
        } catch (\Throwable) {
            return ['state' => 'failed', 'retryable' => false, 'reason' => 'atproto_interaction_failed'];
        }
    }

    /**
     * Mirror a local boost only after the account has opted in.
     */
    public function mirrorBoost(string $viewerId, string $postId, bool $boosted, ?string $accountDid = null): ?array
    {
        if ($this->preferences?->forAccount($viewerId)['mirror_boosts_to_atproto'] !== true) {
            return null;
        }
        if ($this->atproto === null || !$this->atproto->isEnabled()) {
            return ['state' => 'unavailable', 'retryable' => false, 'reason' => 'atproto_disabled'];
        }

        try {
            $accountDid = $this->interactionAccountDid($viewerId, $accountDid);
            if ($boosted) {
                $this->atproto->repost($viewerId, $postId, $accountDid);
            } else {
                $this->atproto->undoRepost($viewerId, $postId, $accountDid);
            }
            return ['state' => $boosted ? 'published' : 'removed', 'retryable' => false];
        } catch (AtprotoInteractionRejectedException $exception) {
            return ['state' => 'failed', 'retryable' => false, 'reason' => $exception->reason];
        } catch (AtprotoAccountNotConnectedException) {
            return ['state' => 'failed', 'retryable' => false, 'reason' => 'atproto_account_not_connected'];
        } catch (AtprotoPublishFailedException $exception) {
            return [
                'state' => 'failed',
                'retryable' => $this->retryablePdsStatus($exception->status),
                'reason' => 'atproto_publish_failed',
            ];
        } catch (\Throwable) {
            return ['state' => 'failed', 'retryable' => false, 'reason' => 'atproto_interaction_failed'];
        }
    }

    private function retryablePdsStatus(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 425 || $status === 429 || $status >= 500;
    }

    private function interactionAccountDid(string $viewerId, ?string $accountDid): ?string
    {
        if ($accountDid !== null) {
            return $accountDid;
        }

        $selected = $this->preferences?->forAccount($viewerId)['mirror_atproto_account_did'] ?? null;

        return is_string($selected) && $selected !== '' ? $selected : null;
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

        foreach ($this->remoteReplyCounts($postIds) as $postId => $remote) {
            if (isset($counts[$postId])) {
                $counts[$postId] += $remote;
            }
        }

        return $counts;
    }

    /**
     * Count ActivityPub replies received by this instance.
     *
     * The core's RemotePost table has no reply parent column, so the host
     * keeps the edge in its own table. A remote reply to a followers-only post
     * is never counted for the public card: the host cannot tell which local
     * viewer may read the remote author's object, and a public count would leak
     * the existence of private conversation. Local replies keep their existing
     * visibility-aware count.
     *
     * @param list<string> $postIds
     *
     * @return array<string, int>
     */
    private function remoteReplyCounts(array $postIds): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($postIds as $index => $postId) {
            $key = 'remote_reply_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $postId;
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT reply.in_reply_to_id, COUNT(DISTINCT reply.object_id) AS total'
                . ' FROM social_remote_reply reply'
                . ' INNER JOIN ' . PostSchema::TABLE . ' parent ON parent.id = reply.in_reply_to_id'
                . ' WHERE reply.in_reply_to_id IN (' . implode(', ', $placeholders) . ')'
                . " AND parent.deleted_at IS NULL AND parent.visibility IN ('public', 'unlisted')"
                . ' GROUP BY reply.in_reply_to_id',
                $parameters,
            );
        } catch (DatabaseException) {
            // An installation that has not run the host migration yet must
            // still serve its existing interaction counters.
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $postId = is_string($row['in_reply_to_id'] ?? null) ? $row['in_reply_to_id'] : '';
            if ($postId !== '') {
                $counts[$postId] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Record a successfully accepted inbox activity that creates or deletes a
     * reply to one of this instance's public posts.
     *
     * This is called only after the core's signature, follow-gate and replay
     * checks accepted the activity. A malformed document or a failed insert is
     * ignored: the reply count is a projection and must never turn an accepted
     * federation delivery into an error response for the peer.
     */
    public function recordInboundActivity(string $body): void
    {
        try {
            $activity = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!is_array($activity)) {
            return;
        }

        $type = is_string($activity['type'] ?? null) ? $activity['type'] : '';
        $object = $activity['object'] ?? null;

        if ($type === 'Create' && is_array($object)) {
            $this->storeInboundReply($activity, $object);

            return;
        }

        if ($type !== 'Delete') {
            return;
        }

        $objectId = is_string($object)
            ? trim($object)
            : (is_array($object) && is_string($object['id'] ?? null) ? trim($object['id']) : '');
        if ($objectId === '') {
            return;
        }

        try {
            $this->db->execute(
                'DELETE FROM social_remote_reply WHERE object_id = :object_id',
                ['object_id' => $objectId],
            );
        } catch (DatabaseException) {
            // The reply index is optional for an old database.
        }
    }

    /**
     * @param array<string, mixed> $activity
     * @param array<string, mixed> $object
     */
    private function storeInboundReply(array $activity, array $object): void
    {
        $activityId = is_string($activity['id'] ?? null) ? trim($activity['id']) : '';
        $objectId = is_string($object['id'] ?? null) ? trim($object['id']) : '';
        $actorId = is_string($object['attributedTo'] ?? null)
            ? trim($object['attributedTo'])
            : (is_string($activity['actor'] ?? null) ? trim($activity['actor']) : '');

        $inReplyTo = $object['inReplyTo'] ?? null;
        if (is_array($inReplyTo)) {
            $inReplyTo = $inReplyTo['id'] ?? null;
        }

        $parentId = is_string($inReplyTo) ? $this->localPostId($inReplyTo) : null;
        if ($activityId === '' || $objectId === '' || $actorId === '' || $parentId === null) {
            return;
        }

        try {
            $parent = $this->posts->getVisible($parentId, null);
        } catch (PostNotFoundException) {
            return;
        }

        if (!in_array($parent->visibility->value, ['public', 'unlisted'], true)) {
            return;
        }

        try {
            if ((int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM social_remote_reply WHERE object_id = :object_id',
                ['object_id' => $objectId],
            ) > 0) {
                return;
            }

            $this->db->execute(
                'INSERT INTO social_remote_reply'
                . ' (activity_id, object_id, in_reply_to_id, actor_id, created_at)'
                . ' VALUES (:activity_id, :object_id, :in_reply_to_id, :actor_id, :created_at)',
                [
                    'activity_id' => $activityId,
                    'object_id' => $objectId,
                    'in_reply_to_id' => $parentId,
                    'actor_id' => $actorId,
                    'created_at' => \AlohaSocial\Core\Support\Time::format($this->clock->now()),
                ],
            );
        } catch (DatabaseException) {
            // A concurrent duplicate Create is harmless; the object id is the
            // identity and the existing row already represents this reply.
        }
    }

    private function localPostId(string $objectId): ?string
    {
        $prefix = rtrim($this->instanceUrl, '/') . '/api/v1/posts/';
        if (!str_starts_with($objectId, $prefix)) {
            return null;
        }

        $encoded = substr($objectId, strlen($prefix));
        if ($encoded === '' || str_contains($encoded, '/')) {
            return null;
        }

        $postId = rawurldecode($encoded);

        return $postId !== '' && mb_strlen($postId) <= 64 ? $postId : null;
    }
}
