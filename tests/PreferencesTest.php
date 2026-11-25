<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Account preferences, the account export and recommendation feedback.
 *
 * All three are host-owned: the core keeps no profile settings and no export,
 * and recommendation feedback is a private marker the For You ranker reads.
 */
final class PreferencesTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies;

    private string $aliceCsrf;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, false);
        $this->server->createAccount('bob', 'bob@example.test');

        $alice = $this->server->signIn('alice');
        $this->aliceCookies = $alice['cookies'];
        $this->aliceCsrf = $alice['csrf'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(\AlohaSocial\Core\Http\Response $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    public function testPreferencesDefaultThenPersistAndValidate(): void
    {
        $anonymous = $this->server->send('GET', '/api/v1/host/preferences');
        self::assertSame(401, $anonymous->status);

        $initial = $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $initial->status);
        $preferences = $this->decoded($initial)['preferences'];
        self::assertSame('public', $preferences['default_visibility']);
        self::assertSame('everyone', $preferences['dm_policy']);
        self::assertTrue($preferences['notify_follows']);
        self::assertFalse($preferences['mirror_likes_to_atproto']);
        self::assertFalse($preferences['mirror_boosts_to_atproto']);
        self::assertNull($preferences['mirror_atproto_account_did']);
        self::assertNull($preferences['updated_at']);

        $updated = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'default_visibility' => 'unlisted',
            'default_quote_policy' => 'followers',
            'dm_policy' => 'followers',
            'show_boosts' => false,
            'discoverable' => false,
            'notify_favourites' => false,
            'mirror_likes_to_atproto' => true,
            'mirror_boosts_to_atproto' => true,
            'mirror_atproto_account_did' => 'did:plc:alice123',
        ], $this->aliceCookies, $this->aliceCsrf);

        self::assertSame(200, $updated->status);
        $saved = $this->decoded($updated)['preferences'];
        self::assertSame('unlisted', $saved['default_visibility']);
        self::assertSame('followers', $saved['default_quote_policy']);
        self::assertSame('followers', $saved['dm_policy']);
        self::assertFalse($saved['show_boosts']);
        self::assertFalse($saved['discoverable']);
        self::assertFalse($saved['notify_favourites']);
        self::assertTrue($saved['mirror_likes_to_atproto']);
        self::assertTrue($saved['mirror_boosts_to_atproto']);
        self::assertSame('did:plc:alice123', $saved['mirror_atproto_account_did']);
        self::assertTrue($saved['notify_mentions']);
        self::assertNotNull($saved['updated_at']);

        $reloaded = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]),
        )['preferences'];
        self::assertSame('unlisted', $reloaded['default_visibility']);
        self::assertTrue($reloaded['mirror_likes_to_atproto']);
        self::assertTrue($reloaded['mirror_boosts_to_atproto']);
        self::assertSame('did:plc:alice123', $reloaded['mirror_atproto_account_did']);

        $invalidVisibility = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'default_visibility' => 'direct',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $invalidVisibility->status);
        self::assertStringContainsString('default_visibility', $invalidVisibility->body);

        $invalidDm = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'dm_policy' => 'strangers',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $invalidDm->status);

        $invalidFlag = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'show_boosts' => 'yes',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $invalidFlag->status);

        $invalidMirrorFlag = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'mirror_likes_to_atproto' => 'yes',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $invalidMirrorFlag->status);

        $invalidAtprotoDid = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'mirror_atproto_account_did' => 'not-a-did',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $invalidAtprotoDid->status);

        $csrf = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'dm_policy' => 'nobody',
        ], $this->aliceCookies);
        self::assertSame(403, $csrf->status);
    }

    public function testExportContainsOnlyTheViewersOwnData(): void
    {
        foreach (['alice', 'bob'] as $handle) {
            $this->server->runtime->database->execute(
                'INSERT INTO social_atproto_follow'
                . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
                . ' VALUES (:user, :did, :subject, :rkey, :uri, :cid, :state, :followed_at)',
                [
                    'user' => $handle,
                    'did' => 'did:plc:' . $handle,
                    'subject' => 'did:plc:followed-by-' . $handle,
                    'rkey' => 'follow-' . $handle,
                    'uri' => 'at://did:plc:' . $handle . '/app.bsky.graph.follow/follow-' . $handle,
                    'cid' => 'bafy' . $handle,
                    'state' => 'active',
                    'followed_at' => '2026-01-01T00:00:00Z',
                ],
            );
        }

        $this->server->runtime->database->execute(
            'INSERT INTO atproto_account'
            . ' (account_id, did, handle, pds_url, access_token, refresh_token, expires_at, active, created_at, updated_at)'
            . ' VALUES (:account_id, :did, :handle, :pds_url, :access, :refresh, 0, 1, :created, :updated)',
            [
                'account_id' => 'alice',
                'did' => 'did:plc:alice123',
                'handle' => 'alice.bsky.social',
                'pds_url' => 'https://pds.example',
                'access' => 'sealed-access-secret',
                'refresh' => 'sealed-refresh-secret',
                'created' => '2026-01-01T00:00:00Z',
                'updated' => '2026-01-01T00:00:00Z',
            ],
        );
        $mine = $this->server->runtime->app->posts->create(
            new CreatePost('alice', 'Mein exportierter Beitrag', Visibility::Public),
        )->id;
        $this->server->runtime->app->posts->create(
            new CreatePost('bob', 'Nicht meiner', Visibility::Public),
        );
        $stamp = '2026-11-24T12:00:00Z';
        $this->server->runtime->database->execute(
            'INSERT INTO featured_tag (account_id, tag, position, created_at)'
            . ' VALUES (:account, :tag, 0, :created)',
            ['account' => 'alice', 'tag' => 'favorite', 'created' => $stamp],
        );
        $this->server->runtime->database->execute(
            'INSERT INTO follow_request (id, target_account_id, actor_id, actor_handle, created_at)'
            . ' VALUES (:id, :target, :actor, :handle, :created)',
            ['id' => 'request-alice', 'target' => 'alice', 'actor' => 'https://remote.example/@person', 'handle' => 'person@remote.example', 'created' => $stamp],
        );
        foreach ([
            ['id' => 'story-alice', 'account' => 'alice', 'content' => 'Alice story'],
            ['id' => 'story-bob', 'account' => 'bob', 'content' => 'Bob story'],
        ] as $story) {
            $this->server->runtime->database->execute(
                'INSERT INTO social_story'
                . ' (id, owner_id, is_local, object_id, caption, remote_media_url, created_at, expires_at)'
                . ' VALUES (:id, :account, 1, :object_id, :content, :media, :created, :expires)',
                [
                    'id' => $story['id'],
                    'account' => $story['account'],
                    'object_id' => 'host-story:' . $story['id'],
                    'content' => $story['content'],
                    'media' => '/api/v1/host/stories/' . $story['id'] . '/background?background=ocean',
                    'created' => $stamp,
                    'expires' => '2026-11-25T12:00:00Z',
                ],
            );
        }

        $anonymous = $this->server->send('GET', '/api/v1/host/export');
        self::assertSame(401, $anonymous->status);

        $response = $this->server->send('GET', '/api/v1/host/export', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('application/json', (string) $response->header('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $response->header('Content-Disposition'));

        /** @var array<string, mixed> $export */
        $export = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('aloha-account-export', $export['format']);
        self::assertSame(1, $export['version']);
        self::assertSame('alice', $export['profile']['handle']);
        self::assertSame('alice@example.test', $export['profile']['email']);
        self::assertArrayNotHasKey('password_hash', $export['profile']);
        self::assertSame('http://localhost:3000', $export['instance']['url']);
        self::assertArrayHasKey('preferences', $export);
        self::assertArrayHasKey('posts', $export);
        self::assertArrayHasKey('following', $export);
        self::assertArrayHasKey('drafts', $export);
        self::assertSame(['favorite'], array_column($export['featured_tags'], 'tag'));
        self::assertSame(['person@remote.example'], array_column($export['follow_requests'], 'actor_handle'));
        self::assertSame(['story-alice'], array_column($export['stories'], 'id'));
        self::assertSame('alohasocial-account-archive', $export['core_archive']['format']);
        self::assertSame(
            ['did:plc:followed-by-alice'],
            array_column($export['core_archive']['data']['social_atproto_follow'], 'subject_did'),
        );
        self::assertSame('alice.bsky.social', $export['linked_bluesky_account']['handle']);
        self::assertStringNotContainsString('sealed-access-secret', $response->body);
        self::assertStringNotContainsString('sealed-refresh-secret', $response->body);

        $ids = array_column($export['posts'], 'id');
        self::assertContains($mine, $ids);
        self::assertNotContains('bob', array_column($export['posts'], 'author_id'));
        self::assertSame(1, count($export['posts']));
    }

    public function testForYouFeedbackHidesAndBoostsPosts(): void
    {
        $less = $this->server->runtime->app->posts->create(
            new CreatePost('bob', 'Weniger davon', Visibility::Public),
        )->id;
        $more = $this->server->runtime->app->posts->create(
            new CreatePost('bob', 'Mehr davon bitte', Visibility::Public),
        )->id;
        $neutral = $this->server->runtime->app->posts->create(
            new CreatePost('bob', 'Neutral', Visibility::Public),
        )->id;

        $anonymous = $this->server->json('POST', '/api/v1/host/for-you/feedback', [
            'post_id' => $less,
            'kind' => 'less',
        ], [], null);
        self::assertSame(401, $anonymous->status);

        $badKind = $this->server->json('POST', '/api/v1/host/for-you/feedback', [
            'post_id' => $less,
            'kind' => 'maybe',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $badKind->status);

        $this->server->json('POST', '/api/v1/host/for-you/feedback', [
            'post_id' => $less,
            'kind' => 'less',
        ], $this->aliceCookies, $this->aliceCsrf);
        $this->server->json('POST', '/api/v1/host/for-you/feedback', [
            'post_id' => $more,
            'kind' => 'more',
        ], $this->aliceCookies, $this->aliceCsrf);

        $feed = $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $feed->status);
        $body = $this->decoded($feed);

        self::assertNotContains($less, $body['posts']);
        self::assertContains($more, $body['posts']);
        self::assertContains($neutral, $body['posts']);
        self::assertSame($more, $body['posts'][0]);
        self::assertSame('Because you want to see more of this', $body['reasons'][$more]);

        // Replacing the marker with "less" removes the post again.
        $this->server->json('POST', '/api/v1/host/for-you/feedback', [
            'post_id' => $more,
            'kind' => 'less',
        ], $this->aliceCookies, $this->aliceCsrf);
        $second = $this->decoded(
            $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]),
        );
        self::assertNotContains($more, $second['posts']);
    }

    public function testForYouIncludesOnlyRemotePostsFromActiveAtprotoFollows(): void
    {
        $now = $this->server->runtime->clock->now();
        $repository = new PdoRemotePostRepository($this->server->runtime->database);
        $followedPost = 'at://did:plc:followed/app.bsky.feed.post/3kfollowed';
        $unfollowedPost = 'at://did:plc:unfollowed/app.bsky.feed.post/3kunfollowed';
        foreach ([
            [$followedPost, 'did:plc:followed', 'From an ATProto account you follow'],
            [$unfollowedPost, 'did:plc:unfollowed', 'From an ATProto account you do not follow'],
        ] as [$uri, $did, $content]) {
            $repository->insert(new RemotePost(
                activityId: $uri,
                objectId: $uri,
                actorId: $did,
                kind: RemotePostKind::Post,
                content: $content,
                contentWarning: null,
                publishedAt: $now,
                objectAuthorId: $did,
            ));
        }
        $this->server->runtime->database->execute(
            'INSERT INTO social_atproto_follow'
            . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
            . ' VALUES (:user, :account_did, :subject_did, :rkey, :uri, :cid, :state, :followed_at)',
            [
                'user' => 'alice',
                'account_did' => 'did:plc:alice',
                'subject_did' => 'did:plc:followed',
                'rkey' => '3kfollow',
                'uri' => 'at://did:plc:alice/app.bsky.graph.follow/3kfollow',
                'cid' => 'bafyfollow',
                'state' => 'active',
                'followed_at' => Time::format($now),
            ],
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_atproto_follow'
            . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
            . ' VALUES (:user, :account_did, :subject_did, :rkey, NULL, NULL, :state, :followed_at)',
            [
                'user' => 'alice',
                'account_did' => 'did:plc:alice',
                'subject_did' => 'did:plc:unfollowed',
                'rkey' => '3kunfollowed',
                'state' => 'pending',
                'followed_at' => Time::format($now),
            ],
        );

        $feed = $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $feed->status, $feed->body);
        $body = $this->decoded($feed);
        self::assertContains($followedPost, $body['posts']);
        self::assertNotContains($unfollowedPost, $body['posts']);
        self::assertSame('Because you follow this account', $body['reasons'][$followedPost]);
    }

    public function testPreferencesAreScopedToOneAccount(): void
    {
        $this->server->json('PATCH', '/api/v1/host/preferences', [
            'default_visibility' => 'followers',
        ], $this->aliceCookies, $this->aliceCsrf);

        $bob = $this->server->signIn('bob');
        $bobPreferences = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $bob['cookies']]),
        )['preferences'];
        self::assertSame('public', $bobPreferences['default_visibility']);
    }
}
