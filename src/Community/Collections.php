<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Collection\MediaCollectionItem;
use AlohaSocial\Core\Domain\Collection\MediaCollectionSchema;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\Time;

use function array_map;
use function count;
use function max;
use function mb_strlen;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Reading lists backed by the core media-collection tables.
 *
 * The core's {@see \AlohaSocial\Core\Service\Collection\MediaCollectionService}
 * intentionally models media albums: every item must be an own image/video
 * post and every collection has an ActivityPub audience. A host reading list
 * is deliberately broader: it may reference any post the owner can read,
 * including text posts and posts by other authors, and it has a strict
 * owner-only mode.
 *
 * This adapter keeps one storage path in the core tables. Public lists use the
 * real account as `owner_id` and `audience = public`. Private lists use the
 * reserved `host-private:` owner namespace and `audience = followers`; no
 * account has a follower edge to that namespace, so the core service cannot
 * enumerate or dereference a private list. The adapter maps the namespace back
 * to the real owner on every read and keeps the item visibility check on the
 * core {@see PostService}, not on a copied snapshot.
 */
final class Collections
{
    public const PRIVATE_OWNER_PREFIX = 'host-private:';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly PostService $posts,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAccount(string $accountId, bool $includePrivate = true, ?string $viewerId = null): array
    {
        $parameters = ['account_id' => $accountId];
        if ($includePrivate) {
            $where = '(c.owner_id = :account_id OR c.owner_id = :private_owner)';
            $parameters['private_owner'] = self::PRIVATE_OWNER_PREFIX . $accountId;
        } else {
            $where = 'c.owner_id = :account_id AND c.audience = :public';
            $parameters['public'] = 'public';
        }

        $rows = $this->db->fetchAll(
            'SELECT c.*, (SELECT COUNT(*) FROM ' . MediaCollectionSchema::ITEM_TABLE
            . ' i WHERE i.collection_id = c.id) AS item_count'
            . ' FROM ' . MediaCollectionSchema::TABLE . ' c WHERE ' . $where
            . ' ORDER BY c.updated_at DESC, c.created_at DESC, c.id DESC LIMIT 100',
            $parameters,
        );

        $collections = array_map(fn (array $row): array => $this->hydrate($row), $rows);

        // A public listing must not reveal how many inaccessible posts an
        // owner has collected. The owner and private views keep the total.
        if ($accountId !== $viewerId) {
            foreach ($collections as &$collection) {
                if ((bool) $collection['is_public']) {
                    $collection['item_count'] = count($this->visiblePostIds(
                        (string) $collection['id'],
                        $viewerId,
                        false,
                    ));
                }
            }
            unset($collection);
        }

        return $collections;
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
        $public = $this->boolean($isPublic);
        $now = Time::format($this->clock->now());
        $id = bin2hex(random_bytes(12));

        $this->db->execute(
            'INSERT INTO ' . MediaCollectionSchema::TABLE
            . ' (id, owner_id, title, description, audience, created_at, updated_at)'
            . ' VALUES (:id, :owner_id, :title, :description, :audience, :now, :now)',
            [
                'id' => $id,
                'owner_id' => $this->ownerId($accountId, $public),
                'title' => $name,
                'description' => $text,
                'audience' => $public ? 'public' : 'followers',
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

        $public = $isPublic === null ? (bool) $current['is_public'] : $this->boolean($isPublic);

        $this->db->execute(
            'UPDATE ' . MediaCollectionSchema::TABLE
            . ' SET owner_id = :owner_id, title = :title, description = :description,'
            . ' audience = :audience, updated_at = :updated_at WHERE id = :id',
            [
                'owner_id' => $this->ownerId($accountId, $public),
                'title' => $title === null ? $current['title'] : $this->title($title),
                'description' => $description === null ? $current['description'] : $this->description($description),
                'audience' => $public ? 'public' : 'followers',
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
            $this->db->execute(
                'DELETE FROM ' . MediaCollectionSchema::ITEM_TABLE . ' WHERE collection_id = :id',
                ['id' => $id],
            );
            $this->db->execute(
                'DELETE FROM ' . MediaCollectionSchema::TABLE . ' WHERE id = :id',
                ['id' => $id],
            );
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

        // A public collection is a public reading list. Keep follower-only and
        // other restricted posts out of it instead of leaking their ids later.
        if ((bool) $collection['is_public'] && !$post->visibility->isPubliclyVisible()) {
            throw CommunityException::invalid('Only public posts can be added to a public collection.');
        }

        $existing = $this->db->fetchOne(
            'SELECT 1 FROM ' . MediaCollectionSchema::ITEM_TABLE
            . ' WHERE collection_id = :collection_id AND post_id = :post_id',
            ['collection_id' => $id, 'post_id' => $postId],
        );
        if ($existing === null) {
            $position = (int) $this->db->fetchValue(
                'SELECT COALESCE(MAX(position), 0) + 1 FROM ' . MediaCollectionSchema::ITEM_TABLE
                . ' WHERE collection_id = :collection_id',
                ['collection_id' => $id],
            );
            $item = new MediaCollectionItem($id, $postId, $position, $this->clock->now());
            $this->db->execute(
                'INSERT INTO ' . MediaCollectionSchema::ITEM_TABLE
                . ' (collection_id, post_id, position, added_at)'
                . ' VALUES (:collection_id, :post_id, :position, :added_at)',
                [
                    'collection_id' => $item->collectionId,
                    'post_id' => $item->postId,
                    'position' => $item->position,
                    'added_at' => Time::format($item->addedAt),
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
            'DELETE FROM ' . MediaCollectionSchema::ITEM_TABLE
            . ' WHERE collection_id = :collection_id AND post_id = :post_id',
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
            'SELECT c.*, (SELECT COUNT(*) FROM ' . MediaCollectionSchema::ITEM_TABLE
            . ' i WHERE i.collection_id = c.id) AS item_count'
            . ' FROM ' . MediaCollectionSchema::TABLE . ' c WHERE c.id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }

        $collection = $this->hydrate($row);
        if ((bool) $collection['is_public'] !== true && $collection['account_id'] !== $viewerId) {
            return null;
        }

        $isOwner = $collection['account_id'] === $viewerId;
        $postIds = $this->visiblePostIds($id, $viewerId, $isOwner);
        $collection['post_ids'] = $postIds;
        if ((bool) $collection['is_public']) {
            $collection['item_count'] = count($postIds);
        }

        return $collection;
    }

    /**
     * @return list<string>
     */
    private function visiblePostIds(string $id, ?string $viewerId, bool $isOwner = false): array
    {
        $items = $this->db->fetchAll(
            'SELECT i.post_id FROM ' . MediaCollectionSchema::ITEM_TABLE . ' i'
            . ' JOIN social_post p ON p.id = i.post_id AND p.deleted_at IS NULL'
            . ' WHERE i.collection_id = :id ORDER BY i.position ASC, i.added_at ASC LIMIT 200',
            ['id' => $id],
        );

        $visible = [];
        foreach ($items as $item) {
            $postId = (string) $item['post_id'];

            try {
                // Rechecks follow relationships, privacy changes and deletion
                // at read time, not only when the item was added. The owner is
                // not exempt: unfollowing the author also hides the post from
                // the owner's public collection view.
                $this->posts->getVisible($postId, $viewerId);
                $visible[] = $postId;
            } catch (PostNotFoundException) {
                continue;
            }
        }

        return $visible;
    }

    private function touch(string $id): void
    {
        $this->db->execute(
            'UPDATE ' . MediaCollectionSchema::TABLE . ' SET updated_at = :updated_at WHERE id = :id',
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

    private function ownerId(string $accountId, bool $public): string
    {
        return $public ? $accountId : self::PRIVATE_OWNER_PREFIX . $accountId;
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
        $owner = (string) $row['owner_id'];
        $private = str_starts_with($owner, self::PRIVATE_OWNER_PREFIX);
        $accountId = $private ? substr($owner, strlen(self::PRIVATE_OWNER_PREFIX)) : $owner;

        return [
            'id' => (string) $row['id'],
            'account_id' => $accountId,
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'is_public' => !$private && (string) ($row['audience'] ?? '') === 'public',
            'item_count' => (int) ($row['item_count'] ?? 0),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
