<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\Time;

use function array_map;
use function is_string;
use function trim;

/**
 * Posts an account has taken out of its public profile without deleting them.
 *
 * Archiving is private and reversible: the row only marks (account, post).
 * The core still owns the post, its media and its federation; removing the
 * row puts it back on the profile. Only the author can archive a post, and an
 * archived post is still readable by anyone it was already visible to.
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
            'SELECT post_id, archived_at FROM archived_post WHERE account_id = :account_id'
            . ' ORDER BY archived_at DESC, post_id ASC LIMIT 500',
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
            $post = $this->posts->get($postId);
        } catch (PostNotFoundException) {
            throw CommunityException::notFound('This post no longer exists.');
        }

        if ($post->authorId !== $accountId) {
            throw CommunityException::forbidden('Only your own posts can be archived.');
        }

        $existing = $this->db->fetchOne(
            'SELECT post_id, archived_at FROM archived_post WHERE account_id = :account_id AND post_id = :post_id',
            ['account_id' => $accountId, 'post_id' => $postId],
        );
        if ($existing !== null) {
            return [
                'post_id' => (string) $existing['post_id'],
                'archived_at' => (string) $existing['archived_at'],
            ];
        }

        $archivedAt = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO archived_post (account_id, post_id, archived_at) VALUES (:account_id, :post_id, :archived_at)',
            ['account_id' => $accountId, 'post_id' => $postId, 'archived_at' => $archivedAt],
        );

        return ['post_id' => $postId, 'archived_at' => $archivedAt];
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

        $removed = $this->db->execute(
            'DELETE FROM archived_post WHERE account_id = :account_id AND post_id = :post_id',
            ['account_id' => $accountId, 'post_id' => $postId],
        );

        return ['post_id' => $postId, 'restored' => $removed > 0];
    }
}
