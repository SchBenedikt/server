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

final class HostExtensionTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies = [];

    private string $aliceCsrf = '';

    /** @var array<string, string> */
    private array $bobCookies = [];

    private string $bobCsrf = '';

    private string $postId = '';

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice');
        $this->server->createAccount('bob');

        $alice = $this->server->signIn('alice');
        $this->aliceCookies = $alice['cookies'];
        $this->aliceCsrf = $alice['csrf'];

        $bob = $this->server->signIn('bob');
        $this->bobCookies = $bob['cookies'];
        $this->bobCsrf = $bob['csrf'];

        $created = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A post for the host interaction layer',
            'visibility' => 'public',
        ], $this->aliceCookies, $this->aliceCsrf);

        self::assertSame(201, $created->status);
        /** @var array{post: array{id: string}} $body */
        $body = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);
        $this->postId = $body['post']['id'];
    }

    public function testLikeIsIdempotentAndViewerSpecific(): void
    {
        $liked = $this->server->json('POST', '/api/v1/posts/' . $this->postId . '/like', [], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $liked->status);
        /** @var array{interaction: array{favourites_count: int, favourited: bool}} $likeBody */
        $likeBody = json_decode($liked->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $likeBody['interaction']['favourites_count']);
        self::assertTrue($likeBody['interaction']['favourited']);

        $repeated = $this->server->json('POST', '/api/v1/posts/' . $this->postId . '/like', [], $this->bobCookies, $this->bobCsrf);
        /** @var array{interaction: array{favourites_count: int}} $repeatedBody */
        $repeatedBody = json_decode($repeated->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $repeatedBody['interaction']['favourites_count']);

        $viewer = $this->server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $this->bobCookies,
            'query' => ['ids' => $this->postId],
        ]);
        /** @var array{interactions: array<string, array{favourited: bool}>} $viewerBody */
        $viewerBody = json_decode($viewer->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($viewerBody['interactions'][$this->postId]['favourited']);

        $authorView = $this->server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $this->aliceCookies,
            'query' => ['ids' => $this->postId],
        ]);
        /** @var array{interactions: array<string, array{favourited: bool}>} $authorBody */
        $authorBody = json_decode($authorView->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($authorBody['interactions'][$this->postId]['favourited']);

        $unliked = $this->server->json('DELETE', '/api/v1/posts/' . $this->postId . '/like', [], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $unliked->status);
        /** @var array{interaction: array{favourites_count: int, favourited: bool}} $unlikedBody */
        $unlikedBody = json_decode($unliked->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $unlikedBody['interaction']['favourites_count']);
        self::assertFalse($unlikedBody['interaction']['favourited']);
    }

    public function testLikeRequiresAuthenticationAndCsrf(): void
    {
        $anonymous = $this->server->send('POST', '/api/v1/posts/' . $this->postId . '/like');
        self::assertSame(401, $anonymous->status);

        $withoutCsrf = $this->server->send('POST', '/api/v1/posts/' . $this->postId . '/like', [
            'cookies' => $this->bobCookies,
        ]);
        self::assertSame(403, $withoutCsrf->status);
        self::assertStringContainsString('csrf_token_invalid', $withoutCsrf->body);

        self::assertSame(401, $this->server->send('POST', '/api/v1/posts/' . $this->postId . '/boost')->status);
        $boostWithoutCsrf = $this->server->send('POST', '/api/v1/posts/' . $this->postId . '/boost', [
            'cookies' => $this->bobCookies,
        ]);
        self::assertSame(403, $boostWithoutCsrf->status);
    }

    public function testBoostCanBeToggledAndProjectsViewerState(): void
    {
        $followed = $this->server->json('POST', '/api/v1/accounts/alice/follow', [], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $followed->status, $followed->body);

        $boost = '/api/v1/posts/' . $this->postId . '/boost';
        $response = $this->server->json('POST', $boost, [], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $response->status, $response->body);

        $state = $this->server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $this->bobCookies,
            'query' => ['ids' => $this->postId],
        ]);
        /** @var array{interactions: array<string, array{reblogs_count: int, reblogged: bool}>} $stateBody */
        $stateBody = json_decode($state->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($stateBody['interactions'][$this->postId]['reblogged']);
        self::assertSame(1, $stateBody['interactions'][$this->postId]['reblogs_count']);

        $home = $this->server->send('GET', '/api/v1/timelines/home', ['cookies' => $this->bobCookies]);
        /** @var array{posts: list<array<string, mixed>>} $homeBody */
        $homeBody = json_decode($home->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $homeBody['posts'], json_encode($homeBody['posts'], JSON_THROW_ON_ERROR));
        self::assertSame('post', $homeBody['posts'][0]['kind']);
        self::assertSame($this->postId, $homeBody['posts'][0]['id']);
        self::assertSame($this->postId, $homeBody['posts'][0]['object_id']);
        self::assertNull($homeBody['posts'][0]['boosted_by']);

        $removed = $this->server->json('DELETE', $boost, [], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $removed->status, $removed->body);
        $after = $this->server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $this->bobCookies,
            'query' => ['ids' => $this->postId],
        ]);
        /** @var array{interactions: array<string, array{reblogs_count: int, reblogged: bool}>} $afterBody */
        $afterBody = json_decode($after->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($afterBody['interactions'][$this->postId]['reblogged']);
        self::assertSame(0, $afterBody['interactions'][$this->postId]['reblogs_count']);

        $afterHome = $this->server->send('GET', '/api/v1/timelines/home', ['cookies' => $this->bobCookies]);
        /** @var array{posts: list<array<string, mixed>>} $afterHomeBody */
        $afterHomeBody = json_decode($afterHome->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $afterHomeBody['posts']);
        self::assertSame('post', $afterHomeBody['posts'][0]['kind']);
        self::assertSame($this->postId, $afterHomeBody['posts'][0]['id']);
    }

    public function testCachedRemotePostLikeUsesCoreFollowAndBlockPolicy(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $alice = $server->signIn('alice');
        $actor = 'https://remote.example/users/carol';
        $objectId = 'https://remote.example/objects/post-1';

        $server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-remote-like', 'alice', :actor, 'https://remote.example/inbox', '2026-11-22T10:00:00Z', 'accepted')",
            ['actor' => $actor],
        );
        $server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at, audience)'
            . " VALUES ('https://remote.example/activities/post-1', :object_id, :actor, :actor, 'post', 'Remote post', '2026-11-22T10:00:00Z', 'public')",
            ['object_id' => $objectId, 'actor' => $actor],
        );

        $path = '/api/v1/posts/' . rawurlencode($objectId) . '/like';
        $liked = $server->json('POST', $path, [], $alice['cookies'], $alice['csrf']);
        self::assertSame(200, $liked->status, $liked->body);
        /** @var array{interaction: array{favourited: bool, favourites_count: int}} $likedBody */
        $likedBody = json_decode($liked->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($likedBody['interaction']['favourited']);
        self::assertSame(1, $likedBody['interaction']['favourites_count'], 'A remote like is the viewer\'s own reaction count.');

        $queuedAfterLike = $server->runtime->app->queue->pendingCount();
        $repeated = $server->json('POST', $path, [], $alice['cookies'], $alice['csrf']);
        self::assertSame(200, $repeated->status, $repeated->body);
        self::assertSame($queuedAfterLike, $server->runtime->app->queue->pendingCount(), 'Repeating a remote like does not enqueue another activity.');

        $state = $server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $alice['cookies'],
            'query' => ['ids' => $objectId],
        ]);
        self::assertSame(200, $state->status);
        /** @var array{interactions: array<string, array{favourited: bool}>} $stateBody */
        $stateBody = json_decode($state->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($stateBody['interactions'][$objectId]['favourited']);

        $unliked = $server->json('DELETE', $path, [], $alice['cookies'], $alice['csrf']);
        self::assertSame(200, $unliked->status, $unliked->body);
        /** @var array{interaction: array{favourited: bool}} $unlikedBody */
        $unlikedBody = json_decode($unliked->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($unlikedBody['interaction']['favourited']);

        $boostPath = '/api/v1/posts/' . rawurlencode($objectId) . '/boost';
        $boosted = $server->json('POST', $boostPath, [], $alice['cookies'], $alice['csrf']);
        self::assertSame(200, $boosted->status, $boosted->body);
        /** @var array{interaction: array{reblogged: bool, reblogs_count: int}} $boostedBody */
        $boostedBody = json_decode($boosted->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($boostedBody['interaction']['reblogged']);
        self::assertSame(1, $boostedBody['interaction']['reblogs_count'], 'A remote boost is the viewer\'s own reaction count.');
        $queuedAfterBoost = $server->runtime->app->queue->pendingCount();
        $repeatedBoost = $server->json('POST', $boostPath, [], $alice['cookies'], $alice['csrf']);
        self::assertSame(200, $repeatedBoost->status, $repeatedBoost->body);
        self::assertSame($queuedAfterBoost, $server->runtime->app->queue->pendingCount(), 'Repeating a remote boost does not enqueue another Announce.');

        $boostState = $server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $alice['cookies'],
            'query' => ['ids' => $objectId],
        ]);
        /** @var array{interactions: array<string, array{reblogged: bool}>} $boostStateBody */
        $boostStateBody = json_decode($boostState->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($boostStateBody['interactions'][$objectId]['reblogged']);

        $unboosted = $server->json('DELETE', $boostPath, [], $alice['cookies'], $alice['csrf']);
        self::assertSame(200, $unboosted->status, $unboosted->body);
        /** @var array{interaction: array{reblogged: bool}} $unboostedBody */
        $unboostedBody = json_decode($unboosted->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($unboostedBody['interaction']['reblogged']);
    }

    public function testCachedRemotePostLikeAndBoostWorkWithoutAFollow(): void
    {
        $http = new FakeBoundedHttpClient();
        $http->json('https://remote.example/users/carol', [
            'id' => 'https://remote.example/users/carol',
            'type' => 'Person',
            'inbox' => 'https://remote.example/users/carol/inbox',
            'outbox' => 'https://remote.example/users/carol/outbox',
        ]);
        $resolver = new class () implements HostResolver {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $server = new TestServer([], $http, $resolver);
        $server->createAccount('alice');
        $alice = $server->signIn('alice');
        $objectId = 'https://remote.example/objects/post-unfollowed';
        $server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at, audience)'
            . " VALUES ('https://remote.example/activities/post-unfollowed', :object_id, 'https://remote.example/users/carol', 'https://remote.example/users/carol', 'post', 'Remote post', '2026-11-22T10:00:00Z', 'public')",
            ['object_id' => $objectId],
        );

        $response = $server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($objectId) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $response->status, $response->body);
        $liked = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($liked['interaction']['favourited']);

        $boost = $server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($objectId) . '/boost',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $boost->status, $boost->body);

        $state = $server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $alice['cookies'],
            'query' => ['ids' => $objectId],
        ]);
        self::assertSame(200, $state->status);
        $stateBody = json_decode($state->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($stateBody['interactions'][$objectId]['favourited']);
        self::assertTrue($stateBody['interactions'][$objectId]['reblogged']);
        self::assertContains('https://remote.example/users/carol', $http->requests);
    }

    public function testBatchPostHydrationReturnsFullPostsAndIgnoresMissingIds(): void
    {
        $response = $this->server->send('GET', '/api/v1/host/posts', [
            'query' => ['ids' => $this->postId . ',does-not-exist'],
        ]);

        self::assertSame(200, $response->status);
        /** @var array{posts: array<string, array{content: string}>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey($this->postId, $body['posts']);
        self::assertSame('A post for the host interaction layer', $body['posts'][$this->postId]['content']);
        self::assertArrayNotHasKey('does-not-exist', $body['posts']);
    }

    public function testProfileUpdateValidatesCsrfAndUpdatesTheHostDirectory(): void
    {
        $withoutCsrf = $this->server->json('PATCH', '/api/v1/host/profile', [
            'display_name' => 'Bobby',
        ], $this->bobCookies);
        self::assertSame(403, $withoutCsrf->status);

        $updated = $this->server->json('PATCH', '/api/v1/host/profile', [
            'display_name' => 'Bobby',
        ], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $updated->status);
        /** @var array{account: array{handle: string, display_name: string}} $body */
        $body = json_decode($updated->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('bob', $body['account']['handle']);
        self::assertSame('Bobby', $body['account']['display_name']);

        $profiles = $this->server->send('GET', '/api/v1/host/profiles');
        self::assertSame(200, $profiles->status);
        self::assertStringContainsString('"bob":"Bobby"', $profiles->body);
    }
}
