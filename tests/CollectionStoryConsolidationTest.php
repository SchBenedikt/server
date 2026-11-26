<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Collection\PdoMediaCollectionRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Story\PdoStoryRepository;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Migrations\Version202611280008ConsolidateCollectionsAndStories;
use AlohaSocial\Server\Migrations\Version202611280009RepairStoryViews;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The migration is the proof that existing host data survived the move to the
 * core tables. The HTTP assertions then exercise the same rows through the
 * adapter, including private visibility, story backgrounds and export.
 */
final class CollectionStoryConsolidationTest extends TestCase
{
    public function testLegacyRowsMoveToCoreAndStayUsableOverHttp(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $server->createAccount('bob', 'bob@example.test');

        $alicePost = $server->runtime->app->posts
            ->create(new CreatePost('alice', 'Alice text post', Visibility::Public))
            ->id;
        $bobPost = $server->runtime->app->posts
            ->create(new CreatePost('bob', 'Bob text post', Visibility::Public))
            ->id;

        $stamp = Time::format($server->runtime->clock->now());
        $expires = Time::format($server->runtime->clock->now()->modify('+24 hours'));
        $db = $server->runtime->database;

        // Recreate the pre-consolidation schema in a fresh installation, as a
        // real upgrade from the previous release would have it.
        $db->execute(
            'CREATE TABLE collection ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' title VARCHAR(100) NOT NULL,'
            . ' description VARCHAR(500) NOT NULL,'
            . ' is_public INTEGER NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $db->execute(
            'CREATE TABLE collection_item ('
            . ' collection_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' position INTEGER NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (collection_id, post_id)'
            . ')',
        );
        $db->execute(
            'CREATE TABLE story ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' content VARCHAR(500) NOT NULL,'
            . ' background VARCHAR(32) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $db->execute(
            'CREATE TABLE story_seen ('
            . ' story_id VARCHAR(64) NOT NULL,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' seen_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (story_id, account_id)'
            . ')',
        );

        $db->execute(
            'INSERT INTO collection (id, account_id, title, description, is_public, created_at, updated_at)'
            . ' VALUES (:id, :account, :title, :description, 1, :created, :updated)',
            [
                'id' => 'legacy-public',
                'account' => 'alice',
                'title' => 'Legacy public',
                'description' => 'Kept across the migration',
                'created' => $stamp,
                'updated' => $stamp,
            ],
        );
        $db->execute(
            'INSERT INTO collection (id, account_id, title, description, is_public, created_at, updated_at)'
            . ' VALUES (:id, :account, :title, :description, 0, :created, :updated)',
            [
                'id' => 'legacy-private',
                'account' => 'alice',
                'title' => 'Legacy private',
                'description' => '',
                'created' => $stamp,
                'updated' => $stamp,
            ],
        );
        $db->execute(
            'INSERT INTO collection_item (collection_id, post_id, position, created_at)'
            . ' VALUES (:collection, :post, 0, :created)',
            ['collection' => 'legacy-public', 'post' => $alicePost, 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO collection_item (collection_id, post_id, position, created_at)'
            . ' VALUES (:collection, :post, 1, :created)',
            ['collection' => 'legacy-public', 'post' => $bobPost, 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO collection_item (collection_id, post_id, position, created_at)'
            . ' VALUES (:collection, :post, 0, :created)',
            ['collection' => 'legacy-private', 'post' => $bobPost, 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO story (id, account_id, content, background, created_at, expires_at)'
            . ' VALUES (:id, :account, :content, :background, :created, :expires)',
            [
                'id' => 'legacy-story',
                'account' => 'alice',
                'content' => 'Legacy sunset',
                'background' => 'sunset',
                'created' => $stamp,
                'expires' => $expires,
            ],
        );
        $db->execute(
            'INSERT INTO story_seen (story_id, account_id, seen_at)'
            . ' VALUES (:story, :account, :seen)',
            ['story' => 'legacy-story', 'account' => 'bob', 'seen' => $stamp],
        );

        $db->execute("DELETE FROM schema_migration WHERE version = '202611280008'");
        $runner = new MigrationRunner($db, new SystemClock(), Migrations::all());
        self::assertSame(['202611280008'], $runner->migrate());

        self::assertSame([], $db->fetchAll(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
            . " AND name IN ('collection', 'collection_item', 'story', 'story_seen')",
        ));

        $public = $db->fetchOne(
            'SELECT owner_id, title, description, audience, created_at, updated_at'
            . ' FROM social_media_collection WHERE id = :id',
            ['id' => 'legacy-public'],
        );
        self::assertNotNull($public);
        self::assertSame('alice', (string) $public['owner_id']);
        self::assertSame('public', (string) $public['audience']);
        self::assertSame('Legacy public', (string) $public['title']);
        self::assertSame('Kept across the migration', (string) $public['description']);
        self::assertSame($stamp, (string) $public['created_at']);

        $private = $db->fetchOne(
            'SELECT owner_id, audience FROM social_media_collection WHERE id = :id',
            ['id' => 'legacy-private'],
        );
        self::assertSame('host-private:alice', (string) $private['owner_id']);
        self::assertSame('followers', (string) $private['audience']);

        self::assertSame(
            [$alicePost, $bobPost],
            array_column(
                $db->fetchAll(
                    'SELECT post_id FROM social_media_collection_item'
                    . ' WHERE collection_id = :id ORDER BY position ASC',
                    ['id' => 'legacy-public'],
                ),
                'post_id',
            ),
        );

        $story = $db->fetchOne(
            'SELECT owner_id, caption, remote_media_url, created_at, expires_at'
            . ' FROM social_story WHERE id = :id',
            ['id' => 'legacy-story'],
        );
        self::assertSame('alice', (string) $story['owner_id']);
        self::assertSame('Legacy sunset', (string) $story['caption']);
        self::assertStringContainsString('background=sunset', (string) $story['remote_media_url']);
        self::assertSame($expires, (string) $story['expires_at']);
        self::assertSame(
            'bob',
            (string) $db->fetchValue(
                'SELECT viewer_id FROM social_story_view WHERE story_id = :id',
                ['id' => 'legacy-story'],
            ),
        );

        $coreStory = (new PdoStoryRepository($db))->find('legacy-story');
        self::assertNotNull($coreStory);
        self::assertSame('Legacy sunset', $coreStory->caption);
        $coreCollection = (new PdoMediaCollectionRepository($db))->find('legacy-public');
        self::assertNotNull($coreCollection);
        self::assertSame('Legacy public', $coreCollection->title);
        self::assertSame(Visibility::Public, $coreCollection->audience);

        $login = $server->signIn('alice');

        $publicResponse = $server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => 'legacy-public'],
        ]);
        self::assertSame(200, $publicResponse->status, $publicResponse->body);
        $publicBody = json_decode($publicResponse->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$alicePost, $bobPost], $publicBody['collection']['post_ids']);

