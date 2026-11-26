<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Interaction\PdoReactionRepository;
use AlohaSocial\Core\Domain\Interaction\Reaction;
use AlohaSocial\Core\Domain\Interaction\ReactionDirection;
use AlohaSocial\Core\Domain\Interaction\ReactionKind;
use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Core\Service\ReactionService;
use AlohaSocial\Core\Support\Time;

use function is_string;
use function max;
use function trim;

/**
 * Local likes and boosts on cached federated posts.
 *
 * The core's reaction service requires an accepted follow so that it never
 * writes to an inbox it has not stored. That is a good rule for a reply or a
 * DM, but a public post may be liked from discovery without following its
 * author. This host keeps the same signed ActivityPub Like/Announce and the
 * same core reaction rows, and only resolves the actor inbox when there is no
 * stored follow edge.
 */
final class RemoteReactions
{
    private readonly PdoReactionRepository $reactions;

    public function __construct(
        private readonly Connection $db,
        private readonly ?ReactionService $snapshots,
        private readonly ActivityFactory $activities,
        private readonly JobQueue $queue,
        private readonly RemoteDirectory $directory,
        private readonly Clock $clock,
        private readonly ?RemotePostImporter $importer = null,
    ) {
        $this->reactions = new PdoReactionRepository($db);
    }

    /**
     * The viewer's state on a cached post, without requiring a follow.
     *
     * @return array<string, mixed>
     *
     * @throws ReactionException
     */
    public function snapshotFor(string $userId, string $objectId): array
    {
        return $this->snapshot($userId, $this->post($userId, $objectId));
    }

    /** @return array<string, mixed> */
    public function like(string $userId, string $objectId): array
    {
        $post = $this->post($userId, $objectId);
        $existing = $this->reactions->find(
            ReactionDirection::Out,
            $userId,
            $post->objectId,
            ReactionKind::Like,
            null,
        );
        $inbox = $existing === null ? $this->inboxFor($userId, $post->actorId) : null;
        $reaction = new Reaction(
            direction: ReactionDirection::Out,
            subjectId: $userId,
            objectId: $post->objectId,
            localPostId: null,
            kind: ReactionKind::Like,
            emoji: null,
            iconUrl: null,
            activityId: $this->activities->likeId($userId, $post->objectId),
            createdAt: Time::toSecondPrecision($this->clock->now()),
        );

        if ($this->insert($reaction) && $inbox !== null) {
            $this->enqueue($userId, $inbox, $this->activities->like($userId, $post->objectId));
        }

        return $this->snapshot($userId, $post);
    }

    /** @return array<string, mixed> */
    public function unlike(string $userId, string $objectId): array
    {
        $post = $this->post($userId, $objectId);
        $stored = $this->reactions->find(
            ReactionDirection::Out,
            $userId,
            $post->objectId,
            ReactionKind::Like,
            null,
        );
        // Resolve the inbox before removing the row: a failed Undo must leave
        // the local like in place so a retry can still reach the peer.
        $inbox = $stored !== null ? $this->inboxFor($userId, $post->actorId) : null;
        $removed = $this->reactions->remove(
            ReactionDirection::Out,
            $userId,
            $post->objectId,
            ReactionKind::Like,
            null,
        );

        if ($removed && $inbox !== null) {
            $this->enqueue($userId, $inbox, $this->activities->undoLike($userId, $post->objectId));
        }

        return $this->snapshot($userId, $post, $stored?->activityId);
    }

    /** @return array<string, mixed> */
    public function boost(string $userId, string $objectId): array
    {
        $post = $this->post($userId, $objectId);
        $existing = $this->reactions->find(
            ReactionDirection::Out,
            $userId,
            $post->objectId,
            ReactionKind::Boost,
            null,
        );
        $inbox = $existing === null ? $this->inboxFor($userId, $post->actorId) : null;
        $createdAt = Time::toSecondPrecision($this->clock->now());
        $activityId = $this->activities->remoteBoostId($userId, $post->objectId, $createdAt);
        $reaction = new Reaction(
            direction: ReactionDirection::Out,
            subjectId: $userId,
            objectId: $post->objectId,
            localPostId: null,
            kind: ReactionKind::Boost,
            emoji: null,
            iconUrl: null,
            activityId: $activityId,
            createdAt: $createdAt,
        );

        if ($this->insert($reaction) && $inbox !== null) {
            $this->enqueue(
                $userId,
                $inbox,
                $this->activities->announceRemote($userId, $post->objectId, $activityId),
            );
        }

        return $this->snapshot($userId, $post);
    }

