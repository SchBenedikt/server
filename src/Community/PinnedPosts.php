<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\Time;

use function array_map;
use function is_string;
use function trim;

/**
 * Own posts an account keeps at the top of its profile.
 *
 * Only the author can pin, only a visible (non-direct) post can be pinned,
 * and the list is bounded so a profile cannot grow an endless header. The
 * service never changes the post itself: a pin is a private host marker that
 * disappears when the post or the pin is removed.
 */
final class PinnedPosts
{
    public const MAX_PINS = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly PostService $posts,
    ) {
    }

    /**
     * @return list<array{post_id: string, position: int, pinned_at: string}>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT post_id, position, created_at FROM pinned_post WHERE account_id = :account_id'
            . ' ORDER BY position ASC, created_at ASC',
            ['account_id' => $accountId],
        );

        return array_map(
            static fn (array $row): array => [
                'post_id' => (string) $row['post_id'],
                'position' => (int) $row['position'],
                'pinned_at' => (string) $row['created_at'],
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
            'SELECT post_id FROM pinned_post WHERE account_id = :account_id AND post_id = :post_id',
            ['account_id' => $accountId, 'post_id' => $postId],
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
            $post = $this->posts->get($postId);
        } catch (PostNotFoundException) {
            throw CommunityException::notFound('This post no longer exists.');
        }

        if ($post->authorId !== $accountId) {
            throw CommunityException::forbidden('Only your own posts can be pinned.');
        }

        if ($post->visibility === Visibility::Direct) {
            throw CommunityException::invalid(
                'Direct messages cannot be pinned.',
                ['post_id' => 'Choose a public post.'],
            );
        }

        if ($this->isPinned($accountId, $postId)) {
            return ['post_id' => $postId, 'pinned' => true, 'pins' => $this->idsFor($accountId)];
        }

        $count = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM pinned_post WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );
        if ($count >= self::MAX_PINS) {
            throw CommunityException::invalid(
                'You can pin at most ' . self::MAX_PINS . '  posts.',
                ['post_id' => 'Unpin another post first.'],
            );
        }

        $this->db->execute(
            'INSERT INTO pinned_post (account_id, post_id, position, created_at)'
            . ' VALUES (:account_id, :post_id, :position, :created_at)',
            [
                'account_id' => $accountId,
                'post_id' => $postId,
                'position' => $count,
                'created_at' => Time::format($this->clock->now()),
            ],
        );

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

        $this->db->execute(
            'DELETE FROM pinned_post WHERE account_id = :account_id AND post_id = :post_id',
            ['account_id' => $accountId, 'post_id' => $postId],
        );

        return ['post_id' => $postId, 'pinned' => false, 'pins' => $this->idsFor($accountId)];
    }
}
