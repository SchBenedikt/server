<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * A remote Like lives in the core reaction table, while a local favourite uses
 * the favourite table. The card counter must see both as one number.
 */
final class RemoteLikeCountTest extends TestCase
{
    public function testInboundRemoteLikesIncreaseThePostCounter(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $postId = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Public post', Visibility::Public),
        )->id;

        $server->runtime->database->execute(
            'INSERT INTO social_reaction'
            . ' (id, direction, subject_id, object_id, local_post_id, kind, emoji, icon_url, activity_id, created_at)'
            . ' VALUES (:id, :direction, :subject, :object, :post, :kind, :emoji, NULL, :activity, :created)',
            [
                'id' => 'reaction-1',
                'direction' => 'in',
                'subject' => 'https://remote.example/@bob',
                'object' => 'https://social.example/api/v1/posts/' . $postId,
                'post' => $postId,
                'kind' => 'like',
                'emoji' => '',
                'activity' => 'https://remote.example/likes/1',
                'created' => '2026-11-18T10:00:00Z',
            ],
        );

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $postId],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['interactions'][$postId]['favourites_count']);
    }

    public function testReplyCountsOnlyIncludeRepliesTheViewerMayRead(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $parent = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Parent', Visibility::Public),
        )->id;
        $server->runtime->app->posts->create(new CreatePost(
            'alice',
            'Public reply',
            Visibility::Public,
            inReplyToId: $parent,
        ));
        $server->runtime->app->posts->create(new CreatePost(
            'alice',
            'Followers-only reply',
            Visibility::Followers,
            inReplyToId: $parent,
        ));

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $parent],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['interactions'][$parent]['replies_count']);
    }

    public function testInboundRemoteRepliesAreCountedAndDeleteRemovesThem(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $parent = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Parent', Visibility::Public),
        )->id;

        $create = [
            'id' => 'https://remote.example/activities/create-1',
            'type' => 'Create',
            'actor' => 'https://remote.example/@bob',
            'object' => [
                'id' => 'https://remote.example/notes/1',
                'type' => 'Note',
                'attributedTo' => 'https://remote.example/@bob',
                'inReplyTo' => 'http://localhost:3000/api/v1/posts/' . $parent,
                'content' => 'A federated reply',
            ],
        ];

        $server->runtime->interactions->recordInboundActivity(json_encode($create, JSON_THROW_ON_ERROR));

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $parent],
        ]);
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['interactions'][$parent]['replies_count']);

        $server->runtime->interactions->recordInboundActivity(json_encode([
            'id' => 'https://remote.example/activities/delete-1',
            'type' => 'Delete',
            'actor' => 'https://remote.example/@bob',
            'object' => 'https://remote.example/notes/1',
        ], JSON_THROW_ON_ERROR));

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $parent],
        ]);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['interactions'][$parent]['replies_count']);
    }

    public function testMissingOriginCountsAreNotCachedAsZero(): void
    {
        $server = new TestServer();
        $objectId = 'https://remote.example/objects/no-counts';

        $server->runtime->remoteEngagement->remember($objectId, [
            'post' => ['id' => $objectId, 'content' => 'No counters'],
        ]);

        self::assertSame([], $server->runtime->remoteEngagement->counts([$objectId]));
    }

    public function testPartialRefreshKeepsPreviouslyKnownCounters(): void
    {
        $server = new TestServer();
        $objectId = 'https://remote.example/objects/partial';

        $server->runtime->remoteEngagement->rememberCounts($objectId, [
            'likes' => 7,
            'boosts' => 2,
            'replies' => 1,
        ]);
        $server->runtime->remoteEngagement->rememberCounts($objectId, ['replies' => 9]);

        $counts = $server->runtime->remoteEngagement->counts([$objectId])[$objectId];
        self::assertSame(7, $counts['likes']);
        self::assertSame(2, $counts['boosts']);
        self::assertSame(9, $counts['replies']);
    }

    public function testCachedOriginCountersDecorateRemoteCardsAndInteractions(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $session = $server->signIn('alice');

        $activityId = 'https://remote.example/activities/42';
        $objectId = 'https://remote.example/objects/42';
        $actorId = 'https://remote.example/@bob';
        $server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at, audience)'
            . ' VALUES (:activity_id, :object_id, :author, :actor, :kind, :content, :published, \'public\')',
            [
                'activity_id' => $activityId,
                'object_id' => $objectId,
                'author' => $actorId,
                'actor' => $actorId,
                'kind' => 'post',
                'content' => 'Federated post',
                'published' => '2026-11-20T10:00:00Z',
            ],
        );
        $server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-counters', 'alice', :actor, 'https://remote.example/inbox', '2026-11-20T10:00:00Z', 'accepted')",
            ['actor' => $actorId],
        );
        $server->runtime->remoteEngagement->remember($objectId, [
            'post' => [
                'id' => $objectId,
                'favourites_count' => 12,
                'reblogs_count' => 3,
                'replies_count' => 4,
            ],
        ]);

        $cards = $server->send('GET', '/api/v1/host/posts', [
            'query' => ['ids' => $activityId],
        ]);
        self::assertSame(200, $cards->status);
        $card = json_decode($cards->body, true, 512, JSON_THROW_ON_ERROR)['posts'][$activityId];
        self::assertSame(12, $card['likes_count']);
        self::assertSame(3, $card['boosts_count']);
        self::assertSame(4, $card['replies_count']);

        // Cards address an ActivityPub post by its object id, so the batched
        // interaction request must use the same key.
        $state = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $objectId],
            'cookies' => $session['cookies'],
        ]);
        self::assertSame(200, $state->status);
        $interaction = json_decode($state->body, true, 512, JSON_THROW_ON_ERROR)['interactions'][$objectId];
        self::assertSame(12, $interaction['favourites_count']);
        self::assertSame(3, $interaction['reblogs_count']);
        self::assertSame(4, $interaction['replies_count']);
        self::assertFalse($interaction['favourited']);
    }

    public function testPrivatePostsDoNotLeakInteractionCounts(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $postId = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Private post', Visibility::Followers),
        )->id;

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $postId],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey($postId, $body['interactions']);
    }
}
