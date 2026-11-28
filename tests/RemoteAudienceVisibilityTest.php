<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The remote-post store legitimately holds followers-only and unknown
 * audience rows. Discovery listings (trending, federated, for-you) and the
 * batch hydration endpoint must apply the same rule RemotePostAccess does:
 * verified public content is open, restricted content needs a follow of the
 * content author, and unlisted stays out of listings entirely.
 */
final class RemoteAudienceVisibilityTest extends TestCase
{
    private TestServer $server;

    private array $alice;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test');
        $this->server->createAccount('bob', 'bob@example.test');
        $this->alice = $this->server->signIn('alice');
    }

    public function testTrendingOnlyListsVerifiedPublicRemotePosts(): void
    {
        $this->remotePost('https://peer.example/activities/public', 'public');
        $this->remotePost('https://peer.example/activities/followers', 'followers');
        $this->remotePost('https://peer.example/activities/unknown', null);
        $this->remotePost('https://peer.example/activities/unlisted', 'unlisted');

        $response = $this->server->send('GET', '/api/v1/host/trending');
        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertContains('https://peer.example/activities/public', $body['posts']);
        self::assertNotContains('https://peer.example/activities/followers', $body['posts']);
        self::assertNotContains('https://peer.example/activities/unknown', $body['posts']);
        self::assertNotContains('https://peer.example/activities/unlisted', $body['posts']);
    }

    public function testForYouKeepsFollowersPostsOfFollowedAuthorsOnly(): void
    {
        $author = 'https://peer.example/users/carol';
        $this->follow('alice', $author);

        // Followers-only post by the followed author: visible to alice.
        $this->remotePost('https://peer.example/activities/followed-f', 'followers', $author);
        // Same audience, but the content author is somebody alice does not
        // follow even though the delivering actor is followed.
        $this->remotePost(
            'https://peer.example/activities/boosted-f',
            'followers',
            'https://peer.example/users/dave',
            actorId: $author,
        );
        // Unlisted never enters a listing, followed author or not.
        $this->remotePost('https://peer.example/activities/unlisted', 'unlisted', $author);

        $response = $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->alice['cookies']]);
        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertContains('https://peer.example/activities/followed-f', $body['posts']);
        self::assertNotContains('https://peer.example/activities/boosted-f', $body['posts']);
        self::assertNotContains('https://peer.example/activities/unlisted', $body['posts']);
    }

    public function testBatchHydrationEnforcesTheAudienceRulePerViewer(): void
    {
        $author = 'https://peer.example/users/carol';
        $this->remotePost('https://peer.example/activities/followers', 'followers', $author);
        $this->remotePost('https://peer.example/activities/public', 'public', $author);
        $this->follow('alice', $author);

        $ids = 'https://peer.example/activities/followers,https://peer.example/activities/public';

        // Anonymous readers get only the public row.
        $anonymous = $this->server->send('GET', '/api/v1/host/posts', ['query' => ['ids' => $ids]]);
        $anonymousPosts = json_decode($anonymous->body, true, 512, JSON_THROW_ON_ERROR)['posts'];
        self::assertArrayNotHasKey('https://peer.example/activities/followers', $anonymousPosts);
        self::assertArrayHasKey('https://peer.example/activities/public', $anonymousPosts);

        // A follower of the author reads both.
        $follower = $this->server->send('GET', '/api/v1/host/posts', [
            'query' => ['ids' => $ids],
            'cookies' => $this->alice['cookies'],
        ]);
        $followerPosts = json_decode($follower->body, true, 512, JSON_THROW_ON_ERROR)['posts'];
        self::assertArrayHasKey('https://peer.example/activities/followers', $followerPosts);
        self::assertArrayHasKey('https://peer.example/activities/public', $followerPosts);

        // A signed-in non-follower still cannot read the followers row.
        $bob = $this->server->signIn('bob');
        $stranger = $this->server->send('GET', '/api/v1/host/posts', [
            'query' => ['ids' => $ids],
            'cookies' => $bob['cookies'],
        ]);
        $strangerPosts = json_decode($stranger->body, true, 512, JSON_THROW_ON_ERROR)['posts'];
        self::assertArrayNotHasKey('https://peer.example/activities/followers', $strangerPosts);
        self::assertArrayHasKey('https://peer.example/activities/public', $strangerPosts);
    }

    public function testLiveUpdatesRespectTheAudienceRule(): void
    {
        $author = 'https://peer.example/users/carol';
        $this->follow('alice', $author);
        $published = $this->server->runtime->clock->now()->format('Y-m-d\\TH:i:s\\Z');

        $this->remotePost('https://peer.example/activities/followers', 'followers', $author, $published);
        $this->remotePost('https://peer.example/activities/unlisted', 'unlisted', $author, $published);

        $response = $this->server->send('GET', '/api/v1/host/updates', [
            'query' => ['since' => '2000-01-01T00:00:00Z'],
            'cookies' => $this->alice['cookies'],
        ]);
        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertContains('https://peer.example/activities/followers', $body['created']);
        self::assertNotContains('https://peer.example/activities/unlisted', $body['created']);
    }

    private function remotePost(
        string $activityId,
        ?string $audience,
        string $author = 'https://peer.example/users/carol',
        ?string $publishedAt = null,
        ?string $actorId = null,
        string $kind = 'post',
    ): void {
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at, audience)'
            . ' VALUES (:activity_id, :object_id, :author, :actor, :kind, :content, :published, :audience)',
            [
                'activity_id' => $activityId,
                'object_id' => $activityId,
                'author' => $author,
                'actor' => $actorId ?? $author,
                'kind' => $kind,
                'content' => 'Remote post ' . $activityId,
                'published' => $publishedAt
                    ?? $this->server->runtime->clock->now()->format('Y-m-d\\TH:i:s\\Z'),
                'audience' => $audience,
            ],
        );
    }

    private function follow(string $account, string $actorId): void
    {
        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES (:id, :account, :actor, 'https://peer.example/inbox', '2026-11-20T10:00:00Z', 'accepted')",
            ['id' => 'f-' . $account . '-' . md5($actorId), 'account' => $account, 'actor' => $actorId],
        );
    }
}
