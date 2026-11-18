<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Service\FollowRequestNotFoundException;
use AlohaSocial\Core\Service\FollowRequestService;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Support\Time;

use function array_map;
use function bin2hex;
use function is_string;
use function random_bytes;
use function trim;

/**
 * Pending follows waiting for a protected account's approval.
 *
 * A request is not an edge: the core only learns about a follow once the owner
 * accepts. That keeps every existing follower count, timeline and collection
 * reading the same graph it always read, and makes a rejected request leave no
 * trace in the social graph at all.
 *
 * The request records the follower's actor URL and, for a local account, its
 * handle. Remote requests live in the core's follower table because the core
 * has the original signed Follow activity, which is required for a valid
 * Accept or Reject. Both sources are presented through one host route.
 */
final class FollowRequests
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly FollowerService $followers,
        private readonly ActivityFactory $activities,
        private readonly ?NotificationService $notifications = null,
        private readonly ?FollowRequestService $remoteRequests = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAccount(string $targetAccountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM follow_request WHERE target_account_id = :target'
            . ' ORDER BY created_at DESC, id DESC LIMIT 200',
            ['target' => $targetAccountId],
        );

        $requests = array_map([$this, 'hydrate'], $rows);

        if ($this->remoteRequests !== null) {
            foreach ($this->remoteRequests->pending($targetAccountId, FollowRequestService::MAX_LIMIT) as $follower) {
                $requests[] = [
                    'id' => $follower->id,
                    'target_account_id' => $follower->localUserId,
                    'actor_id' => $follower->actorId,
                    'actor_handle' => $follower->actorId,
                    'is_local' => false,
                    'created_at' => $follower->followedAt->format(DATE_ATOM),
                ];
            }
        }

        usort($requests, static fn (array $a, array $b): int => (string) $b['created_at'] <=> (string) $a['created_at']);

        return $requests;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $targetAccountId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM follow_request WHERE id = :id AND target_account_id = :target',
            ['id' => $id, 'target' => $targetAccountId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function hasPending(string $targetAccountId, string $actorId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM follow_request WHERE target_account_id = :target AND actor_id = :actor',
            ['target' => $targetAccountId, 'actor' => $actorId],
        );

        return $row !== null;
    }

    /**
     * Records (or refreshes) a pending request from one local account.
     *
     * @return array<string, mixed>
     */
    public function requestFromLocal(string $targetAccountId, string $followerId): array
    {
        $targetAccountId = trim($targetAccountId);
        $followerId = trim($followerId);
        if ($targetAccountId === '' || $followerId === '') {
            throw CommunityException::invalid('The request is incomplete.');
        }
        if ($targetAccountId === $followerId) {
            throw CommunityException::invalid('You cannot follow yourself.');
        }

        $actorId = $this->activities->actorId($followerId);
        $existing = $this->db->fetchOne(
            'SELECT * FROM follow_request WHERE target_account_id = :target AND actor_id = :actor',
            ['target' => $targetAccountId, 'actor' => $actorId],
        );
        if ($existing !== null) {
            return $this->hydrate($existing);
        }

        $id = bin2hex(random_bytes(12));
        $createdAt = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO follow_request (id, target_account_id, actor_id, actor_handle, is_local, created_at)'
            . ' VALUES (:id, :target, :actor, :handle, 1, :created_at)',
            [
                'id' => $id,
                'target' => $targetAccountId,
                'actor' => $actorId,
                'handle' => $followerId,
                'created_at' => $createdAt,
            ],
        );

        return $this->find($id, $targetAccountId)
            ?? throw CommunityException::notFound('The request was not saved.');
    }

    /**
     * Accepts a local request: creates the follower edge and tells the follower.
     *
     * @return array<string, mixed>
     */
    public function accept(string $id, string $targetAccountId): array
    {
        $request = $this->find($id, $targetAccountId);
        if ($request === null) {
            if ($this->remoteRequests !== null) {
                try {
                    $follower = $this->remoteRequests->accept($targetAccountId, $id);
                } catch (FollowRequestNotFoundException) {
                    throw CommunityException::notFound('This follow request no longer exists.');
                }
                $this->notifications?->recordFollow($targetAccountId, $follower->actorId);

                return [
                    'id' => $follower->id,
                    'target_account_id' => $follower->localUserId,
                    'actor_id' => $follower->actorId,
                    'actor_handle' => $follower->actorId,
                    'is_local' => false,
                    'created_at' => $follower->followedAt->format(DATE_ATOM),
                ];
            }

            throw CommunityException::notFound('This follow request no longer exists.');
        }
        if ($request['is_local'] !== true) {
            throw CommunityException::invalid('This request cannot be approved here.');
        }

        $followerId = (string) $request['actor_handle'];
        $this->followers->follow(
            new FollowActor(
                localUserId: $targetAccountId,
                actorId: (string) $request['actor_id'],
                inboxUrl: $this->activities->sharedInboxId(),
            ),
            local: true,
        );
        $this->notifications?->recordFollow($targetAccountId, $followerId);

        $this->db->execute(
            'DELETE FROM follow_request WHERE id = :id AND target_account_id = :target',
            ['id' => $id, 'target' => $targetAccountId],
        );

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $id, string $targetAccountId): array
    {
        $request = $this->find($id, $targetAccountId);
        if ($request === null) {
            if ($this->remoteRequests !== null) {
                try {
                    $follower = $this->remoteRequests->reject($targetAccountId, $id);
                } catch (FollowRequestNotFoundException) {
                    throw CommunityException::notFound('This follow request no longer exists.');
                }

                return [
                    'id' => $follower->id,
                    'target_account_id' => $follower->localUserId,
                    'actor_id' => $follower->actorId,
                    'actor_handle' => $follower->actorId,
                    'is_local' => false,
                    'created_at' => $follower->followedAt->format(DATE_ATOM),
                ];
            }

            throw CommunityException::notFound('This follow request no longer exists.');
        }

        $this->db->execute(
            'DELETE FROM follow_request WHERE id = :id AND target_account_id = :target',
            ['id' => $id, 'target' => $targetAccountId],
        );

        return $request;
    }

    /**
     * Drops a pending request when the follower withdraws it.
     */
    public function withdraw(string $targetAccountId, string $followerId): bool
    {
        $actorId = $this->activities->actorId(trim($followerId));

        $removed = $this->db->execute(
            'DELETE FROM follow_request WHERE target_account_id = :target AND actor_id = :actor',
            ['target' => $targetAccountId, 'actor' => $actorId],
        );

        return $removed > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'target_account_id' => (string) $row['target_account_id'],
            'actor_id' => (string) $row['actor_id'],
            'actor_handle' => (string) $row['actor_handle'],
            'is_local' => (bool) ($row['is_local'] ?? false),
            'created_at' => is_string($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
        ];
    }
}
