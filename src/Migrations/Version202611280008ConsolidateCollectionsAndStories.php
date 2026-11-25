<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Collection\MediaCollectionSchema;
use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;
use AlohaSocial\Server\Community\Collections;

use function is_string;
use function parse_str;
use function parse_url;
use function rawurlencode;

/**
 * Moves the two remaining host-owned community stores onto the core tables.
 *
 * Reading lists were broader than core media albums: they could reference any
 * visible post and had an owner-only mode. The adapter in {@see Collections}
 * keeps those semantics on the core tables. The copy here is lossless: every
 * collection, every item (including text and foreign-author posts), every
 * story, its background and every view marker is carried over. Core media
 * rules are not used to filter legacy rows; the adapter handles them at read
 * time. The old tables are dropped so there is only one source of truth.
 */
final class Version202611280008ConsolidateCollectionsAndStories implements Migration
{
    private const STORY_OBJECT_PREFIX = 'host-story:';

    public function version(): string
    {
        return '202611280008';
    }

    public function description(): string
    {
        return 'Move host reading lists and text stories onto the core tables.';
    }

    public function up(Connection $connection): void
    {
        $this->ensureCoreTables($connection);

        if (SchemaUpgrade::tableExists($connection, 'collection')) {
            $this->copyCollections($connection);
            if (SchemaUpgrade::tableExists($connection, 'collection_item')) {
                $this->copyCollectionItems($connection);
            }
        }

        if (SchemaUpgrade::tableExists($connection, 'story')) {
            $this->copyStories($connection);
        }

        if (SchemaUpgrade::tableExists($connection, 'story_seen')) {
            $this->copyStoryViews($connection);
        }

        foreach (['story_seen', 'story', 'collection_item', 'collection'] as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }

    public function down(Connection $connection): void
    {
        $this->createLegacyTables($connection);
        $this->copyCollectionsBack($connection);
        $this->copyCollectionItemsBack($connection);
        $this->copyStoriesBack($connection);
        $this->copyStoryViewsBack($connection);
    }

    private function ensureCoreTables(Connection $connection): void
    {
        if (!SchemaUpgrade::tableExists($connection, MediaCollectionSchema::TABLE)) {
            MediaCollectionSchema::create($connection);
        } elseif (!SchemaUpgrade::tableExists($connection, MediaCollectionSchema::ITEM_TABLE)) {
            foreach (MediaCollectionSchema::statements() as $statement) {
                if (str_contains($statement, MediaCollectionSchema::ITEM_TABLE)) {
                    $connection->execute($statement);
                }
            }
        }

        if (!SchemaUpgrade::tableExists($connection, StorySchema::TABLE)) {
            StorySchema::create($connection);
        } else {
            foreach ([StorySchema::VIEW_TABLE, StorySchema::REACTION_TABLE, StorySchema::REPLY_TABLE, StorySchema::TOMBSTONE_TABLE] as $table) {
                if (SchemaUpgrade::tableExists($connection, $table)) {
                    continue;
                }
                foreach (StorySchema::statements() as $statement) {
                    if (str_contains($statement, $table)) {
                        $connection->execute($statement);
                    }
                }
            }
        }
    }

    private function copyCollections(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT id, account_id, title, description, is_public, created_at, updated_at'
            . ' FROM collection ORDER BY created_at ASC, id ASC',
        );

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if ($connection->fetchOne(
                'SELECT 1 AS present FROM ' . MediaCollectionSchema::TABLE . ' WHERE id = :id',
                ['id' => $id],
            ) !== null) {
                continue;
            }

            $public = (int) ($row['is_public'] ?? 1) === 1;
            $owner = (string) $row['account_id'];
            $connection->execute(
                'INSERT INTO ' . MediaCollectionSchema::TABLE
                . ' (id, owner_id, title, description, audience, created_at, updated_at)'
                . ' VALUES (:id, :owner_id, :title, :description, :audience, :created_at, :updated_at)',
                [
                    'id' => $id,
                    'owner_id' => $public ? $owner : Collections::PRIVATE_OWNER_PREFIX . $owner,
                    'title' => (string) $row['title'],
                    'description' => (string) ($row['description'] ?? ''),
                    'audience' => $public ? 'public' : 'followers',
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                ],
            );
        }
    }

    private function copyCollectionItems(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT collection_id, post_id, position, created_at FROM collection_item'
            . ' ORDER BY collection_id ASC, position ASC, created_at ASC, post_id ASC',
        );

        /** @var array<string, int> $positions */
        $positions = [];
        foreach ($rows as $row) {
            $collectionId = (string) $row['collection_id'];
            $postId = (string) $row['post_id'];
            if ($connection->fetchOne(
                'SELECT 1 AS present FROM ' . MediaCollectionSchema::ITEM_TABLE
                . ' WHERE collection_id = :collection_id AND post_id = :post_id',
                ['collection_id' => $collectionId, 'post_id' => $postId],
            ) !== null) {
                continue;
            }

            $positions[$collectionId] ??= (int) $connection->fetchValue(
                'SELECT COALESCE(MAX(position), 0) FROM ' . MediaCollectionSchema::ITEM_TABLE
                . ' WHERE collection_id = :collection_id',
                ['collection_id' => $collectionId],
            );
            ++$positions[$collectionId];

            $connection->execute(
                'INSERT INTO ' . MediaCollectionSchema::ITEM_TABLE
                . ' (collection_id, post_id, position, added_at)'
                . ' VALUES (:collection_id, :post_id, :position, :added_at)',
                [
                    'collection_id' => $collectionId,
                    'post_id' => $postId,
                    'position' => $positions[$collectionId],
                    'added_at' => (string) $row['created_at'],
                ],
            );
        }
    }

    private function copyStories(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT id, account_id, content, background, created_at, expires_at'
            . ' FROM story ORDER BY created_at ASC, id ASC',
        );

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if ($connection->fetchOne(
                'SELECT 1 AS present FROM ' . StorySchema::TABLE . ' WHERE id = :id',
                ['id' => $id],
            ) !== null) {
                continue;
            }

            $tone = is_string($row['background'] ?? null) ? (string) $row['background'] : 'ocean';
            $connection->execute(
                'INSERT INTO ' . StorySchema::TABLE
                . ' (id, owner_id, is_local, object_id, caption, attachment_id, remote_media_url,'
                . ' created_at, expires_at, deleted_at)'
                . ' VALUES (:id, :owner_id, 1, :object_id, :caption, NULL, :remote_media_url,'
                . ' :created_at, :expires_at, NULL)',
                [
                    'id' => $id,
                    'owner_id' => (string) $row['account_id'],
                    'object_id' => self::STORY_OBJECT_PREFIX . $id,
                    'caption' => (string) $row['content'],
                    'remote_media_url' => $this->backgroundUrl($id, $tone),
                    'created_at' => (string) $row['created_at'],
                    'expires_at' => (string) $row['expires_at'],
                ],
            );
        }
    }

    private function copyStoryViews(Connection $connection): void
    {
        $connection->execute(
            'INSERT INTO ' . StorySchema::VIEW_TABLE . ' (story_id, viewer_id, viewed_at)'
            . ' SELECT seen.story_id, seen.account_id, seen.seen_at FROM story_seen seen'
            . ' WHERE EXISTS (SELECT 1 FROM ' . StorySchema::TABLE . ' story WHERE story.id = seen.story_id)'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . StorySchema::VIEW_TABLE . ' existing_view'
            . ' WHERE existing_view.story_id = seen.story_id AND existing_view.viewer_id = seen.account_id)',
        );
    }

    private function createLegacyTables(Connection $connection): void
    {
        if (!SchemaUpgrade::tableExists($connection, 'collection')) {
            $connection->execute(
                'CREATE TABLE collection ('
                . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
                . ' account_id VARCHAR(64) NOT NULL,'
                . " title VARCHAR(100) NOT NULL,"
                . " description VARCHAR(500) NOT NULL DEFAULT '',"
                . ' is_public INTEGER NOT NULL DEFAULT 1,'
                . ' created_at VARCHAR(20) NOT NULL,'
                . ' updated_at VARCHAR(20) NOT NULL'
                . ')',
            );
            SchemaUpgrade::createIndexIfMissing($connection, 'collection', 'collection_account', 'account_id, updated_at');
        }

        if (!SchemaUpgrade::tableExists($connection, 'collection_item')) {
            $connection->execute(
                'CREATE TABLE collection_item ('
                . ' collection_id VARCHAR(64) NOT NULL,'
                . ' post_id VARCHAR(64) NOT NULL,'
                . ' position INTEGER NOT NULL,'
                . ' created_at VARCHAR(20) NOT NULL,'
                . ' PRIMARY KEY (collection_id, post_id)'
                . ')',
            );
            SchemaUpgrade::createIndexIfMissing($connection, 'collection_item', 'collection_item_order', 'collection_id, position');
        }

        if (!SchemaUpgrade::tableExists($connection, 'story')) {
            $connection->execute(
                'CREATE TABLE story ('
                . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
                . ' account_id VARCHAR(64) NOT NULL,'
                . ' content VARCHAR(500) NOT NULL,'
                . " background VARCHAR(32) NOT NULL DEFAULT 'ocean',"
                . ' created_at VARCHAR(20) NOT NULL,'
                . ' expires_at VARCHAR(20) NOT NULL'
                . ')',
            );
            SchemaUpgrade::createIndexIfMissing($connection, 'story', 'story_expiry', 'expires_at, created_at');
            SchemaUpgrade::createIndexIfMissing($connection, 'story', 'story_account', 'account_id, created_at');
        }

        if (!SchemaUpgrade::tableExists($connection, 'story_seen')) {
            $connection->execute(
                'CREATE TABLE story_seen ('
                . ' story_id VARCHAR(64) NOT NULL,'
                . ' account_id VARCHAR(64) NOT NULL,'
                . ' seen_at VARCHAR(20) NOT NULL,'
                . ' PRIMARY KEY (story_id, account_id)'
                . ')',
            );
            SchemaUpgrade::createIndexIfMissing($connection, 'story_seen', 'story_seen_account', 'account_id');
        }
    }

    private function copyCollectionsBack(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT id, owner_id, title, description, audience, created_at, updated_at'
            . ' FROM ' . MediaCollectionSchema::TABLE . ' ORDER BY created_at ASC, id ASC',
        );

        foreach ($rows as $row) {
            $owner = (string) $row['owner_id'];
            $private = str_starts_with($owner, Collections::PRIVATE_OWNER_PREFIX);
            $accountId = $private ? substr($owner, strlen(Collections::PRIVATE_OWNER_PREFIX)) : $owner;
            if ($connection->fetchOne(
                'SELECT 1 AS present FROM collection WHERE id = :id',
                ['id' => (string) $row['id']],
            ) !== null) {
                continue;
            }

            $connection->execute(
                'INSERT INTO collection (id, account_id, title, description, is_public, created_at, updated_at)'
                . ' VALUES (:id, :account_id, :title, :description, :is_public, :created_at, :updated_at)',
                [
                    'id' => (string) $row['id'],
                    'account_id' => $accountId,
                    'title' => (string) $row['title'],
                    'description' => (string) ($row['description'] ?? ''),
                    'is_public' => !$private && (string) $row['audience'] === 'public' ? 1 : 0,
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                ],
            );
        }
    }

    private function copyCollectionItemsBack(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT collection_id, post_id, position, added_at FROM ' . MediaCollectionSchema::ITEM_TABLE
            . ' ORDER BY collection_id ASC, position ASC',
        );

        foreach ($rows as $row) {
            if ($connection->fetchOne(
                'SELECT 1 AS present FROM collection_item'
                . ' WHERE collection_id = :collection_id AND post_id = :post_id',
                [
                    'collection_id' => (string) $row['collection_id'],
                    'post_id' => (string) $row['post_id'],
                ],
            ) !== null) {
                continue;
            }

            $connection->execute(
                'INSERT INTO collection_item (collection_id, post_id, position, created_at)'
                . ' VALUES (:collection_id, :post_id, :position, :created_at)',
                [
                    'collection_id' => (string) $row['collection_id'],
                    'post_id' => (string) $row['post_id'],
                    'position' => max(0, (int) $row['position'] - 1),
                    'created_at' => (string) $row['added_at'],
                ],
            );
        }
    }

    private function copyStoriesBack(Connection $connection): void
    {
        $rows = $connection->fetchAll(
            'SELECT id, owner_id, caption, remote_media_url, created_at, expires_at FROM ' . StorySchema::TABLE
            . ' WHERE object_id LIKE :object_prefix AND deleted_at IS NULL ORDER BY created_at ASC, id ASC',
            ['object_prefix' => self::STORY_OBJECT_PREFIX . '%'],
        );

        foreach ($rows as $row) {
            if ($connection->fetchOne(
                'SELECT 1 AS present FROM story WHERE id = :id',
                ['id' => (string) $row['id']],
            ) !== null) {
                continue;
            }

            $connection->execute(
                'INSERT INTO story (id, account_id, content, background, created_at, expires_at)'
                . ' VALUES (:id, :account_id, :content, :background, :created_at, :expires_at)',
                [
                    'id' => (string) $row['id'],
                    'account_id' => (string) $row['owner_id'],
                    'content' => (string) $row['caption'],
                    'background' => $this->backgroundFromUrl(
                        is_string($row['remote_media_url'] ?? null) ? $row['remote_media_url'] : null,
                    ),
                    'created_at' => (string) $row['created_at'],
                    'expires_at' => (string) $row['expires_at'],
                ],
            );
        }
    }

    private function copyStoryViewsBack(Connection $connection): void
    {
        $connection->execute(
            'INSERT INTO story_seen (story_id, account_id, seen_at)'
            . ' SELECT view_row.story_id, view_row.viewer_id, view_row.viewed_at FROM '
            . StorySchema::VIEW_TABLE . ' view_row'
            . ' WHERE EXISTS (SELECT 1 FROM ' . StorySchema::TABLE . ' story'
            . ' WHERE story.id = view_row.story_id AND story.object_id LIKE :object_prefix)'
            . ' AND NOT EXISTS (SELECT 1 FROM story_seen seen'
            . ' WHERE seen.story_id = view_row.story_id AND seen.account_id = view_row.viewer_id)',
            ['object_prefix' => self::STORY_OBJECT_PREFIX . '%'],
        );
    }

    private function backgroundUrl(string $id, string $tone): string
    {
        return '/api/v1/host/stories/' . rawurlencode($id) . '/background?background=' . rawurlencode($tone);
    }

    private function backgroundFromUrl(?string $url): string
    {
        if ($url === null) {
            return 'ocean';
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return 'ocean';
        }

        parse_str($query, $parameters);
        $tone = $parameters['background'] ?? null;

        return is_string($tone) && in_array($tone, ['ocean', 'sunset', 'forest', 'berry', 'night', 'sand'], true)
            ? $tone
            : 'ocean';
    }
}
