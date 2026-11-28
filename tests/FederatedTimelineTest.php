<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class FederatedTimelineTest extends TestCase
{
    private TestServer $server;

    protected function setUp(): void
    {
        $this->server = new TestServer();
    }

    public function testFederatedTimelineReturnsRemotePostsNewestFirst(): void
    {
        $this->seed('https://peer.example/activities/1', 'https://peer.example/notes/1', 'Older post', '2026-11-20T10:00:00Z');
        $this->seed('https://peer.example/activities/2', 'https://peer.example/notes/2', 'Newer post', '2026-11-21T10:00:00Z');

        $response = $this->server->send('GET', '/api/v1/host/federated');
        self::assertSame(200, $response->status, $response->body);

        /** @var array{posts: list<array<string, mixed>>, has_more: bool} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $body['posts']);
        self::assertSame('https://peer.example/activities/2', $body['posts'][0]['id']);
        self::assertSame('Newer post', $body['posts'][0]['content']);
        self::assertTrue($body['posts'][0]['is_remote']);
        self::assertSame('public', $body['posts'][0]['visibility']);
        self::assertSame('@bob@peer.example', $body['posts'][0]['author_handle']);
        self::assertSame('https://peer.example/notes/2', $body['posts'][0]['external_url']);
        self::assertFalse($body['has_more']);
    }

    public function testFederatedTimelineCarriesOriginEngagementAndBoostShape(): void
    {
        $this->seed(
            'https://peer.example/activities/boost-1',
            'https://other.example/notes/9',
            '',
            '2026-11-22T10:00:00Z',
            kind: 'boost',
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_engagement (object_id, likes, boosts, replies, fetched_at)'
            . ' VALUES (:object_id, :likes, :boosts, :replies, :fetched_at)',
            [
                'object_id' => 'https://other.example/notes/9',
                'likes' => 12,
                'boosts' => 3,
                'replies' => 4,
                'fetched_at' => '2026-11-22T10:00:00Z',
            ],
        );

        $response = $this->server->send('GET', '/api/v1/host/federated');
        /** @var array{posts: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $post = $body['posts'][0];
        self::assertSame('boost', $post['kind']);
        self::assertSame('https://peer.example/users/bob', $post['boosted_by']);
        self::assertSame('https://other.example/notes/9', $post['object_id']);
        self::assertSame(12, $post['likes_count']);
        self::assertSame(3, $post['boosts_count']);
        self::assertSame(4, $post['replies_count']);
    }

    public function testFederatedTimelineFiltersByHashtag(): void
    {
        $this->seed('https://peer.example/activities/1', 'https://peer.example/notes/1', 'A post about #fediverse', '2026-11-20T10:00:00Z');
        $this->seed('https://peer.example/activities/2', 'https://peer.example/notes/2', 'A post about #photography', '2026-11-21T10:00:00Z');

        $response = $this->server->send('GET', '/api/v1/host/federated', [
            'query' => ['tag' => 'Fediverse'],
        ]);
        /** @var array{posts: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $body['posts']);
        self::assertSame('https://peer.example/activities/1', $body['posts'][0]['id']);
    }

    public function testFederatedTimelinePaginatesAndSkipsDeletedPosts(): void
    {
        $this->seed('https://peer.example/activities/1', 'https://peer.example/notes/1', 'Oldest', '2026-11-19T10:00:00Z');
        $this->seed('https://peer.example/activities/2', 'https://peer.example/notes/2', 'Middle', '2026-11-20T10:00:00Z');
        $this->seed('https://peer.example/activities/3', 'https://peer.example/notes/3', 'Newest', '2026-11-21T10:00:00Z');
        $this->seed(
            'https://peer.example/activities/4',
            'https://peer.example/notes/4',
            'Deleted',
            '2026-11-22T10:00:00Z',
            deletedAt: '2026-11-23T10:00:00Z',
        );

        $first = $this->server->send('GET', '/api/v1/host/federated', [
            'query' => ['limit' => '2'],
        ]);
        /** @var array{posts: list<array<string, mixed>>, next_before: string, next_before_id: string, has_more: bool} $firstBody */
        $firstBody = json_decode($first->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $firstBody['posts']);
        self::assertTrue($firstBody['has_more']);
        self::assertSame('https://peer.example/activities/3', $firstBody['posts'][0]['id']);

        $second = $this->server->send('GET', '/api/v1/host/federated', [
            'query' => [
                'limit' => '2',
                'before' => $firstBody['next_before'],
                'before_id' => $firstBody['next_before_id'],
            ],
        ]);
        /** @var array{posts: list<array<string, mixed>>, has_more: bool} $secondBody */
        $secondBody = json_decode($second->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $secondBody['posts']);
        self::assertSame('https://peer.example/activities/1', $secondBody['posts'][0]['id']);
        self::assertFalse($secondBody['has_more']);
    }

    public function testFederatedTimelineOnlyListsVerifiedPublicAudience(): void
    {
        $this->seed('https://peer.example/activities/public', 'https://peer.example/notes/p', 'Public', '2026-11-20T10:00:00Z', audience: 'public');
        $this->seed('https://peer.example/activities/followers', 'https://peer.example/notes/f', 'Followers only', '2026-11-21T10:00:00Z', audience: 'followers');
        $this->seed('https://peer.example/activities/unknown', 'https://peer.example/notes/u', 'Unknown audience', '2026-11-22T10:00:00Z', audience: null);
        $this->seed('https://peer.example/activities/unlisted', 'https://peer.example/notes/u2', 'Unlisted', '2026-11-23T10:00:00Z', audience: 'unlisted');
        $this->seed('https://peer.example/activities/direct', 'https://peer.example/notes/d', 'Direct', '2026-11-24T10:00:00Z', audience: 'direct');

        $response = $this->server->send('GET', '/api/v1/host/federated');
        self::assertSame(200, $response->status, $response->body);
        /** @var array{posts: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['https://peer.example/activities/public'], array_column($body['posts'], 'id'));
    }

    public function testFederatedTimelineDoesNotExposeLocalPosts(): void
    {
        $this->server->createAccount('alice');
        $alice = $this->server->signIn('alice');
        $created = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A local post',
            'visibility' => 'public',
        ], $alice['cookies'], $alice['csrf']);
        self::assertSame(201, $created->status, $created->body);

        $response = $this->server->send('GET', '/api/v1/host/federated');
        /** @var array{posts: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $body['posts']);
    }

    private function seed(
        string $activityId,
        string $objectId,
        string $content,
        string $publishedAt,
        string $actorId = 'https://peer.example/users/bob',
        string $kind = 'post',
        ?string $deletedAt = null,
        ?string $audience = 'public',
    ): void {
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content,'
            . ' content_warning, published_at, deleted_at, audience)'
            . ' VALUES (:activity_id, :object_id, :object_author_id, :actor_id, :kind, :content,'
            . ' NULL, :published_at, :deleted_at, :audience)',
            [
                'activity_id' => $activityId,
                'object_id' => $objectId,
                'object_author_id' => $actorId,
                'actor_id' => $actorId,
                'kind' => $kind,
                'content' => $content,
                'published_at' => $publishedAt,
                'deleted_at' => $deletedAt,
                'audience' => $audience,
            ],
        );
    }
}
