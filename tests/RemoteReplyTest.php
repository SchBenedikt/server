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

final class RemoteReplyTest extends TestCase
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

    public function testReplyToCachedRemotePostStoresTargetAndDeliversCreate(): void
    {
        $this->seedRemotePost();
        $this->http->json('https://peer.example/users/bob', [
            'id' => 'https://peer.example/users/bob',
            'type' => 'Person',
            'preferredUsername' => 'bob',
            'inbox' => 'https://peer.example/users/bob/inbox',
        ]);
        $this->server->createAccount('alice');
        $alice = $this->server->signIn('alice');

        $response = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'Great point!',
            'visibility' => 'public',
            'in_reply_to_id' => 'https://peer.example/notes/1',
        ], $alice['cookies'], $alice['csrf']);

        self::assertSame(201, $response->status, $response->body);

        /** @var array{post: array<string, mixed>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $post = $body['post'];
        self::assertSame('https://peer.example/notes/1', $post['in_reply_to_id']);

        $stored = $this->server->runtime->database->fetchOne(
            'SELECT in_reply_to_id FROM social_post WHERE id = :id',
            ['id' => (string) $post['id']],
        );
        self::assertSame('https://peer.example/notes/1', $stored['in_reply_to_id'] ?? null);

        $jobs = $this->server->runtime->database->fetchAll(
            'SELECT payload FROM social_job WHERE type = :type AND subject_id = :user',
            ['type' => 'federation.deliver', 'user' => 'alice'],
        );
        self::assertCount(1, $jobs);

        /** @var array{inbox: string, activity: array<string, mixed>} $payload */
        $payload = json_decode((string) $jobs[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://peer.example/users/bob/inbox', $payload['inbox']);
        self::assertSame('Great point!', $payload['activity']['object']['content']);
        self::assertSame('https://peer.example/notes/1', $payload['activity']['object']['inReplyTo']);
        self::assertContains('https://peer.example/users/bob', $payload['activity']['cc']);
    }

    public function testLocalReplyStillUsesALocalParent(): void
    {
        $this->server->createAccount('alice');
        $alice = $this->server->signIn('alice');

        $created = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A local thread',
            'visibility' => 'public',
        ], $alice['cookies'], $alice['csrf']);
        self::assertSame(201, $created->status, $created->body);
        /** @var array{post: array<string, mixed>} $createdBody */
        $createdBody = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);

        $response = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A local reply',
            'visibility' => 'public',
            'in_reply_to_id' => $createdBody['post']['id'],
        ], $alice['cookies'], $alice['csrf']);

        self::assertSame(201, $response->status, $response->body);
        /** @var array{post: array<string, mixed>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($createdBody['post']['id'], $body['post']['in_reply_to_id']);
    }

    public function testUnknownRemoteTargetIsNotFound(): void
    {
        $this->server->createAccount('alice');
        $alice = $this->server->signIn('alice');

        $response = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A reply',
            'visibility' => 'public',
            'in_reply_to_id' => 'https://peer.example/notes/missing',
        ], $alice['cookies'], $alice['csrf']);

        self::assertSame(404, $response->status, $response->body);
    }

    private function seedRemotePost(): void
    {
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content,'
            . ' content_warning, published_at, deleted_at)'
            . ' VALUES (:activity_id, :object_id, :object_author_id, :actor_id, :kind, :content,'
            . ' NULL, :published_at, NULL)',
            [
                'activity_id' => 'https://peer.example/activities/1',
                'object_id' => 'https://peer.example/notes/1',
                'object_author_id' => 'https://peer.example/users/bob',
                'actor_id' => 'https://peer.example/users/bob',
                'kind' => 'post',
                'content' => 'A remote post',
                'published_at' => '2026-11-20T10:00:00Z',
            ],
        );
    }
}
