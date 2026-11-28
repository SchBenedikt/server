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

    public function testUiLocalePersistsAndValidates(): void
    {
        $initial = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]),
        )['preferences'];
        self::assertNull($initial['ui_locale']);

        $updated = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'ui_locale' => 'de-AT',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $updated->status);
        self::assertSame('de-AT', $this->decoded($updated)['preferences']['ui_locale']);

        $reloaded = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]),
        )['preferences'];
        self::assertSame('de-AT', $reloaded['ui_locale']);

        // An empty string resets to the browser default.
        $reset = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'ui_locale' => '',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $reset->status);
        self::assertNull($this->decoded($reset)['preferences']['ui_locale']);

        foreach (['<script>', 'en_US!', str_repeat('a', 40), 42] as $bad) {
            $invalid = $this->server->json('PATCH', '/api/v1/host/preferences', [
                'ui_locale' => $bad,
            ], $this->aliceCookies, $this->aliceCsrf);
            self::assertSame(422, $invalid->status, 'ui_locale ' . var_export($bad, true));
        }
    }

    public function testOnboardingDismissalPersistsPerAccount(): void
    {
        $initial = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]),
        )['preferences'];
        self::assertFalse($initial['onboarding_dismissed']);
        self::assertNull($initial['onboarding_dismissed_at']);

        $dismissed = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'onboarding_dismissed' => true,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $dismissed->status);
        $saved = $this->decoded($dismissed)['preferences'];
        self::assertTrue($saved['onboarding_dismissed']);
        self::assertNotNull($saved['onboarding_dismissed_at']);

        $reloaded = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]),
        )['preferences'];
        self::assertTrue($reloaded['onboarding_dismissed']);
        self::assertSame($saved['onboarding_dismissed_at'], $reloaded['onboarding_dismissed_at']);

        // Re-dismissing must keep the original timestamp.
        $again = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'onboarding_dismissed' => true,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $again->status);
        self::assertSame(
            $saved['onboarding_dismissed_at'],
            $this->decoded($again)['preferences']['onboarding_dismissed_at'],
        );

        // The flag is per account: bob's preferences stay untouched.
        $bob = $this->server->signIn('bob');
        $bobPreferences = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $bob['cookies']]),
        )['preferences'];
        self::assertFalse($bobPreferences['onboarding_dismissed']);

        $restored = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'onboarding_dismissed' => false,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $restored->status);
        $restoredPreferences = $this->decoded($restored)['preferences'];
        self::assertFalse($restoredPreferences['onboarding_dismissed']);
        self::assertNull($restoredPreferences['onboarding_dismissed_at']);

        $invalid = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'onboarding_dismissed' => 'yes',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $invalid->status);
    }

    public function testDismissedAnnouncementsPersistPerAccount(): void
    {
        $initial = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]),
        )['preferences'];
        self::assertSame([], $initial['dismissed_announcements']);

        $id = str_repeat('a', 24);
        $saved = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'dismissed_announcements' => [$id],
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $saved->status);
        self::assertSame([$id], $this->decoded($saved)['preferences']['dismissed_announcements']);

        // A later unrelated save keeps the dismissal list.
        $kept = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'show_boosts' => false,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $kept->status);
        self::assertSame([$id], $this->decoded($kept)['preferences']['dismissed_announcements']);

        // Bob's list is untouched.
        $bob = $this->server->signIn('bob');
        $bobPreferences = $this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $bob['cookies']]),
        )['preferences'];
        self::assertSame([], $bobPreferences['dismissed_announcements']);

        $cleared = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'dismissed_announcements' => [],
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $cleared->status);
        self::assertSame([], $this->decoded($cleared)['preferences']['dismissed_announcements']);

        $invalid = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'dismissed_announcements' => ['not-an-id'],
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $invalid->status);
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

    public function testUndiscoverableAuthorLeavesRecommendationSurfaces(): void
    {
        $post = $this->server->runtime->app->posts->create(
            new CreatePost('bob', 'Verborgen #secret', Visibility::Public),
        )->id;
        $mine = $this->server->runtime->app->posts->create(
            new CreatePost('alice', 'Sichtbar #open', Visibility::Public),
        )->id;

        $bob = $this->server->signIn('bob');
        $this->server->json('PATCH', '/api/v1/host/preferences', [
            'discoverable' => false,
        ], $bob['cookies'], $bob['csrf']);

        // Another reader's For You drops the post; the author's own feed
        // still shows it.
        $aliceFeed = $this->decoded(
            $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]),
        );
        self::assertNotContains($post, $aliceFeed['posts']);
        self::assertContains($mine, $aliceFeed['posts']);

        $bobFeed = $this->decoded(
            $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $bob['cookies']]),
        );
        self::assertContains($post, $bobFeed['posts']);

        // Anonymous trending and the hashtag trends drop it too.
        $trending = $this->decoded($this->server->send('GET', '/api/v1/host/trending'));
        self::assertNotContains($post, $trending['posts']);
        self::assertContains($mine, $trending['posts']);

        $tags = array_column(
            $this->decoded($this->server->send('GET', '/api/v1/host/trends'))['tags'],
            'tag',
        );
        self::assertContains('open', $tags);
        self::assertNotContains('secret', $tags);

        // Re-enabling restores the surfaces.
        $this->server->json('PATCH', '/api/v1/host/preferences', [
            'discoverable' => true,
        ], $bob['cookies'], $bob['csrf']);
        $restored = $this->decoded(
            $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]),
        );
        self::assertContains($post, $restored['posts']);
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

    public function testRecommendationOptOutClearsStoredSignalsAndEmptiesTheFeed(): void
    {
        $db = $this->server->runtime->database;
        $db->execute(
            'INSERT INTO recommendation_snapshot (id_hash, account_id, feed_kind, payload, created_at, expires_at)'
            . " VALUES ('snap-1', 'alice', 'for-you', '[]', '2026-11-28T10:00:00Z', '2026-11-29T10:00:00Z')",
        );
        $db->execute(
            'INSERT INTO recommendation_feedback (account_id, post_id, kind, created_at)'
            . " VALUES ('alice', 'post-1', 'more', '2026-11-28T10:00:00Z')",
        );
        $db->execute(
            'INSERT INTO recommendation_impression (account_id, post_id, author_id, seen_at, dwell_ms)'
            . " VALUES ('alice', 'post-1', 'acct-bob', '2026-11-28T10:00:00Z', 5000)",
        );

        $updated = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'recommendations_opt_out' => true,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $updated->status, $updated->body);
        self::assertTrue($this->decoded($updated)['preferences']['recommendations_opt_out']);

        foreach (['recommendation_snapshot', 'recommendation_feedback', 'recommendation_impression'] as $table) {
            self::assertSame(
                0,
                (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $table . ' WHERE account_id = :account', ['account' => 'alice']),
                $table . ' must be cleared on opt-out',
            );
        }

        $feed = $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $feed->status, $feed->body);
        $body = $this->decoded($feed);
        self::assertSame([], $body['posts'] ?? null);

        $impressions = $this->server->json('POST', '/api/v1/host/for-you/impressions', [
            'items' => [['post_id' => 'post-9', 'dwell_ms' => 1000]],
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(202, $impressions->status, $impressions->body);
        self::assertSame(0, $this->decoded($impressions)['recorded']);
        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM recommendation_impression WHERE account_id = :account', ['account' => 'alice']),
        );

        $reenabled = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'recommendations_opt_out' => false,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertFalse($this->decoded($reenabled)['preferences']['recommendations_opt_out']);
    }
}
