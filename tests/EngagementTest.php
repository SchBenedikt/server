<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Drafts, archived posts and the For You ranker.
 *
 * These features live entirely on the host: a draft is one unsent
 * composition, an archive is a private marker on an own post, and For You is a
 * read-only ranking over the core's public posts and social graph.
 */
final class EngagementTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies;

    private string $aliceCsrf;

    /** @var array<string, string> */
    private array $bobCookies;

    private string $bobCsrf;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $this->server->createAccount('bob', 'bob@example.test');

        $alice = $this->server->signIn('alice');
        $this->aliceCookies = $alice['cookies'];
        $this->aliceCsrf = $alice['csrf'];

        $bob = $this->server->signIn('bob');
        $this->bobCookies = $bob['cookies'];
        $this->bobCsrf = $bob['csrf'];
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

    private function post(string $authorId, string $content, Visibility $visibility = Visibility::Public): string
    {
        return $this->server->runtime->app->posts
            ->create(new CreatePost($authorId, $content, $visibility))
            ->id;
    }

    public function testDraftsAreScopedToOneAccountAndReplaceTheSameContext(): void
    {
        $anonymous = $this->server->send('GET', '/api/v1/host/drafts');
        self::assertSame(401, $anonymous->status);

        $created = $this->server->json('POST', '/api/v1/host/drafts', [
            'context' => '',
            'content' => '  Ein halber Gedanke  ',
            'visibility' => 'public',
            'attachment_ids' => ['att-1', 'att-1', ''],
        ], $this->aliceCookies, $this->aliceCsrf);

        self::assertSame(201, $created->status);
        $draft = $this->decoded($created)['draft'];
        self::assertSame('Ein halber Gedanke', $draft['content']);
        self::assertSame(['att-1'], $draft['attachment_ids']);
        self::assertNull($draft['poll']);

        $replaced = $this->server->json('POST', '/api/v1/host/drafts', [
            'context' => '',
            'content' => 'Gedanke zu Ende',
            'content_warning' => 'CW',
            'visibility' => 'followers',
            'poll' => [
                'options' => ['Ja', 'Nein'],
                'multiple' => false,
                'hide_totals' => true,
                'expires_in' => 3600,
            ],
        ], $this->aliceCookies, $this->aliceCsrf);

        self::assertSame(201, $replaced->status);
        $updated = $this->decoded($replaced)['draft'];
        self::assertSame($draft['id'], $updated['id']);
        self::assertSame('Gedanke zu Ende', $updated['content']);
        self::assertSame('CW', $updated['content_warning']);
        self::assertSame('followers', $updated['visibility']);
        self::assertTrue($updated['poll']['hide_totals']);
        self::assertSame(['Ja', 'Nein'], $updated['poll']['options']);

        $list = $this->server->send('GET', '/api/v1/host/drafts', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $list->status);
        self::assertCount(1, $this->decoded($list)['drafts']);

        $bobList = $this->server->send('GET', '/api/v1/host/drafts', ['cookies' => $this->bobCookies]);
        self::assertSame(200, $bobList->status);
        self::assertCount(0, $this->decoded($bobList)['drafts']);

        $bobDelete = $this->server->json(
            'DELETE',
            '/api/v1/host/drafts/' . $updated['id'],
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(404, $bobDelete->status);

        $deleted = $this->server->json(
            'DELETE',
            '/api/v1/host/drafts/' . $updated['id'],
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $deleted->status);
        self::assertSame(0, count($this->server->runtime->drafts->forAccount('alice')));
    }

    public function testDraftsValidateContentVisibilityAndPoll(): void
    {
        $empty = $this->server->json('POST', '/api/v1/host/drafts', [
            'content' => '   ',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $empty->status);
        self::assertStringContainsString('content', $empty->body);

        $visibility = $this->server->json('POST', '/api/v1/host/drafts', [
            'content' => 'Hallo',
            'visibility' => 'direct',
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $visibility->status);

        $poll = $this->server->json('POST', '/api/v1/host/drafts', [
            'content' => 'Hallo',
            'poll' => ['options' => ['Nur eine']],
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(422, $poll->status);

        $csrf = $this->server->json('POST', '/api/v1/host/drafts', [
            'content' => 'Ohne Token',
        ], $this->aliceCookies);
        self::assertSame(403, $csrf->status);
        self::assertStringContainsString('csrf_token_invalid', $csrf->body);
    }

    public function testArchivingIsOwnerOnlyReversibleAndPrivate(): void
    {
        $own = $this->post('alice', 'Ein alter Beitrag');
        $foreign = $this->post('bob', 'Nicht deiner');

        $anonymous = $this->server->send('GET', '/api/v1/host/archived');
        self::assertSame(401, $anonymous->status);

        $forbidden = $this->server->json(
            'POST',
            '/api/v1/host/archived',
            ['post_id' => $foreign],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(403, $forbidden->status);

        $archived = $this->server->json(
            'POST',
            '/api/v1/host/archived',
            ['post_id' => $own],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(201, $archived->status);
        self::assertSame($own, $this->decoded($archived)['archived']['post_id']);

        $again = $this->server->json(
            'POST',
            '/api/v1/host/archived',
            ['post_id' => $own],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(201, $again->status);

        $list = $this->server->send('GET', '/api/v1/host/archived', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $list->status);
        $rows = $this->decoded($list)['archived'];
        self::assertCount(1, $rows);
        self::assertSame($own, $rows[0]['post_id']);

        $bobList = $this->server->send('GET', '/api/v1/host/archived', ['cookies' => $this->bobCookies]);
        self::assertCount(0, $this->decoded($bobList)['archived']);

        $restored = $this->server->json(
            'DELETE',
            '/api/v1/host/archived/' . $own,
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $restored->status);
        self::assertTrue($this->decoded($restored)['archived']['restored']);
        self::assertCount(0, $this->decoded(
            $this->server->send('GET', '/api/v1/host/archived', ['cookies' => $this->aliceCookies]),
        )['archived']);
    }

    public function testForYouRanksFollowedAndEngagedPostsAndExplainsWhy(): void
    {
        $own = $this->post('alice', 'Mein eigener Beitrag');

        $plain = $this->post('bob', 'Ein ruhiger Beitrag');
        $engaged = $this->post('bob', 'Ein lebhafter #aloha Beitrag');
        $other = $this->post('carol', 'Von einem unbekannten Konto');

        $this->server->runtime->interactions->like('bob', $engaged);
        $this->server->runtime->interactions->like('carol', $engaged);

        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-1', 'alice', 'carol', 'https://remote.test/inbox', '2026-11-16T00:00:00Z', 'accepted')",
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_followed_hashtag (user_id, hashtag, created_at)'
            . " VALUES ('alice', 'aloha', '2026-11-16T00:00:00Z')",
        );

        $anonymous = $this->server->send('GET', '/api/v1/host/for-you');
        self::assertSame(401, $anonymous->status);

        $response = $this->server->send('GET', '/api/v1/host/for-you', [
            'query' => ['limit' => '2'],
            'cookies' => $this->aliceCookies,
        ]);
        self::assertSame(200, $response->status);
        $body = $this->decoded($response);

        self::assertCount(2, $body['posts']);
        self::assertSame($engaged, $body['posts'][0]);
        self::assertSame($other, $body['posts'][1]);
        self::assertSame('Because you follow #aloha', $body['reasons'][$engaged]);
        self::assertSame('Because you follow this account', $body['reasons'][$other]);
        // The reader's own posts stay in the feed; a single-author instance
        // must never render an empty For You feed.
        self::assertSame(4, $body['total']);

        $firstPage = $this->server->send('GET', '/api/v1/host/for-you', [
            'query' => ['limit' => '2', 'offset' => '0'],
            'cookies' => $this->aliceCookies,
        ]);
        self::assertSame($engaged, $this->decoded($firstPage)['posts'][0]);

        $secondPage = $this->server->send('GET', '/api/v1/host/for-you', [
            'query' => ['limit' => '2', 'offset' => '2'],
            'cookies' => $this->aliceCookies,
        ]);
        $secondBody = $this->decoded($secondPage);
        self::assertContains($own, $secondBody['posts']);
        self::assertNull($secondBody['next']);
    }

    public function testRankedFeedsExcludeLocalAndRemoteReplies(): void
    {
        $localParent = $this->post('bob', 'Lokaler Hauptbeitrag');
        $localReply = $this->server->runtime->app->posts->create(
            new CreatePost('bob', 'Lokale Antwort', Visibility::Public, inReplyToId: $localParent),
        )->id;

        $remoteActor = 'https://remote.test/users/carol';
        $remoteParent = 'https://remote.test/activities/root';
        $remoteReply = 'https://remote.test/activities/reply';
        foreach ([[$remoteParent, null], [$remoteReply, $remoteParent]] as [$activityId, $replyTo]) {
            $this->server->runtime->database->execute(
                'INSERT INTO social_remote_post'
                . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at, in_reply_to_id)'
                . ' VALUES (:activity_id, :object_id, :object_author_id, :actor_id, :kind, :content, :published_at, :in_reply_to_id)',
                [
                    'activity_id' => $activityId,
                    'object_id' => str_replace('/activities/', '/objects/', $activityId),
                    'object_author_id' => $remoteActor,
                    'actor_id' => $remoteActor,
                    'kind' => 'post',
                    'content' => $replyTo === null ? 'Remote root' : 'Remote reply',
                    'published_at' => '2026-11-22T10:00:00Z',
                    'in_reply_to_id' => $replyTo,
                ],
            );
        }
        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-ranked-remote', 'alice', :actor_id, 'https://remote.test/inbox', '2026-11-22T10:00:00Z', 'accepted')",
            ['actor_id' => $remoteActor],
        );

        $forYou = $this->decoded($this->server->send(
            'GET',
            '/api/v1/host/for-you',
            ['cookies' => $this->aliceCookies],
        ));
        self::assertContains($localParent, $forYou['posts']);
        self::assertContains($remoteParent, $forYou['posts']);
        self::assertNotContains($localReply, $forYou['posts']);
        self::assertNotContains($remoteReply, $forYou['posts']);

        $trending = $this->decoded($this->server->send('GET', '/api/v1/host/trending'));
        self::assertContains($localParent, $trending['posts']);
        self::assertContains($remoteParent, $trending['posts']);
        self::assertNotContains($localReply, $trending['posts']);
        self::assertNotContains($remoteReply, $trending['posts']);
    }

    public function testForYouIncludesFollowedFediversePostsAndHydratesThem(): void
    {
        $remoteId = 'https://remote.test/activities/111';
        $objectId = 'https://remote.test/objects/111';
        $actorId = 'https://remote.test/users/bob';

        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at)'
            . ' VALUES (:activity_id, :object_id, :object_author_id, :actor_id, :kind, :content, :published_at)',
            [
                'activity_id' => $remoteId,
                'object_id' => $objectId,
                'object_author_id' => $actorId,
                'actor_id' => $actorId,
                'kind' => 'post',
                'content' => 'Hallo aus dem Fediverse #aloha',
                'published_at' => '2026-11-18T10:00:00Z',
            ],
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-remote', 'alice', :actor_id, 'https://remote.test/inbox', '2026-11-18T10:00:00Z', 'accepted')",
            ['actor_id' => $actorId],
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_followed_hashtag (user_id, hashtag, created_at)'
            . " VALUES ('alice', 'aloha', '2026-11-18T10:00:00Z')",
        );

        $response = $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $response->status);
        $body = $this->decoded($response);
        self::assertContains($remoteId, $body['posts']);
        self::assertSame('Because you follow #aloha', $body['reasons'][$remoteId]);

        $cards = $this->decoded($this->server->send('GET', '/api/v1/host/posts', [
            'query' => ['ids' => $remoteId],
        ]));
        self::assertSame('Hallo aus dem Fediverse #aloha', $cards['posts'][$remoteId]['content']);
        self::assertTrue($cards['posts'][$remoteId]['is_remote']);
    }

    public function testForYouHidesMutedAndBlockedAuthors(): void
    {
        $this->post('bob', 'Sichtbar');
        $muted = $this->post('carol', 'Stummgeschaltet');

        $this->server->runtime->database->execute(
            "INSERT INTO social_mute (user_id, actor_id, notifications, created_at) VALUES ('alice', 'carol', 0, '2026-11-16T00:00:00Z')",
        );

        $response = $this->server->send('GET', '/api/v1/host/for-you', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $response->status);
        self::assertNotContains($muted, $this->decoded($response)['posts']);
    }
}
