<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Core\Service\ReactionService;
use Throwable;

/**
 * Local likes and boosts on cached federated posts.
 *
 * The writes, deliveries and the block/audience/follow policy all live in
 * the core's reaction service; this adapter only adds the host's one extra
 * rule — a post discovered through search or a profile may not be in the
 * inbound cache yet, so it is imported once before the core resolves it.
 */
final class RemoteReactions
{
    private readonly PdoRemotePostRepository $posts;

    public function __construct(
        private readonly Connection $db,
        private readonly ?ReactionService $snapshots,
        private readonly ?RemotePostImporter $importer = null,
    ) {
        $this->posts = new PdoRemotePostRepository($db);
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
        $service = $this->service();
        $post = $this->post($userId, $objectId);

        return $service->remoteInteractionsFor($userId, $post);
    }

    /** @return array<string, mixed> */
    public function like(string $userId, string $objectId): array
    {
        // Authorize before delegating so an authorization-store outage still
        // fails closed instead of surfacing as a generic server error.
        $this->post($userId, $objectId);

        return $this->service()->likeRemote($userId, $objectId);
    }

    /** @return array<string, mixed> */
    public function unlike(string $userId, string $objectId): array
    {
        $this->ensure($objectId);

        return $this->undo($userId, $objectId, $this->service()->unlikeRemote(...));
    }

    /** @return array<string, mixed> */
    public function boost(string $userId, string $objectId): array
    {
        $this->post($userId, $objectId);

        return $this->service()->boostRemote($userId, $objectId);
    }

    /** @return array<string, mixed> */
    public function unboost(string $userId, string $objectId): array
    {
        $this->ensure($objectId);

        return $this->undo($userId, $objectId, $this->service()->unboostRemote(...));
    }

    /**
     * Imports a discovered post so the core's remote interaction policy has
     * a stored row to authorize against.
     *
     * @throws ReactionException
     */
    private function ensure(string $objectId): void
    {
        $objectId = trim($objectId);
        if ($objectId === '') {
            throw new ReactionException('remote_post_not_found', 'Remote post not found.');
        }

        $post = $this->posts->findPostByObjectId($objectId);
        if (($post === null || $post->deletedAt !== null) && $this->importer !== null) {
            try {
                $this->importer->ensure($objectId);
            } catch (RemoteDirectoryException $exception) {
                if ($exception->status === 404) {
                    throw new ReactionException('remote_post_not_found', 'Remote post not found.');
                }

                throw new ReactionException('remote_post_unreachable', $exception->getMessage());
            }
        }
    }

    /**
     * The cached post after the core's remote-interaction policy authorized
     * the read. Used for the viewer-state snapshot, which must not reveal a
     * post the caller could not react to.
     *
     * @throws ReactionException
     */
    private function post(string $userId, string $objectId): RemotePost
    {
        $objectId = trim($objectId);
        if ($objectId === '') {
            throw new ReactionException('remote_post_not_found', 'Remote post not found.');
        }

        $this->ensure($objectId);

        try {
            $authorized = $this->service()->remotePostFor($userId, $objectId);
        } catch (ReactionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ReactionException(
                'authorization_unavailable',
                'Remote interaction authorization is temporarily unavailable.',
            );
        }

        return $authorized;
    }

    /**
     * Undo paths in the core deliberately skip the read policy so a removed
     * follow or a new block cannot trap a local reaction; delivery is still
     * gated. A policy-store outage surfaces here as a fail-closed answer.
     *
     * @param callable(string, string): array<string, mixed> $action
     *
     * @return array<string, mixed>
     */
    private function undo(string $userId, string $objectId, callable $action): array
    {
        try {
            return $action($userId, $objectId);
        } catch (ReactionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ReactionException(
                'authorization_unavailable',
                'Remote interaction authorization is temporarily unavailable.',
            );
        }
    }

    /**
     * @throws ReactionException
     */
    private function service(): ReactionService
    {
        if ($this->snapshots === null) {
            throw new ReactionException(
                'remote_reactions_not_configured',
                'Remote post interactions are not available.',
            );
        }

        return $this->snapshots;
    }
}
