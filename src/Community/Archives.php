<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\Time;

use function array_map;
use function trim;

/**
 * Posts an account has taken out of its public profile without deleting them.
 *
 * Archiving is a core post state (`social_post.archived_at`), not a second
 * marker table. That keeps the host profile, the core account statuses, the
 * search index and the core `/api/v1/posts/archived` route in agreement.
 * Only the author can archive a post, and an archived post is still readable
 * by anyone it was already visible to.
 */
final class Archives
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly PostService $posts,
    ) {
    }

    /**
     * @return list<array{post_id: string, archived_at: string}>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id AS post_id, archived_at FROM social_post'
            . ' WHERE author_id = :account_id AND archived_at IS NOT NULL AND deleted_at IS NULL'
            . ' ORDER BY archived_at DESC, id ASC LIMIT 500',
            ['account_id' => $accountId],
        );

        return array_map(
            static fn (array $row): array => [
                'post_id' => (string) $row['post_id'],
                'archived_at' => (string) $row['archived_at'],
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

    /**
     * @return array{post_id: string, archived_at: string}
     */
    public function archive(string $accountId, string $postId): array
    {
        $postId = trim($postId);
        if ($postId === '') {
            throw CommunityException::invalid('The post is missing.', ['post_id' => 'Please choose a post.']);
        }

        try {
            $post = $this->posts->archive($accountId, $postId);
        } catch (PostNotFoundException) {
            throw CommunityException::notFound('This post no longer exists.');
        } catch (PostAccessDeniedException) {
            throw CommunityException::forbidden('Only your own posts can be archived.');
        }

        return [
            'post_id' => $post->id,
            'archived_at' => $post->archivedAt === null
                ? Time::format($this->clock->now())
                : Time::format($post->archivedAt),
        ];
    }

    /**
     * @return array{post_id: string, restored: bool}
     */
    public function restore(string $accountId, string $postId): array
    {
        $postId = trim($postId);
        if ($postId === '') {
            throw CommunityException::invalid('The post is missing.', ['post_id' => 'Please choose a post.']);
        }

        $row = $this->db->fetchOne(
            'SELECT author_id, archived_at FROM social_post WHERE id = :id AND deleted_at IS NULL',
            ['id' => $postId],
        );
        if ($row === null) {
            return ['post_id' => $postId, 'restored' => false];
        }
        if ((string) $row['author_id'] !== $accountId) {
            throw CommunityException::forbidden('Only your own posts can be restored.');
        }
        if ($row['archived_at'] === null) {
            return ['post_id' => $postId, 'restored' => false];
        }

        try {
            $this->posts->unarchive($accountId, $postId);
        } catch (PostNotFoundException) {
            return ['post_id' => $postId, 'restored' => false];
        } catch (PostAccessDeniedException) {
            throw CommunityException::forbidden('Only your own posts can be restored.');
        }

        return ['post_id' => $postId, 'restored' => true];
    }
}
