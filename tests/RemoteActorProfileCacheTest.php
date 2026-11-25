<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Server\Tests\Support\FakeBoundedHttpClient;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class RemoteActorProfileCacheTest extends TestCase
{
    private FakeBoundedHttpClient $http;

    private TestServer $server;

    protected function setUp(): void
    {
        $this->http = new FakeBoundedHttpClient();
        $this->server = new TestServer([], $this->http, new class implements HostResolver {
            public function resolve(string $host): array
            {
                return $host === 'peer.example' ? ['8.8.8.8'] : [];
            }
        });
    }

    public function testFederatedTimelineShowsCachedDisplayNameAndAvatar(): void
    {
        $this->seed('https://peer.example/activities/1', 'https://peer.example/notes/1', 'Hello');
        $this->http->json('https://peer.example/@bob', [
            'id' => 'https://peer.example/@bob',
            'type' => 'Person',
            'preferredUsername' => 'bob',
            'name' => 'Bob Beispiel',
            'icon' => ['type' => 'Image', 'url' => 'https://cdn.peer.example/bob.png'],
            'inbox' => 'https://peer.example/@bob/inbox',
            'outbox' => 'https://peer.example/@bob/outbox',
        ]);

        self::assertSame(1, $this->server->runtime->remoteActorProfiles->refreshStale(5));

        $response = $this->server->send('GET', '/api/v1/host/federated');
        self::assertSame(200, $response->status, $response->body);

        /** @var array{posts: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $post = $body['posts'][0];

        self::assertSame('Bob Beispiel', $post['author_display_name']);
        self::assertSame('@bob@peer.example', $post['author_handle']);
        self::assertSame('https://cdn.peer.example/bob.png', $post['author_avatar']);
        self::assertSame('https://peer.example/@bob', $post['object_author_id']);
    }

    public function testUnreachableActorBacksOffWithoutBreakingTheTimeline(): void
    {
        $this->seed('https://peer.example/activities/1', 'https://peer.example/notes/1', 'Hello');
        $this->http->html('https://peer.example/@bob', 'gone', 410);

        self::assertSame(0, $this->server->runtime->remoteActorProfiles->refreshStale(5));
        $requestsAfterFailure = count($this->http->requests);
        self::assertSame(1, $requestsAfterFailure);

        // The six-hour failure backoff must keep the next tick from retrying
        // an actor that just refused the request.
        self::assertSame(0, $this->server->runtime->remoteActorProfiles->refreshStale(5));
        self::assertCount($requestsAfterFailure, $this->http->requests);

        $response = $this->server->send('GET', '/api/v1/host/federated');
        self::assertSame(200, $response->status, $response->body);

        /** @var array{posts: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($body['posts'][0]['author_display_name']);
        self::assertNull($body['posts'][0]['author_avatar']);
        self::assertSame('@bob@peer.example', $body['posts'][0]['author_handle']);
    }

    public function testBoostCardUsesTheOriginalAuthorsProfile(): void
    {
        $this->seed(
            'https://peer.example/activities/boost-1',
            'https://other.example/notes/9',
            '',
            actorId: 'https://peer.example/@booster',
            kind: 'boost',
            objectAuthorId: 'https://peer.example/@bob',
        );
        $this->http->json('https://peer.example/@bob', [
            'id' => 'https://peer.example/@bob',
            'type' => 'Person',
            'preferredUsername' => 'bob',
            'name' => 'Bob Beispiel',
            'icon' => ['type' => 'Image', 'url' => 'https://cdn.peer.example/bob.png'],
            'inbox' => 'https://peer.example/@bob/inbox',
        ]);

        self::assertSame(1, $this->server->runtime->remoteActorProfiles->refreshStale(5));

        $response = $this->server->send('GET', '/api/v1/host/federated');
        /** @var array{posts: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $post = $body['posts'][0];

        self::assertSame('boost', $post['kind']);
        self::assertSame('https://peer.example/@booster', $post['boosted_by']);
        self::assertSame('Bob Beispiel', $post['author_display_name']);
        self::assertSame('https://cdn.peer.example/bob.png', $post['author_avatar']);
    }

    private function seed(
        string $activityId,
        string $objectId,
        string $content,
        string $publishedAt = '2026-11-21T10:00:00Z',
        string $actorId = 'https://peer.example/@bob',
        string $kind = 'post',
        ?string $objectAuthorId = null,
    ): void {
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content,'
            . ' content_warning, published_at, deleted_at)'
            . ' VALUES (:activity_id, :object_id, :object_author_id, :actor_id, :kind, :content,'
            . ' NULL, :published_at, NULL)',
            [
                'activity_id' => $activityId,
                'object_id' => $objectId,
                'object_author_id' => $objectAuthorId ?? $actorId,
                'actor_id' => $actorId,
                'kind' => $kind,
                'content' => $content,
                'published_at' => $publishedAt,
            ],
        );
    }
}
