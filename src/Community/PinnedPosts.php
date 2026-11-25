<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostPin;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\PinService;
use AlohaSocial\Core\Service\ValidationException;

use function array_map;
use function trim;

/**
 * Own posts an account keeps at the top of its profile.
 *
 * Pins live in the core's `social_post_pin` table, which is also what the
 * ActivityPub `featured` collection reads. The host route stays a thin adapter
 * around PinService, so pinning in the UI immediately changes what remote
 * servers and core API clients see. A deleted or archived post is filtered by
 * the core when it builds featured; a stale row is harmless.
 */
final class PinnedPosts
{
    public const MAX_PINS = PostPin::MAX_PINS;

    public function __construct(
        private readonly Connection $db,
        private readonly PinService $pins,
    ) {
    }

    /**
     * @return list<array{post_id: string, position: int, pinned_at: string}>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT post_id, position, pinned_at FROM social_post_pin WHERE owner_id = :owner_id'
            . ' ORDER BY position ASC',
            ['owner_id' => $accountId],
        );

        return array_map(
            static fn (array $row): array => [
                'post_id' => (string) $row['post_id'],
                'position' => (int) $row['position'],
                'pinned_at' => (string) $row['pinned_at'],
            ],
            $rows,
        );
    }

    /**
     * @return list<string>
     */
    public function idsFor(string $accountId): array
    {
        return array_column($this->forAccount($accountId), 'post_id');
    }

    public function isPinned(string $accountId, string $postId): bool
    {
        return $this->db->fetchOne(
            'SELECT post_id FROM social_post_pin WHERE owner_id = :owner_id AND post_id = :post_id',
            ['owner_id' => $accountId, 'post_id' => $postId],
        ) !== null;
    }

    /**
     * @return array{post_id: string, pinned: bool, pins: list<string>}
     */
    public function pin(string $accountId, string $postId): array
    {
        $postId = trim($postId);
        if ($postId === '') {
            throw CommunityException::invalid('The post is missing.', ['post_id' => 'Please choose a post.']);
        }

        try {
            $this->pins->pin($accountId, $postId);
        } catch (PostNotFoundException) {
            throw CommunityException::notFound('This post no longer exists.');
        } catch (PostAccessDeniedException) {
            throw CommunityException::forbidden('Only your own posts can be pinned.');
        } catch (ValidationException $exception) {
            throw CommunityException::invalid($exception->getMessage(), $exception->fields());
        }

        return ['post_id' => $postId, 'pinned' => true, 'pins' => $this->idsFor($accountId)];
    }

    /**
     * @return array{post_id: string, pinned: bool, pins: list<string>}
     */
    public function unpin(string $accountId, string $postId): array
    {
        $postId = trim($postId);
        if ($postId === '') {
            throw CommunityException::invalid('The post is missing.', ['post_id' => 'Please choose a post.']);
        }

        $this->pins->unpin($accountId, $postId);

        return ['post_id' => $postId, 'pinned' => false, 'pins' => $this->idsFor($accountId)];
    }
}
