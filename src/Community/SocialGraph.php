<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Platform\Db\Connection;

use function array_merge;
use function array_values;
use function rawurlencode;
use function rtrim;
use function trim;

/**
 * One read model for "who does this account follow?".
 *
 * The core stores a local follow as a follower edge in `social_follower`
 * (`is_local = 1`, `actor_id` = the follower's actor URL) and a remote follow
 * as an `social_following` row whose `actor_id` is an actor URL or a DID.
 * Host features that only looked at `social_following`, or compared its actor
 * ids with local handles, silently lost every local follow. This class keeps
 * the two id spaces apart and exposes the right shape for each caller.
 */
final class SocialGraph
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $instanceUrl = '',
    ) {
    }

    /**
     * The canonical ActivityPub actor id of a local account.
     *
     * The core uses the same `/@handle` form in LocalRelationshipService.
     */
    public function actorId(string $accountId): string
    {
        $accountId = trim($accountId);
        if ($accountId === '' || $this->instanceUrl === '') {
            return '';
        }

        return rtrim($this->instanceUrl, '/') . '/@' . rawurlencode($accountId);
    }

    /**
     * Local accounts the viewer follows, as local account ids.
     *
     * @return list<string>
     */
    public function localFolloweeIds(string $viewerId): array
    {
        $viewerId = trim($viewerId);
        if ($viewerId === '') {
            return [];
        }

        [$condition, $parameters] = $this->localActorCondition($viewerId, 'actor_id', 'local_follow');
        $rows = $this->db->fetchAll(
            'SELECT local_user_id FROM social_follower'
            . " WHERE is_local = 1 AND state = 'accepted' AND " . $condition,
            $parameters,
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['local_user_id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Remote actor ids and AT Protocol DIDs the viewer follows.
     *
     * @return list<string>
     */
    public function remoteFollowedActorIds(string $viewerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT actor_id AS id FROM social_following'
            . " WHERE local_user_id = :viewer AND state = 'accepted'"
            . ' UNION SELECT subject_did AS id FROM ' . AtprotoFollowSchema::TABLE
            . " WHERE local_user_id = :atproto_viewer AND state = 'active'",
            ['viewer' => $viewerId, 'atproto_viewer' => $viewerId],
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * All author ids a post may carry for an account the viewer follows.
     *
     * Local posts use the local handle, remote posts use the actor URL or DID.
     *
     * @return array<string, true>
     */
    public function followedAuthorIds(string $viewerId): array
    {
        $authors = [];
        foreach (array_merge($this->localFolloweeIds($viewerId), $this->remoteFollowedActorIds($viewerId)) as $id) {
            $authors[$id] = true;
        }

        return $authors;
    }

    /**
     * A correlated subquery returning the local accounts the viewer follows.
     *
     * Callers use it as `account_id IN (<sql>)` so a poll does not need one
     * placeholder per followed account.
     *
     * @return array{sql: string, params: array<string, string>}
     */
    public function localFollowingSubquery(
        string $viewerId,
        string $actorColumn = 'actor_id',
        string $prefix = 'local_follow',
    ): array {
        [$condition, $parameters] = $this->localActorCondition($viewerId, $actorColumn, $prefix);

        return [
            'sql' => 'SELECT local_user_id FROM social_follower'
                . " WHERE is_local = 1 AND state = 'accepted' AND " . $condition,
            'params' => $parameters,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function localActorCondition(string $viewerId, string $actorColumn, string $prefix): array
    {
        $actor = $this->actorId($viewerId);
        if ($actor !== '') {
            return [$actorColumn . ' = :' . $prefix . '_actor', [$prefix . '_actor' => $actor]];
        }

        // A caller without the configured instance URL can still resolve the
        // local edge by its stable `/@handle` suffix. `is_local = 1` keeps a
        // remote actor with the same suffix out of the result.
        return [
            $actorColumn . ' LIKE :' . $prefix . '_actor_suffix',
            [$prefix . '_actor_suffix' => '%/@' . $viewerId],
        ];
    }
}
