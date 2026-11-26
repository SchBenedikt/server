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

final class RemotePostImportTest extends TestCase
{
    private const OBJECT = 'https://peer.example/notes/live-1';

    private const ACTOR = 'https://peer.example/users/bob';

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
        $this->server->createAccount('alice');

        $this->http->json(self::OBJECT, [
            'id' => self::OBJECT,
            'type' => 'Note',
            'attributedTo' => self::ACTOR,
            'content' => '<p>A post found through search.</p>',
            'published' => '2026-11-20T10:00:00Z',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ]);
        $this->http->json(self::ACTOR, [
            'id' => self::ACTOR,
            'type' => 'Person',
            'preferredUsername' => 'bob',
            'inbox' => self::ACTOR . '/inbox',
        ]);
    }

    public function testLikeImportsAPostThatOnlyExistsOnItsOrigin(): void
    {
        $alice = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode(self::OBJECT) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        /** @var array{interaction: array{favourited: bool}} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['interaction']['favourited']);

        $stored = $this->server->runtime->database->fetchOne(
            'SELECT object_id, actor_id, object_author_id, content FROM social_remote_post WHERE object_id = :object',
            ['object' => self::OBJECT],
        );
        self::assertSame(self::OBJECT, $stored['object_id'] ?? null);
        self::assertSame(self::ACTOR, $stored['actor_id'] ?? null);
        self::assertSame(self::ACTOR, $stored['object_author_id'] ?? null);
        self::assertSame('A post found through search.', $stored['content'] ?? null);
    }

    public function testReplyImportsTheLiveParentAndKeepsItsAuthor(): void
    {
        $alice = $this->server->signIn('alice');

        $response = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A reply to a live discovery result.',
            'visibility' => 'public',
            'in_reply_to_id' => self::OBJECT,
        ], $alice['cookies'], $alice['csrf']);

        self::assertSame(201, $response->status, $response->body);
        /** @var array{post: array<string, mixed>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::OBJECT, $body['post']['in_reply_to_id']);

        $stored = $this->server->runtime->database->fetchOne(
            'SELECT actor_id FROM social_remote_post WHERE object_id = :object',
            ['object' => self::OBJECT],
        );
        self::assertSame(self::ACTOR, $stored['actor_id'] ?? null);

        $jobs = $this->server->runtime->database->fetchAll(
            'SELECT payload FROM social_job WHERE type = :type AND subject_id = :user',
            ['type' => 'federation.deliver', 'user' => 'alice'],
        );
        self::assertCount(1, $jobs);
    }

    public function testAPrivateLiveObjectIsNotImported(): void
    {
        $this->http->json(self::OBJECT, [
            'id' => self::OBJECT,
            'type' => 'Note',
            'attributedTo' => self::ACTOR,
            'content' => '<p>Followers only.</p>',
            'published' => '2026-11-20T10:00:00Z',
            'to' => [self::ACTOR . '/followers'],
        ]);
        $alice = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode(self::OBJECT) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(404, $response->status, $response->body);
        self::assertNull($this->server->runtime->database->fetchOne(
            'SELECT object_id FROM social_remote_post WHERE object_id = :object',
            ['object' => self::OBJECT],
        ));
    }
}
