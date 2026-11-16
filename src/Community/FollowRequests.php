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
 * handle. Remote requests are not accepted through this service yet because a
 * remote follower needs the original signed Follow activity to receive a valid
 * Accept; the host rejects those at the inbox policy instead. This service is
 * therefore deliberately the local-account half of the feature.
 */
final class FollowRequests
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly FollowerService $followers,
        private readonly ActivityFactory $activities,
        private readonly ?NotificationService $notifications = null,
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

        return array_map([$this, 'hydrate'], $rows);
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
            throw CommunityException::invalid('Die Anfrage ist unvollständig.');
        }
        if ($targetAccountId === $followerId) {
            throw CommunityException::invalid('Du kannst dir nicht selbst folgen.');
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
            ?? throw CommunityException::notFound('Die Anfrage wurde nicht gespeichert.');
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
            throw CommunityException::notFound('Diese Folgenanfrage gibt es nicht mehr.');
        }
        if ($request['is_local'] !== true) {
            throw CommunityException::invalid('Diese Anfrage kann hier nicht bestätigt werden.');
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
            throw CommunityException::notFound('Diese Folgenanfrage gibt es nicht mehr.');
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
