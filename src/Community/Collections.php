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
use function max;
use function mb_strlen;
use function min;
use function trim;

/**
 * Curated post collections.
 *
 * A collection is a named, ordered list of posts. The items reference core
 * post ids; the posts themselves are never copied. Public collections are
 * readable by anyone, private ones only by their owner.
 */
final class Collections
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly PostService $posts,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAccount(string $accountId, bool $includePrivate = true): array
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM collection_item i WHERE i.collection_id = c.id) AS item_count'
            . ' FROM collection c WHERE c.account_id = :account_id';
        if (!$includePrivate) {
            $sql .= ' AND c.is_public = 1';
        }
        $sql .= ' ORDER BY c.updated_at DESC, c.created_at DESC LIMIT 100';

        $rows = $this->db->fetchAll($sql, ['account_id' => $accountId]);

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicCollections(string $accountId): array
    {
        return $this->forAccount($accountId, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $accountId, mixed $title, mixed $description = '', mixed $isPublic = true): array
    {
        $name = $this->title($title);
        $text = $this->description($description);
        $now = Time::format($this->clock->now());
        $id = bin2hex(random_bytes(12));

        $this->db->execute(
            'INSERT INTO collection (id, account_id, title, description, is_public, created_at, updated_at)'
            . ' VALUES (:id, :account_id, :title, :description, :is_public, :now, :now)',
            [
                'id' => $id,
                'account_id' => $accountId,
                'title' => $name,
                'description' => $text,
                'is_public' => $this->boolean($isPublic) ? 1 : 0,
                'now' => $now,
            ],
        );

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('The collection was not saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function update(
        string $id,
        string $accountId,
        mixed $title = null,
        mixed $description = null,
        mixed $isPublic = null,
    ): array {
        $current = $this->find($id, $accountId);
        if ($current === null) {
            throw CommunityException::notFound('This collection no longer exists.');
        }
        $this->assertOwner($current, $accountId);

        $this->db->execute(
            'UPDATE collection SET title = :title, description = :description, is_public = :is_public, updated_at = :updated_at'
            . ' WHERE id = :id',
            [
                'title' => $title === null ? $current['title'] : $this->title($title),
                'description' => $description === null ? $current['description'] : $this->description($description),
                'is_public' => $isPublic === null ? ((bool) $current['is_public'] ? 1 : 0) : ($this->boolean($isPublic) ? 1 : 0),
                'updated_at' => Time::format($this->clock->now()),
                'id' => $id,
            ],
        );

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('This collection no longer exists.');
    }

    public function delete(string $id, string $accountId): void
    {
        $collection = $this->find($id, $accountId);
        if ($collection === null) {
            throw CommunityException::notFound('This collection no longer exists.');
        }
        $this->assertOwner($collection, $accountId);

        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM collection_item WHERE collection_id = :id', ['id' => $id]);
            $this->db->execute('DELETE FROM collection WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(string $id, string $accountId, string $postId): array
    {
        $collection = $this->find($id, $accountId);
        if ($collection === null) {
            throw CommunityException::notFound('This collection no longer exists.');
        }
        $this->assertOwner($collection, $accountId);

        try {
            $post = $this->posts->getVisible($postId, $accountId);
        } catch (PostNotFoundException) {
            throw CommunityException::notFound('This post is not available.');
        }

        if ((string) $post->visibility->value === 'direct') {
            throw CommunityException::invalid('Direct messages cannot be added to a collection.');
        }

        $existing = $this->db->fetchOne(
            'SELECT 1 FROM collection_item WHERE collection_id = :collection_id AND post_id = :post_id',
            ['collection_id' => $id, 'post_id' => $postId],
        );
        if ($existing === null) {
            $position = (int) $this->db->fetchValue(
                'SELECT COALESCE(MAX(position), -1) + 1 FROM collection_item WHERE collection_id = :collection_id',
                ['collection_id' => $id],
            );
            $this->db->execute(
                'INSERT INTO collection_item (collection_id, post_id, position, created_at)'
                . ' VALUES (:collection_id, :post_id, :position, :created_at)',
                [
                    'collection_id' => $id,
                    'post_id' => $postId,
                    'position' => $position,
                    'created_at' => Time::format($this->clock->now()),
                ],
            );
            $this->touch($id);
        }

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('This collection no longer exists.');
    }

    /**
     * @return array<string, mixed>
     */
    public function removeItem(string $id, string $accountId, string $postId): array
    {
        $collection = $this->find($id, $accountId);
        if ($collection === null) {
            throw CommunityException::notFound('This collection no longer exists.');
        }
        $this->assertOwner($collection, $accountId);

        $this->db->execute(
            'DELETE FROM collection_item WHERE collection_id = :collection_id AND post_id = :post_id',
            ['collection_id' => $id, 'post_id' => $postId],
        );
        $this->touch($id);

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('This collection no longer exists.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, ?string $viewerId = null): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT c.*, (SELECT COUNT(*) FROM collection_item i WHERE i.collection_id = c.id) AS item_count'
            . ' FROM collection c WHERE c.id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }

        $collection = $this->hydrate($row);
        if ((bool) $collection['is_public'] !== true && $collection['account_id'] !== $viewerId) {
            return null;
        }

        $items = $this->db->fetchAll(
            'SELECT i.post_id, i.created_at FROM collection_item i'
            . ' JOIN social_post p ON p.id = i.post_id AND p.deleted_at IS NULL'
            . ' WHERE i.collection_id = :id ORDER BY i.position ASC, i.created_at ASC LIMIT 200',
            ['id' => $id],
        );
        $collection['post_ids'] = array_map(static fn (array $item): string => (string) $item['post_id'], $items);

        return $collection;
    }

    private function touch(string $id): void
    {
        $this->db->execute(
            'UPDATE collection SET updated_at = :updated_at WHERE id = :id',
            ['updated_at' => Time::format($this->clock->now()), 'id' => $id],
        );
    }

    /**
     * @param array<string, mixed> $collection
     */
    private function assertOwner(array $collection, string $accountId): void
    {
        if ((string) $collection['account_id'] !== $accountId) {
            throw CommunityException::forbidden('You can only change your own collections.');
        }
    }

    private function title(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';
        if ($title === '') {
            throw CommunityException::invalid('A collection needs a name.', ['title' => 'Please enter a name.']);
        }
        if (mb_strlen($title) > 100) {
            throw CommunityException::invalid('The name is too long.', ['title' => 'At most 100 characters.']);
        }

        return $title;
    }

    private function description(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) > 500) {
            throw CommunityException::invalid('The description is too long.', ['description' => 'At most 500 characters.']);
        }

        return $text;
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
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
            'account_id' => (string) $row['account_id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'is_public' => (int) ($row['is_public'] ?? 1) === 1,
            'item_count' => (int) ($row['item_count'] ?? 0),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