        $hidden = $server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => 'legacy-private'],
        ]);
        self::assertSame(404, $hidden->status);

        $owner = $server->send('GET', '/api/v1/host/collection', [
            'query' => ['id' => 'legacy-private'],
            'cookies' => $login['cookies'],
        ]);
        self::assertSame(200, $owner->status, $owner->body);
        $ownerBody = json_decode($owner->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$bobPost], $ownerBody['collection']['post_ids']);
        self::assertFalse($ownerBody['collection']['is_public']);

        $stories = $server->send('GET', '/api/v1/host/stories', [
            'cookies' => $login['cookies'],
        ]);
        self::assertSame(200, $stories->status, $stories->body);
        $storyBody = json_decode($stories->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('legacy-story', array_column($storyBody['stories'], 'id'));
        self::assertSame('sunset', array_column($storyBody['stories'], 'background', 'id')['legacy-story']);

        $background = $server->send('GET', '/api/v1/host/stories/legacy-story/background');
        self::assertSame(200, $background->status, $background->body);
        self::assertStringContainsString('image/svg+xml', (string) $background->header('Content-Type'));
        self::assertStringContainsString('#d35b3f', $background->body);

        $seen = $server->json(
            'POST',
            '/api/v1/host/stories/seen',
            ['ids' => ['legacy-story']],
            $login['cookies'],
            $login['csrf'],
        );
        self::assertSame(200, $seen->status, $seen->body);
        self::assertSame(1, json_decode($seen->body, true, 512, JSON_THROW_ON_ERROR)['seen']);

        $renamed = $server->json(
            'PATCH',
            '/api/v1/host/collections/legacy-public',
            ['title' => 'Renamed public'],
            $login['cookies'],
            $login['csrf'],
        );
        self::assertSame(200, $renamed->status, $renamed->body);
        self::assertSame(
            'Renamed public',
            (string) $db->fetchValue(
                'SELECT title FROM social_media_collection WHERE id = :id',
                ['id' => 'legacy-public'],
            ),
        );

        $createdStory = $server->json(
            'POST',
            '/api/v1/host/stories',
            ['content' => 'A new core story', 'background' => 'forest'],
            $login['cookies'],
            $login['csrf'],
        );
        self::assertSame(201, $createdStory->status, $createdStory->body);
        $createdStoryBody = json_decode($createdStory->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            'A new core story',
            (string) $db->fetchValue(
                'SELECT caption FROM social_story WHERE id = :id',
                ['id' => $createdStoryBody['story']['id']],
            ),
        );
        $newStorySeen = $server->json(
            'POST',
            '/api/v1/host/stories/seen',
            ['ids' => [$createdStoryBody['story']['id']]],
            $login['cookies'],
            $login['csrf'],
        );
        self::assertSame(200, $newStorySeen->status, $newStorySeen->body);
        self::assertSame(1, json_decode($newStorySeen->body, true, 512, JSON_THROW_ON_ERROR)['seen']);

        $deleted = $server->json(
            'DELETE',
            '/api/v1/host/stories/' . $createdStoryBody['story']['id'],
            [],
            $login['cookies'],
            $login['csrf'],
        );
        self::assertSame(200, $deleted->status, $deleted->body);
        self::assertNotNull($db->fetchValue(
            'SELECT deleted_at FROM social_story WHERE id = :id',
            ['id' => $createdStoryBody['story']['id']],
        ));
        self::assertSame(0, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM social_story_view WHERE story_id = :id',
            ['id' => $createdStoryBody['story']['id']],
        ));

        $export = $server->send('GET', '/api/v1/host/export', ['cookies' => $login['cookies']]);
        self::assertSame(200, $export->status, $export->body);
        $exportBody = json_decode($export->body, true, 512, JSON_THROW_ON_ERROR);
        $collectionIds = array_column($exportBody['collections'], 'id');
        sort($collectionIds);
        self::assertSame(['legacy-private', 'legacy-public'], $collectionIds);
        self::assertSame(['legacy-story'], array_column($exportBody['stories'], 'id'));
        self::assertSame('legacy-story', (string) $exportBody['story_views'][0]['story_id']);

        // Simulate a view marker that outlived its soft-deleted story. The
        // repair migration must clean it before the frozen 008 rollback turns
        // it into an orphaned legacy row.
        $deletedStoryId = (string) $createdStoryBody['story']['id'];
        $db->execute(
            'INSERT INTO social_story_view (story_id, viewer_id, viewed_at)'
            . ' VALUES (:story_id, :viewer_id, :viewed_at)',
            ['story_id' => $deletedStoryId, 'viewer_id' => 'bob', 'viewed_at' => $stamp],
        );

        // The rollback path is part of the migration contract too: it restores
        // the old tables and reconstructs the host shape from core rows. The
        // runner unwinds 009 before 008, and the test follows that order.
        $migration = new Version202611280008ConsolidateCollectionsAndStories();
        $repair = new Version202611280009RepairStoryViews();
        $repair->down($db);
        $migration->down($db);
        self::assertSame(
            'Legacy private',
            (string) $db->fetchValue('SELECT title FROM collection WHERE id = :id', ['id' => 'legacy-private']),
        );
        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT is_public FROM collection WHERE id = :id', ['id' => 'legacy-private']),
        );
        self::assertSame(
            $bobPost,
            (string) $db->fetchValue(
                'SELECT post_id FROM collection_item WHERE collection_id = :id ORDER BY position LIMIT 1',
                ['id' => 'legacy-private'],
            ),
        );
        self::assertSame(
            'sunset',
            (string) $db->fetchValue('SELECT background FROM story WHERE id = :id', ['id' => 'legacy-story']),
        );
        self::assertSame(
            ['alice', 'bob'],
            array_column(
                $db->fetchAll(
                    'SELECT account_id FROM story_seen WHERE story_id = :id ORDER BY account_id',
                    ['id' => 'legacy-story'],
                ),
                'account_id',
            ),
        );
        self::assertSame(0, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM story WHERE id = :id',
            ['id' => $deletedStoryId],
        ));
        self::assertSame(0, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM story_seen WHERE story_id = :id',
            ['id' => $deletedStoryId],
        ));

        $migration->up($db);
        $repair->up($db);
        self::assertSame(0, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM social_story_view WHERE story_id = :id',
            ['id' => $deletedStoryId],
        ));
        self::assertSame(2, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM social_story_view WHERE story_id = :id',
            ['id' => 'legacy-story'],
        ));
        self::assertSame([], $db->fetchAll(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
            . " AND name IN ('collection', 'collection_item', 'story', 'story_seen')",
        ));
    }

    public function testCollidingCoreStoryIdDoesNotReceiveLegacyStoryViews(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $db = $server->runtime->database;
        $stamp = Time::format($server->runtime->clock->now());
        $expires = Time::format($server->runtime->clock->now()->modify('+24 hours'));

        $db->execute(
            'CREATE TABLE story ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' content VARCHAR(500) NOT NULL,'
            . ' background VARCHAR(32) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $db->execute(
            'CREATE TABLE story_seen ('
            . ' story_id VARCHAR(64) NOT NULL,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' seen_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (story_id, account_id)'
            . ')',
        );

        // A core story already owns the id that the legacy row would use.
        // The legacy story is therefore not copied; its view must not be
        // attached to this unrelated core row.
        $db->execute(
            'INSERT INTO social_story'
            . ' (id, owner_id, is_local, object_id, caption, attachment_id, remote_media_url,'
            . ' created_at, expires_at, deleted_at)'
            . ' VALUES (:id, :owner, 1, :object_id, :caption, NULL, :media, :created, :expires, NULL)',
            [
                'id' => 'collision-story',
                'owner' => 'alice',
                'object_id' => 'https://remote.example/stories/collision',
                'caption' => 'Core story',
                'media' => 'https://remote.example/media/collision.png',
                'created' => $stamp,
                'expires' => $expires,
            ],
        );
        $db->execute(
            'INSERT INTO story (id, account_id, content, background, created_at, expires_at)'
            . ' VALUES (:id, :account, :content, :background, :created, :expires)',
            [
                'id' => 'collision-story',
                'account' => 'alice',
                'content' => 'Legacy story',
                'background' => 'forest',
                'created' => $stamp,
                'expires' => $expires,
            ],
        );
        $db->execute(
            'INSERT INTO story_seen (story_id, account_id, seen_at)'
            . ' VALUES (:story, :account, :seen)',
            ['story' => 'collision-story', 'account' => 'alice', 'seen' => $stamp],
        );
        $db->execute("DELETE FROM schema_migration WHERE version = '202611280008'");

        $runner = new MigrationRunner($db, new SystemClock(), Migrations::all());
        self::assertSame(['202611280008'], $runner->migrate());

        self::assertSame(
            'Core story',
            (string) $db->fetchValue(
                'SELECT caption FROM social_story WHERE id = :id',
                ['id' => 'collision-story'],
            ),
        );
        self::assertSame(0, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM social_story_view WHERE story_id = :id',
            ['id' => 'collision-story'],
        ));
        self::assertSame([], $db->fetchAll(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
            . " AND name IN ('story', 'story_seen')",
        ));
    }
}
