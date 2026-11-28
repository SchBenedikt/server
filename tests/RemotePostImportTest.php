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
                return in_array($host, ['peer.example', 'mastodon.example'], true) ? ['8.8.8.8'] : [];
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

    public function testCachedRemotePostOpensByObjectIdWhenOriginIsUnavailable(): void
    {
        $this->http->html(self::OBJECT, 'origin unavailable', 503);
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, object_type, content, published_at, audience)'
            . ' VALUES (:activity_id, :object_id, :author, :actor, \'post\', \'note\', :content, :published_at, \'public\')',
            [
                'activity_id' => 'https://peer.example/activities/live-1',
                'object_id' => self::OBJECT,
                'author' => self::ACTOR,
                'actor' => self::ACTOR,
                'content' => '<p>Cached remote ✅</p>',
                'published_at' => '2026-11-20T10:00:00Z',
            ],
        );
        $alice = $this->server->signIn('alice');

        $batch = $this->server->send('GET', '/api/v1/host/posts', [
            'cookies' => $alice['cookies'],
            'query' => ['ids' => self::OBJECT],
        ]);
        self::assertSame(200, $batch->status, $batch->body);
        $batchBody = json_decode($batch->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::OBJECT, $batchBody['posts'][self::OBJECT]['object_id'] ?? null);
        self::assertTrue($batchBody['posts'][self::OBJECT]['is_remote'] ?? false);

        $detail = $this->server->send('GET', '/api/v1/host/remote-post', [
            'cookies' => $alice['cookies'],
            'query' => ['id' => self::OBJECT],
        ]);
        self::assertSame(200, $detail->status, $detail->body);
        $detailBody = json_decode($detail->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::OBJECT, $detailBody['post']['object_id'] ?? null);
        self::assertTrue($detailBody['post']['is_remote'] ?? false);
        self::assertStringContainsString('Cached remote', $detailBody['post']['content'] ?? '');
    }

    public function testMastodonNotificationKeepsItsRemoteTargetWhenStatusIsUnavailable(): void
    {
        $http = new FakeBoundedHttpClient();
        $http->html(
            'https://peer.example/users/bob',
            'origin unavailable',
            503,
        );
        $server = new TestServer(['mastodon_api' => true], $http, new class implements HostResolver {
            public function resolve(string $host): array
            {
                return in_array($host, ['peer.example', 'mastodon.example'], true) ? ['8.8.8.8'] : [];
            }
        });
        $server->createAccount('alice');
        $server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, object_type, content, published_at)'
            . ' VALUES (:activity_id, :object_id, :author, :actor, \'post\', \'note\', :content, :published_at)',
            [
                'activity_id' => 'https://peer.example/activities/notification-1',
                'object_id' => 'https://peer.example/notes/notification-1',
                'author' => 'https://peer.example/users/bob',
                'actor' => 'https://peer.example/users/bob',
                'content' => '<p>Remote notification</p>',
                'published_at' => '2026-11-20T10:00:00Z',
            ],
        );
        $server->runtime->database->execute(
            'INSERT INTO social_notification'
            . ' (id, recipient_id, actor_id, type, post_id, created_at, status)'
            . ' VALUES (:id, :recipient, :actor, \'mention\', :post, :created_at, \'visible\')',
            [
                'id' => 'notification-remote-1',
                'recipient' => 'alice',
                'actor' => 'https://peer.example/users/bob',
                'post' => 'https://peer.example/notes/notification-1',
                'created_at' => '2026-11-20T10:00:00Z',
            ],
        );
        $alice = $server->signIn('alice');

        $response = $server->send('GET', '/api/mastodon/v1/notifications', [
            'cookies' => $alice['cookies'],
            'query' => ['limit' => '20'],
        ]);
        self::assertSame(200, $response->status, $response->body);
        $rows = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $remote = null;
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === 'notification-remote-1') {
                $remote = $row;
            }
        }
        self::assertIsArray($remote, $response->body);
        self::assertArrayNotHasKey('status', $remote);
        self::assertSame('https://peer.example/notes/notification-1', $remote['post_id'] ?? null);
    }

    public function testAudienceLessLiveObjectIsNotImported(): void
    {
        $this->http->json(self::OBJECT, [
            'id' => self::OBJECT,
            'type' => 'Note',
            'attributedTo' => self::ACTOR,
            'content' => '<p>No audience declared.</p>',
            'published' => '2026-11-20T10:00:00Z',
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

    public function testDirectedLiveObjectIsNotImported(): void
    {
        $this->http->json(self::OBJECT, [
            'id' => self::OBJECT,
            'type' => 'Note',
            'attributedTo' => self::ACTOR,
            'content' => '<p>Addressed to one actor only.</p>',
            'published' => '2026-11-20T10:00:00Z',
            'to' => [self::ACTOR],
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

    public function testMastodonStatusWithoutVisibilityIsNotImported(): void
    {
        $url = 'https://peer.example/@bob/42';
        $this->http->json('https://peer.example/api/v1/statuses/42', [
            'uri' => self::OBJECT,
            'url' => $url,
            'content' => '<p>Status without a visibility field.</p>',
            'created_at' => '2026-11-20T10:00:00Z',
            'account' => ['uri' => self::ACTOR, 'acct' => 'bob'],
        ]);
        $alice = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($url) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(404, $response->status, $response->body);
        self::assertNull($this->server->runtime->database->fetchOne(
            'SELECT object_id FROM social_remote_post WHERE object_id = :object',
            ['object' => $url],
        ));
    }

    public function testMastodonUnlistedStatusImportsAsUnlisted(): void
    {
        $url = 'https://peer.example/@bob/43';
        $this->http->json('https://peer.example/api/v1/statuses/43', [
            'uri' => self::OBJECT,
            'url' => $url,
            'content' => '<p>Link-only post.</p>',
            'visibility' => 'unlisted',
            'created_at' => '2026-11-20T10:00:00Z',
            'account' => ['uri' => self::ACTOR, 'acct' => 'bob'],
        ]);
        $alice = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($url) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $stored = $this->server->runtime->database->fetchOne(
            'SELECT audience FROM social_remote_post WHERE object_id = :object',
            ['object' => $url],
        );
        self::assertSame('unlisted', $stored['audience'] ?? null);
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

    public function testLikeImportsAPostFoundThroughTheMastodonApi(): void
    {
        $object = 'https://mastodon.example/users/bob/statuses/42';
        $this->http->json('https://mastodon.example/api/v1/statuses/42', [
            'id' => '42',
            'uri' => $object,
            'url' => 'https://mastodon.example/@bob/42',
            'content' => '<p>Hello from Mastodon.</p>',
            'visibility' => 'public',
            'created_at' => '2026-11-21T09:00:00Z',
            'account' => [
                'uri' => self::ACTOR,
                'acct' => 'bob@mastodon.example',
                'display_name' => 'Bob',
            ],
        ]);
        $alice = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($object) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $stored = $this->server->runtime->database->fetchOne(
            'SELECT object_id, actor_id, content FROM social_remote_post WHERE object_id = :object',
            ['object' => $object],
        );
        self::assertSame($object, $stored['object_id'] ?? null);
        self::assertSame('Hello from Mastodon.', $stored['content'] ?? null);
    }

    public function testLikeHealsALegacyRowWithoutAudienceWhenOriginProvesPublic(): void
    {
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, object_type, content, published_at)'
            . ' VALUES (:activity_id, :object_id, :author, :actor, \'post\', \'note\', :content, :published_at)',
            [
                'activity_id' => 'https://peer.example/activities/legacy-1',
                'object_id' => self::OBJECT,
                'author' => self::ACTOR,
                'actor' => self::ACTOR,
                'content' => '<p>Legacy cached post.</p>',
                'published_at' => '2026-11-01T10:00:00Z',
            ],
        );
        $alice = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode(self::OBJECT) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $stored = $this->server->runtime->database->fetchOne(
            'SELECT audience FROM social_remote_post WHERE object_id = :object',
            ['object' => self::OBJECT],
        );
        self::assertSame('public', $stored['audience'] ?? null);
    }

    public function testLikeKeepsTheFollowGateWhenOriginCannotProvePublic(): void
    {
        $this->http->json(self::OBJECT, [
            'id' => self::OBJECT,
            'type' => 'Note',
            'attributedTo' => self::ACTOR,
            'content' => '<p>Followers only.</p>',
            'published' => '2026-11-20T10:00:00Z',
            'to' => ['https://remote.example/users/bob/followers'],
        ]);
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, object_type, content, published_at)'
            . ' VALUES (:activity_id, :object_id, :author, :actor, \'post\', \'note\', :content, :published_at)',
            [
                'activity_id' => 'https://peer.example/activities/legacy-2',
                'object_id' => self::OBJECT,
                'author' => self::ACTOR,
                'actor' => self::ACTOR,
                'content' => '<p>Legacy cached post.</p>',
                'published_at' => '2026-11-01T10:00:00Z',
            ],
        );
        $alice = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode(self::OBJECT) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(403, $response->status, $response->body);
        $stored = $this->server->runtime->database->fetchOne(
            'SELECT audience FROM social_remote_post WHERE object_id = :object',
            ['object' => self::OBJECT],
        );
        self::assertIsArray($stored);
        self::assertNull($stored['audience']);
    }
}
