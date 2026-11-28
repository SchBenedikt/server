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

/**
 * Card views must never wait on an origin: the interactions endpoint only
 * records which remote counters are stale, and the worker drains that queue
 * before its generic newest-first scan.
 */
final class RemoteEngagementRefresherTest extends TestCase
{
    private function server(FakeBoundedHttpClient $http): TestServer
    {
        return new TestServer([], $http, new class implements HostResolver {
            public function resolve(string $host): array
            {
                return in_array($host, ['mastodon.test', 'remote.example'], true)
                    ? ['93.184.216.34']
                    : [];
            }
        });
    }

    private function seedRemotePost(
        TestServer $server,
        string $objectId,
        string $actorId,
        string $publishedAt = '2026-11-20T10:00:00Z',
    ): void {
        $server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at, audience)'
            . " VALUES (:activity_id, :object_id, :author, :actor, 'post', 'Federated post',"
            . ' :published_at, \'public\')',
            [
                'activity_id' => $objectId,
                'object_id' => $objectId,
                'author' => $actorId,
                'actor' => $actorId,
                'published_at' => $publishedAt,
            ],
        );
        $server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES (:id, 'alice', :actor, 'https://remote.example/inbox', '2026-11-20T10:00:00Z', 'accepted')",
            ['id' => 'f-' . md5($objectId), 'actor' => $actorId],
        );
    }

    public function testInteractionsQueuesStaleCountersWithoutRemoteIo(): void
    {
        $http = new FakeBoundedHttpClient();
        $server = $this->server($http);
        $server->createAccount('alice', 'alice@example.test');
        $session = $server->signIn('alice');

        $objectId = 'https://mastodon.test/users/alice/statuses/123';
        $this->seedRemotePost($server, $objectId, 'https://mastodon.test/users/alice');

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $objectId],
            'cookies' => $session['cookies'],
        ]);

        self::assertSame(200, $response->status);
        self::assertSame([], $http->requests, 'The read path must not call the origin.');

        $queued = $server->runtime->database->fetchAll(
            'SELECT object_id FROM remote_engagement_request',
        );
        self::assertSame([$objectId], array_column($queued, 'object_id'));

        // The worker drain performs the fetch the request skipped.
        $http->json('https://mastodon.test/api/v1/statuses/123', [
            'id' => '123',
            'favourites_count' => 7,
            'replies_count' => 2,
            'reblogs_count' => 1,
        ]);
        $refreshed = $server->runtime->remoteEngagementRefresher->refresh(10);

        self::assertSame(1, $refreshed);
        self::assertSame(
            ['https://mastodon.test/api/v1/statuses/123'],
            $http->requests,
        );
        self::assertSame([], $server->runtime->database->fetchAll(
            'SELECT object_id FROM remote_engagement_request',
        ));
        $counts = $server->runtime->remoteEngagement->counts([$objectId])[$objectId];
        self::assertSame(7, $counts['likes']);
        self::assertSame(1, $counts['boosts']);
        self::assertSame(2, $counts['replies']);
    }

    public function testRefreshServesQueuedPostsBeforeTheGenericScan(): void
    {
        $http = new FakeBoundedHttpClient();
        $server = $this->server($http);
        $server->createAccount('alice', 'alice@example.test');

        $queuedId = 'https://mastodon.test/users/alice/statuses/200';
        $scanId = 'https://mastodon.test/users/bob/statuses/100';
        $this->seedRemotePost($server, $queuedId, 'https://mastodon.test/users/alice');
        $this->seedRemotePost($server, $scanId, 'https://mastodon.test/users/bob');

        $server->runtime->remoteEngagementRefresher->requestRefresh([$queuedId]);

        $http->json('https://mastodon.test/api/v1/statuses/200', [
            'id' => '200',
            'favourites_count' => 5,
            'replies_count' => 0,
            'reblogs_count' => 0,
        ]);
        $http->json('https://mastodon.test/api/v1/statuses/100', [
            'id' => '100',
            'favourites_count' => 9,
            'replies_count' => 0,
            'reblogs_count' => 0,
        ]);

        // A budget of one must go to the visible post, not the newer scan row.
        $refreshed = $server->runtime->remoteEngagementRefresher->refresh(1);

        self::assertSame(1, $refreshed);
        self::assertSame(
            ['https://mastodon.test/api/v1/statuses/200'],
            $http->requests,
        );
        self::assertArrayHasKey($queuedId, $server->runtime->remoteEngagement->counts([$queuedId]));
        self::assertArrayNotHasKey($scanId, $server->runtime->remoteEngagement->counts([$scanId]));
    }

    public function testAgeAdaptiveTtlSkipsFreshOldPosts(): void
    {
        $http = new FakeBoundedHttpClient();
        $server = $this->server($http);
        $server->createAccount('alice', 'alice@example.test');

        // Both snapshots were fetched an hour ago — older than the 15 minute
        // base TTL, so the SQL candidate scan returns both.
        $fetchedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $oldId = 'https://mastodon.test/users/alice/statuses/900';
        $youngId = 'https://mastodon.test/users/bob/statuses/901';
        $this->seedRemotePost($server, $oldId, 'https://mastodon.test/users/alice', '2020-01-01T00:00:00Z');
        $this->seedRemotePost($server, $youngId, 'https://mastodon.test/users/bob', gmdate('Y-m-d\TH:i:s\Z'));

        foreach ([$oldId, $youngId] as $objectId) {
            $server->runtime->database->execute(
                'INSERT INTO social_remote_engagement (object_id, likes, boosts, replies, fetched_at)'
                . ' VALUES (:object_id, 3, 1, 2, :fetched_at)',
                ['object_id' => $objectId, 'fetched_at' => $fetchedAt],
            );
        }

        $http->json('https://mastodon.test/api/v1/statuses/901', [
            'id' => '901',
            'favourites_count' => 4,
            'replies_count' => 0,
            'reblogs_count' => 0,
        ]);

        $refreshed = $server->runtime->remoteEngagementRefresher->refresh(10);

        // The six-year-old post earns the 8x TTL and stays fresh; only the
        // young post is re-fetched.
        self::assertSame(1, $refreshed);
        self::assertSame(
            ['https://mastodon.test/api/v1/statuses/901'],
            $http->requests,
        );
    }

    public function testRequestRefreshDeduplicatesAndValidatesIds(): void
    {
        $server = new TestServer();

        $server->runtime->remoteEngagementRefresher->requestRefresh([
            'https://remote.example/objects/1',
            'https://remote.example/objects/1',
            'not a url at all::::',
            str_repeat('x', 600),
            '',
        ]);

        $queued = $server->runtime->database->fetchAll(
            'SELECT object_id FROM remote_engagement_request',
        );
        self::assertSame(['https://remote.example/objects/1'], array_column($queued, 'object_id'));
    }
}