    /** @return array<string, mixed> */
    public function unboost(string $userId, string $objectId): array
    {
        $post = $this->post($userId, $objectId);
        $stored = $this->reactions->find(
            ReactionDirection::Out,
            $userId,
            $post->objectId,
            ReactionKind::Boost,
            null,
        );
        $inbox = $stored !== null ? $this->inboxFor($userId, $post->actorId) : null;
        $removed = $this->reactions->remove(
            ReactionDirection::Out,
            $userId,
            $post->objectId,
            ReactionKind::Boost,
            null,
        );

        if ($removed && $inbox !== null) {
            $this->enqueue(
                $userId,
                $inbox,
                $this->activities->undoRemoteAnnounce($userId, $post->objectId, $stored?->activityId),
            );
        }

        return $this->snapshot($userId, $post);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReactionException
     */
    private function snapshot(string $userId, RemotePost $post, ?string $previousActivityId = null): array
    {
        if ($this->snapshots === null) {
            return [
                'favourites_count' => 0,
                'favourited' => false,
                'reblogs_count' => 0,
                'reblogged' => false,
                'replies_count' => 0,
                'emoji_reactions' => [],
            ];
        }

        $snapshot = $this->snapshots->remoteInteractionsFor($userId, $post);
        if ($previousActivityId !== null) {
            $snapshot['previous_activity_id'] = $previousActivityId;
        }

        return $snapshot;
    }

    /**
     * @throws ReactionException
     */
    private function post(string $userId, string $objectId): RemotePost
    {
        $objectId = trim($objectId);
        if ($objectId === '') {
            throw new ReactionException('remote_post_not_found', 'Remote post not found.');
        }

        $posts = new PdoRemotePostRepository($this->db);
        $post = $posts->findPostByObjectId($objectId);
        if (($post === null || $post->deletedAt !== null) && $this->importer !== null) {
            // A post discovered through search or a profile is not in the
            // inbound cache yet. Import it once so a like or boost has the
            // same target an inbound Create would have stored.
            $post = $this->importer->ensure($objectId);
        }
        if ($post === null || $post->deletedAt !== null) {
            throw new ReactionException('remote_post_not_found', 'Remote post not found.');
        }

        // A block is decided locally and the activity is never sent. The post
        // stays readable only through an explicit block filter, so refusing the
        // reaction is the least surprising answer.
        try {
            $blocked = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM social_block WHERE local_user_id = :user AND actor_id = :actor',
                ['user' => $userId, 'actor' => $post->actorId],
            );
        } catch (DatabaseException) {
            $blocked = 0;
        }
        if ($blocked > 0) {
            throw new ReactionException('blocked_actor', 'This actor is blocked.');
        }

        return $post;
    }

    private function insert(Reaction $reaction): bool
    {
        try {
            $this->reactions->insert($reaction);

            return true;
        } catch (DatabaseException $exception) {
            $existing = $this->reactions->find(
                ReactionDirection::Out,
                $reaction->subjectId,
                $reaction->objectId,
                $reaction->kind,
                $reaction->emoji,
            );
            if ($existing === null) {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * Resolves the delivery target before the local reaction is written.
     *
     * A stored follow edge wins; otherwise the actor document is read with a
     * signed GET. A refusal is surfaced to the caller instead of returning
     * success for a reaction the peer will never see.
     */
    private function inboxFor(string $userId, string $actorId): string
    {
        $inbox = $this->storedInbox($userId, $actorId);
        if ($inbox !== null) {
            return $inbox;
        }

        try {
            return $this->directory->resolveActorInbox($actorId);
        } catch (RemoteDirectoryException $exception) {
            throw new ReactionException('remote_delivery_unreachable', $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function enqueue(string $userId, string $inbox, array $activity): void
    {
        $this->queue->push(JobType::FEDERATION_DELIVER, [
            'inbox' => $inbox,
            'activity' => $activity,
            'key_id' => $this->activities->keyId($userId),
        ], subject: JobSubject::account($userId));
    }

    private function storedInbox(string $userId, string $actorId): ?string
    {
        try {
            $inbox = $this->db->fetchValue(
                'SELECT inbox_url FROM social_following'
                . ' WHERE local_user_id = :user AND actor_id = :actor AND state = :state'
                . ' LIMIT 1',
                ['user' => $userId, 'actor' => $actorId, 'state' => 'accepted'],
            );
        } catch (DatabaseException) {
            return null;
        }

        return is_string($inbox) && trim($inbox) !== '' ? trim($inbox) : null;
    }
}
