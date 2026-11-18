<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Server\Federation\HtmlText;
use AlohaSocial\Server\Federation\RemoteDirectory;
use AlohaSocial\Server\Federation\RemoteDirectoryException;
use AlohaSocial\Server\Tests\Support\FakeBoundedHttpClient;
use PHPUnit\Framework\TestCase;

final class RemoteDirectoryTest extends TestCase
{
    private FakeBoundedHttpClient $http;

    private RemoteDirectory $directory;

    protected function setUp(): void
    {
        $this->http = new FakeBoundedHttpClient();
        $this->directory = new RemoteDirectory($this->http, new FederationTargetGuard());
    }

    public function testAMentionResolvesToTheActorInboxWithoutReadingTheOutbox(): void
    {
        $webfinger = 'https://mastodon.test/.well-known/webfinger?resource=acct%3Aalice%40mastodon.test';
        $actorUrl = 'https://mastodon.test/users/alice';

        $this->http->json($webfinger, [
            'links' => [
                ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUrl],
            ],
        ]);
        $this->http->json($actorUrl, [
            'id' => $actorUrl,
            'type' => 'Person',
            'preferredUsername' => 'alice',
            'inbox' => 'https://mastodon.test/users/alice/inbox',
            'outbox' => 'https://mastodon.test/users/alice/outbox',
        ]);

        $target = $this->directory->mentionTarget('alice@mastodon.test');

        self::assertNotNull($target);
        self::assertSame($actorUrl, $target->actorId);
        self::assertSame('https://mastodon.test/users/alice/inbox', $target->inboxUrl);
    }

    public function testActivityPubProfileIsReadFromWebFingerActorAndOutbox(): void
    {
        $webfinger = 'https://mastodon.test/.well-known/webfinger?resource=acct%3Aalice%40mastodon.test';
        $actorUrl = 'https://mastodon.test/users/alice';
        $outbox = 'https://mastodon.test/users/alice/outbox';

        $this->http->json($webfinger, [
            'subject' => 'acct:alice@mastodon.test',
            'links' => [
                ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUrl],
            ],
        ]);
        $this->http->json($actorUrl, [
            'id' => $actorUrl,
            'type' => 'Person',
            'preferredUsername' => 'alice',
            'name' => 'Alice Example',
            'summary' => '<p>Hallo <strong>Welt</strong><br>Zweite Zeile</p>',
            'icon' => ['type' => 'Image', 'url' => 'https://cdn.mastodon.test/avatar.png'],
            'image' => ['type' => 'Image', 'url' => 'https://cdn.mastodon.test/banner.png'],
            'inbox' => 'https://mastodon.test/users/alice/inbox',
            'outbox' => $outbox,
            'followers' => 'https://mastodon.test/users/alice/followers',
            'following' => 'https://mastodon.test/users/alice/following',
            'published' => '2020-01-01T00:00:00Z',
        ]);
        $this->http->json('https://mastodon.test/users/alice/followers', ['totalItems' => 12]);
        $this->http->json('https://mastodon.test/users/alice/following', ['totalItems' => 4]);
        $this->http->json($outbox, [
            'type' => 'OrderedCollectionPage',
            'totalItems' => 465,
            'orderedItems' => [
                [
                    'type' => 'Create',
                    'object' => [
                        'id' => 'https://mastodon.test/users/alice/statuses/1',
                        'type' => 'Note',
                        'content' => '<p>Hallo <a href="https://example.test">Welt</a> &amp; mehr</p>',
                        'published' => '2026-01-01T00:00:00Z',
                        'attributedTo' => $actorUrl,
                        'inReplyTo' => 'https://mastodon.test/users/bob/statuses/9',
                        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                        'attachment' => [
                            [
                                'type' => 'Document',
                                'mediaType' => 'image/png',
                                'url' => 'https://cdn.mastodon.test/media.png',
                                'name' => 'Ein Bild',
                                'width' => 640,
                                'height' => 480,
                            ],
                        ],
                    ],
                ],
            ],
            'next' => 'https://mastodon.test/users/alice/outbox?max_id=42&page=true',
        ]);

        $result = $this->directory->lookup('@alice@mastodon.test');

        self::assertSame('activitypub', $result['kind']);
        self::assertSame('@alice@mastodon.test', $result['profile']['handle']);
        self::assertSame('Alice Example', $result['profile']['display_name']);
        self::assertSame("Hallo Welt\nZweite Zeile", $result['profile']['bio']);
        self::assertSame(12, $result['profile']['followers_count']);
        self::assertSame(4, $result['profile']['following_count']);
        self::assertSame(465, $result['profile']['posts_count']);
        self::assertTrue($result['profile']['can_follow']);
        self::assertSame('https://mastodon.test/users/alice/inbox', $result['profile']['inbox']);
        self::assertSame('https://mastodon.test/users/alice/outbox?max_id=42&page=true', $result['cursor']);

        self::assertCount(1, $result['posts']);
        $post = $result['posts'][0];
        self::assertSame('Hallo Welt & mehr', $post['content']);
        self::assertSame('public', $post['visibility']);
        self::assertSame('https://mastodon.test/users/alice/statuses/1', $post['id']);
        self::assertSame('https://mastodon.test/users/bob/statuses/9', $post['in_reply_to_id']);
        self::assertCount(1, $post['attachments']);
        self::assertSame('https://cdn.mastodon.test/media.png', $post['attachments'][0]['url']);
        self::assertSame('image/png', $post['attachments'][0]['mime_type']);
        self::assertSame('Ein Bild', $post['attachments'][0]['alt_text']);
    }

    public function testBlueskyProfileIsReadFromThePublicAppView(): void
    {
        $resolve = 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=bsky.test';
        $profile = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=did%3Aplc%3Aabc';
        $feed = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?actor=did%3Aplc%3Aabc'
            . '&limit=30&filter=posts_no_replies';

        $this->http->json($resolve, ['did' => 'did:plc:abc']);
        $this->http->json($profile, [
            'did' => 'did:plc:abc',
            'handle' => 'bsky.test',
            'displayName' => 'Bluesky Test',
            'description' => '<p>Kurzbeschreibung</p>',
            'avatar' => 'https://cdn.bsky.app/avatar.png',
            'banner' => 'https://cdn.bsky.app/banner.png',
            'followersCount' => 120,
            'followsCount' => 7,
            'postsCount' => 33,
            'createdAt' => '2023-01-01T00:00:00Z',
        ]);
        $this->http->json($feed, [
            'cursor' => 'next-page',
            'feed' => [
                [
                    'post' => [
                        'uri' => 'at://did:plc:abc/app.bsky.feed.post/3kabc',
                        'author' => [
                            'handle' => 'bsky.test',
                            'displayName' => 'Bluesky Test',
                            'avatar' => 'https://cdn.bsky.app/avatar.png',
                        ],
                        'record' => [
                            'text' => 'Hallo Bluesky',
                            'createdAt' => '2026-02-02T00:00:00Z',
                        ],
                        'embed' => [
                            '$type' => 'app.bsky.embed.images#view',
                            'images' => [
                                [
                                    'thumb' => 'https://cdn.bsky.app/thumb.jpg',
                                    'fullsize' => 'https://cdn.bsky.app/full.jpg',
                                    'alt' => 'Alt-Text',
                                    'aspectRatio' => ['width' => 1200, 'height' => 800],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->directory->lookup('bsky.app/profile/bsky.test');

        self::assertSame('bluesky', $result['kind']);
        self::assertSame('@bsky.test', $result['profile']['handle']);
        self::assertSame('Bluesky Test', $result['profile']['display_name']);
        self::assertSame('Kurzbeschreibung', $result['profile']['bio']);
        self::assertSame('https://bsky.app/profile/bsky.test', $result['profile']['url']);
        self::assertFalse($result['profile']['can_follow']);
        self::assertSame('next-page', $result['cursor']);

        self::assertCount(1, $result['posts']);
        $post = $result['posts'][0];
        self::assertSame('at://did:plc:abc/app.bsky.feed.post/3kabc', $post['id']);
        self::assertSame('@bsky.test', $post['author_handle']);
        self::assertSame('Bluesky Test', $post['author_display_name']);
        self::assertSame('Hallo Bluesky', $post['content']);
        self::assertCount(1, $post['attachments']);
        self::assertSame('https://cdn.bsky.app/full.jpg', $post['attachments'][0]['url']);
        self::assertSame('Alt-Text', $post['attachments'][0]['alt_text']);
    }

    public function testAuthorizedFetchFallsBackToThePublicMastodonApi(): void
    {
        $webfinger = 'https://social.test/.well-known/webfinger?resource=acct%3Aalice%40social.test';
        $actorUrl = 'https://social.test/users/alice';
        $lookup = 'https://social.test/api/v1/accounts/lookup?acct=alice';
        $statuses = 'https://social.test/api/v1/accounts/42/statuses?limit=30&exclude_replies=true';

        $this->http->json($webfinger, [
            'links' => [
                ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUrl],
            ],
        ]);
        $this->http->html($actorUrl, 'authorized fetch', 401);
        $this->http->json($lookup, [
            'id' => '42',
            'username' => 'alice',
            'acct' => 'alice',
            'display_name' => 'Alice Public',
            'note' => '<p>Öffentliche Beschreibung</p>',
            'url' => 'https://social.test/@alice',
            'uri' => $actorUrl,
            'avatar' => 'https://cdn.social.test/avatar.png',
            'header' => 'https://cdn.social.test/banner.png',
            'followers_count' => 9,
            'following_count' => 3,
            'statuses_count' => 21,
            'created_at' => '2021-01-01T00:00:00Z',
        ]);
        $this->http->json($statuses, [
            [
                'id' => '100',
                'uri' => 'https://social.test/users/alice/statuses/100',
                'url' => 'https://social.test/@alice/100',
                'created_at' => '2026-03-03T00:00:00Z',
                'content' => '<p>Aus der öffentlichen API</p>',
                'spoiler_text' => '',
                'visibility' => 'public',
                'account' => [
                    'uri' => $actorUrl,
                    'url' => 'https://social.test/@alice',
                ],
                'media_attachments' => [],
            ],
        ]);

        $result = $this->directory->lookup('@alice@social.test');

        self::assertSame('activitypub', $result['kind']);
        self::assertSame('mastodon_api', $result['profile']['via']);
        self::assertSame('@alice@social.test', $result['profile']['handle']);
        self::assertSame('Alice Public', $result['profile']['display_name']);
        self::assertSame(21, $result['profile']['posts_count']);
        self::assertFalse($result['profile']['can_follow']);
        self::assertCount(1, $result['posts']);
        self::assertSame('Aus der öffentlichen API', $result['posts'][0]['content']);
        self::assertSame('100', $result['cursor']);
    }

    public function testAnInvalidHandleIsRejectedBeforeAnyRequest(): void
    {
        try {
            $this->directory->lookup('nur-ein-wort');
            self::fail('An invalid handle must not be resolved.');
        } catch (RemoteDirectoryException $exception) {
            self::assertSame('remote_handle_invalid', $exception->errorCode);
            self::assertSame(422, $exception->status);
        }

        self::assertSame([], $this->http->requests);
    }

    public function testMastodonPostDetailIncludesCountsParentAndReplies(): void
    {
        $statusUrl = 'https://mastodon.test/api/v1/statuses/123';
        $contextUrl = 'https://mastodon.test/api/v1/statuses/123/context';
        $account = [
            'id' => '7',
            'uri' => 'https://mastodon.test/users/alice',
            'url' => 'https://mastodon.test/@alice',
            'acct' => 'alice',
            'display_name' => 'Alice',
            'avatar' => 'https://cdn.test/alice.png',
        ];

        $this->http->json($statusUrl, [
            'id' => '123',
            'uri' => 'https://mastodon.test/users/alice/statuses/123',
            'url' => 'https://mastodon.test/@alice/123',
            'created_at' => '2026-04-04T12:00:00Z',
            'content' => '<p>Detail <strong>Beitrag</strong></p>',
            'spoiler_text' => '',
            'visibility' => 'public',
            'in_reply_to_id' => '100',
            'replies_count' => 1,
            'reblogs_count' => 3,
            'favourites_count' => 2,
            'account' => $account,
            'media_attachments' => [],
        ]);
        $this->http->json($contextUrl, [
            'ancestors' => [[
                'id' => '100',
                'uri' => 'https://mastodon.test/users/bob/statuses/100',
                'url' => 'https://mastodon.test/@bob/100',
                'created_at' => '2026-04-03T12:00:00Z',
                'content' => '<p>Elternbeitrag</p>',
                'visibility' => 'public',
                'account' => [
                    'uri' => 'https://mastodon.test/users/bob',
                    'url' => 'https://mastodon.test/@bob',
                    'acct' => 'bob',
                    'display_name' => 'Bob',
                ],
                'media_attachments' => [],
            ]],
            'descendants' => [[
                'id' => '124',
                'uri' => 'https://mastodon.test/users/carol/statuses/124',
                'url' => 'https://mastodon.test/@carol/124',
                'created_at' => '2026-04-04T13:00:00Z',
                'content' => '<p>Antwort von Carol</p>',
                'visibility' => 'public',
                'in_reply_to_id' => '123',
                'account' => [
                    'uri' => 'https://mastodon.test/users/carol',
                    'url' => 'https://mastodon.test/@carol',
                    'acct' => 'carol',
                    'display_name' => 'Carol',
                ],
                'media_attachments' => [],
            ]],
        ]);

        $result = $this->directory->remotePost('https://mastodon.test/@alice/123');

        self::assertSame('activitypub', $result['network']);
        self::assertSame('Detail Beitrag', $result['post']['content']);
        self::assertSame(2, $result['post']['likes_count']);
        self::assertSame(1, $result['post']['replies_count']);
        self::assertSame(3, $result['post']['boosts_count']);
        self::assertSame('https://mastodon.test/@alice/123', $result['post']['external_url']);
        self::assertSame('https://mastodon.test/users/bob/statuses/100', $result['parent']['id']);
        self::assertSame('https://mastodon.test/users/bob/statuses/100', $result['post']['in_reply_to_id']);
        self::assertCount(1, $result['replies']);
        self::assertSame('Antwort von Carol', $result['replies'][0]['content']);
        self::assertSame('@alice@mastodon.test', $result['profile']['handle']);
    }

    public function testActivityPubPostDetailReadsCountsAndRepliesFromTheObject(): void
    {
        $postUrl = 'https://pleroma.test/notes/abc123';
        $actorUrl = 'https://pleroma.test/users/alice';
        $replyActorUrl = 'https://pleroma.test/users/bob';
        $parentUrl = 'https://pleroma.test/notes/parent1';
        $replyUrl = 'https://pleroma.test/notes/reply1';

        $this->http->json($postUrl, [
            'id' => $postUrl,
            'type' => 'Note',
            'content' => '<p>Öffentlicher Beitrag</p>',
            'published' => '2026-05-05T10:00:00Z',
            'attributedTo' => $actorUrl,
            'inReplyTo' => $parentUrl,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'likes' => $postUrl . '/likes',
            'shares' => $postUrl . '/shares',
            'replies' => $postUrl . '/replies',
        ]);
        $this->http->json($actorUrl, [
            'id' => $actorUrl,
            'type' => 'Person',
            'preferredUsername' => 'alice',
            'name' => 'Alice',
        ]);
        $this->http->json($postUrl . '/likes', ['totalItems' => 5]);
        $this->http->json($postUrl . '/shares', ['totalItems' => 2]);
        $this->http->json($postUrl . '/replies', [
            'totalItems' => 1,
            'first' => [
                'type' => 'OrderedCollectionPage',
                'orderedItems' => [[
                    'id' => $replyUrl,
                    'type' => 'Note',
                    'content' => '<p>Direkte Antwort</p>',
                    'published' => '2026-05-05T11:00:00Z',
                    'attributedTo' => $replyActorUrl,
                    'inReplyTo' => $postUrl,
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                ]],
            ],
        ]);
        $this->http->json($replyActorUrl, [
            'id' => $replyActorUrl,
            'type' => 'Person',
            'preferredUsername' => 'bob',
            'name' => 'Bob',
        ]);
        $this->http->json($parentUrl, [
            'id' => $parentUrl,
            'type' => 'Note',
            'content' => '<p>Elternbeitrag</p>',
            'published' => '2026-05-05T09:00:00Z',
            'attributedTo' => $actorUrl,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ]);

        $result = $this->directory->remotePost($postUrl);

        self::assertSame('activitypub', $result['network']);
        self::assertSame(5, $result['post']['likes_count']);
        self::assertSame(1, $result['post']['replies_count']);
        self::assertSame(2, $result['post']['boosts_count']);
        self::assertSame('@alice@pleroma.test', $result['post']['author_handle']);
        self::assertSame($parentUrl, $result['parent']['id']);
        self::assertCount(1, $result['replies']);
        self::assertSame('Direkte Antwort', $result['replies'][0]['content']);
        self::assertSame('@bob@pleroma.test', $result['replies'][0]['author_handle']);
    }

    public function testBlueskyThreadIncludesEngagementCountsAndReplies(): void
    {
        $uri = 'at://did:plc:abc/app.bsky.feed.post/3kabc';
        $url = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPostThread?uri='
            . rawurlencode($uri) . '&depth=3&parentHeight=1';

        $this->http->json($url, [
            'thread' => [
                'post' => [
                    'uri' => $uri,
                    'author' => ['handle' => 'alice.bsky.test', 'displayName' => 'Alice'],
                    'record' => [
                        'text' => 'Bluesky Detail',
                        'createdAt' => '2026-06-06T10:00:00Z',
                        'reply' => ['parent' => ['uri' => 'at://did:plc:abc/app.bsky.feed.post/parent']],
                    ],
                    'likeCount' => 17,
                    'replyCount' => 4,
                    'repostCount' => 6,
                ],
                'parent' => [
                    'post' => [
                        'uri' => 'at://did:plc:abc/app.bsky.feed.post/parent',
                        'author' => ['handle' => 'bob.bsky.test'],
                        'record' => ['text' => 'Elternpost', 'createdAt' => '2026-06-06T09:00:00Z'],
                    ],
                ],
                'replies' => [[
                    'post' => [
                        'uri' => 'at://did:plc:abc/app.bsky.feed.post/reply',
                        'author' => ['handle' => 'carol.bsky.test'],
                        'record' => ['text' => 'Antwort', 'createdAt' => '2026-06-06T11:00:00Z'],
                    ],
                    'replies' => [[
                        'post' => [
                            'uri' => 'at://did:plc:abc/app.bsky.feed.post/nested',
                            'author' => ['handle' => 'dave.bsky.test'],
                            'record' => ['text' => 'Verschachtelt', 'createdAt' => '2026-06-06T12:00:00Z'],
                        ],
                    ]],
                ]],
            ],
        ]);

        $result = $this->directory->remotePost($uri);

        self::assertSame('bluesky', $result['network']);
        self::assertSame(17, $result['post']['likes_count']);
        self::assertSame(4, $result['post']['replies_count']);
        self::assertSame(6, $result['post']['boosts_count']);
        self::assertSame('https://bsky.app/profile/alice.bsky.test/post/3kabc', $result['post']['external_url']);
        self::assertSame('at://did:plc:abc/app.bsky.feed.post/parent', $result['post']['in_reply_to_id']);
        self::assertSame('Elternpost', $result['parent']['content']);
        self::assertCount(2, $result['replies']);
        self::assertSame('Antwort', $result['replies'][0]['content']);
        self::assertSame('Verschachtelt', $result['replies'][1]['content']);
    }

    public function testActivityPubConnectionsUseTheMastodonApi(): void
    {
        $lookup = 'https://social.test/api/v1/accounts/lookup?acct=alice';
        $followers = 'https://social.test/api/v1/accounts/42/followers?limit=40';

        $this->http->json($lookup, ['id' => '42']);
        $this->http->json($followers, [[
            'id' => '900',
            'uri' => 'https://social.test/users/bob',
            'url' => 'https://social.test/@bob',
            'acct' => 'bob',
            'display_name' => 'Bob',
            'note' => '<p>Bob-Bio</p>',
            'avatar' => 'https://cdn.social.test/bob.png',
            'followers_count' => 8,
            'following_count' => 3,
            'statuses_count' => 12,
            'created_at' => '2022-02-02T00:00:00Z',
        ]]);

        $result = $this->directory->remoteConnections('@alice@social.test', 'followers');

        self::assertSame('activitypub', $result['network']);
        self::assertCount(1, $result['profiles']);
        self::assertSame('@bob@social.test', $result['profiles'][0]['handle']);
        self::assertSame('Bob-Bio', $result['profiles'][0]['bio']);
        self::assertSame(8, $result['profiles'][0]['followers_count']);
        self::assertSame('900', $result['cursor']);
    }

    public function testActivityPubConnectionsFallBackToTheActorCollection(): void
    {
        $actorUrl = 'https://pleroma.test/users/alice';
        $collection = 'https://pleroma.test/users/alice/following';

        $this->http->json($actorUrl, [
            'id' => $actorUrl,
            'type' => 'Person',
            'preferredUsername' => 'alice',
            'following' => $collection,
        ]);
        $this->http->json($collection, [
            'type' => 'OrderedCollectionPage',
            'orderedItems' => ['https://pleroma.test/users/bob'],
            'next' => $collection . '?page=2',
        ]);
        $this->http->json('https://pleroma.test/users/bob', [
            'id' => 'https://pleroma.test/users/bob',
            'type' => 'Person',
            'preferredUsername' => 'bob',
            'name' => 'Bob Remote',
        ]);

        $result = $this->directory->remoteConnections($actorUrl, 'following');

        self::assertSame('activitypub', $result['network']);
        self::assertCount(1, $result['profiles']);
        self::assertSame('@bob@pleroma.test', $result['profiles'][0]['handle']);
        self::assertSame('Bob Remote', $result['profiles'][0]['display_name']);
        self::assertSame($collection . '?page=2', $result['cursor']);
    }

    public function testBlueskyConnectionsReadThePublicGraph(): void
    {
        $resolve = 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=bsky.test';
        $followers = 'https://public.api.bsky.app/xrpc/app.bsky.graph.getFollowers?actor=did%3Aplc%3Aabc&limit=50';

        $this->http->json($resolve, ['did' => 'did:plc:abc']);
        $this->http->json($followers, [
            'cursor' => 'next-followers',
            'followers' => [[
                'did' => 'did:plc:bob',
                'handle' => 'bob.bsky.test',
                'displayName' => 'Bob',
                'description' => 'Kurzbio',
                'avatar' => 'https://cdn.bsky.app/bob.png',
                'createdAt' => '2023-03-03T00:00:00Z',
            ]],
        ]);

        $result = $this->directory->remoteConnections('bsky.test', 'followers');

        self::assertSame('bluesky', $result['network']);
        self::assertCount(1, $result['profiles']);
        self::assertSame('@bob.bsky.test', $result['profiles'][0]['handle']);
        self::assertSame('Bob', $result['profiles'][0]['display_name']);
        self::assertSame('Kurzbio', $result['profiles'][0]['bio']);
        self::assertSame('next-followers', $result['cursor']);
    }

    public function testBlueskySearchReturnsActorsAndPosts(): void
    {
        $actors = 'https://public.api.bsky.app/xrpc/app.bsky.actor.searchActors?q=aloha&limit=20';
        $posts = 'https://public.api.bsky.app/xrpc/app.bsky.feed.searchPosts?q=aloha&limit=25&sort=latest';

        $this->http->json($actors, [
            'actors' => [[
                'did' => 'did:plc:alice',
                'handle' => 'alice.bsky.test',
                'displayName' => 'Alice',
                'description' => '<p>Fediverse-Freundin</p>',
                'avatar' => 'https://cdn.bsky.app/alice.png',
                'followersCount' => 44,
            ]],
        ]);
        $this->http->json($posts, [
            'cursor' => 'posts-next',
            'posts' => [[
                'uri' => 'at://did:plc:alice/app.bsky.feed.post/3ksearch',
                'author' => [
                    'handle' => 'alice.bsky.test',
                    'displayName' => 'Alice',
                ],
                'record' => [
                    'text' => 'Hallo aus der Suche',
                    'createdAt' => '2026-06-06T00:00:00Z',
                ],
            ]],
        ]);

        $result = $this->directory->search('aloha', 'all', 'bluesky');

        self::assertSame('aloha', $result['query']);
        self::assertCount(1, $result['accounts']);
        self::assertSame('@alice.bsky.test', $result['accounts'][0]['handle']);
        self::assertSame('Fediverse-Freundin', $result['accounts'][0]['bio']);
        self::assertCount(1, $result['posts']);
        self::assertSame('Hallo aus der Suche', $result['posts'][0]['content']);
        self::assertSame('posts-next', $result['cursor']);
        self::assertSame([], $result['notes']);
    }

    public function testMastodonKeywordSearchUsesTheDiscoveryInstance(): void
    {
        $directory = new RemoteDirectory(
            $this->http,
            new FederationTargetGuard(),
            'discovery.test',
        );

        $accountSearch = 'https://discovery.test/api/v2/search?q=aloha&type=accounts&limit=20&resolve=false';
        $statusSearch = 'https://discovery.test/api/v2/search?q=aloha&type=statuses&limit=20&resolve=false';

        $this->http->json($accountSearch, [
            'accounts' => [[
                'id' => '9',
                'uri' => 'https://discovery.test/users/carol',
                'url' => 'https://discovery.test/@carol',
                'acct' => 'carol',
                'display_name' => 'Carol',
                'note' => '<p>Discovery-Profil</p>',
                'followers_count' => 10,
            ]],
            'statuses' => [],
        ]);
        $this->http->json($statusSearch, [
            'accounts' => [],
            'statuses' => [[
                'id' => '77',
                'uri' => 'https://discovery.test/users/carol/statuses/77',
                'url' => 'https://discovery.test/@carol/77',
                'created_at' => '2026-07-07T00:00:00Z',
                'content' => '<p>Ein Treffer aus dem Fediverse</p>',
                'visibility' => 'public',
                'account' => [
                    'uri' => 'https://discovery.test/users/carol',
                    'url' => 'https://discovery.test/@carol',
                    'acct' => 'carol',
                ],
                'media_attachments' => [],
            ]],
        ]);

        $result = $directory->search('aloha', 'all', 'activitypub');

        self::assertCount(1, $result['accounts']);
        self::assertSame('@carol@discovery.test', $result['accounts'][0]['handle']);
        self::assertSame('Discovery-Profil', $result['accounts'][0]['bio']);
        self::assertCount(1, $result['posts']);
        self::assertSame('Ein Treffer aus dem Fediverse', $result['posts'][0]['content']);
    }

    public function testMastodonHashtagSearchReadsTheTagTimeline(): void
    {
        $directory = new RemoteDirectory(
            $this->http,
            new FederationTargetGuard(),
            'discovery.test',
        );

        $this->http->json(
            'https://discovery.test/api/v2/search?q=%23aloha&type=accounts&limit=20&resolve=false',
            ['accounts' => [], 'statuses' => []],
        );
        $this->http->json('https://discovery.test/api/v1/timelines/tag/aloha?limit=25', [
            [
                'id' => '88',
                'uri' => 'https://discovery.test/users/dave/statuses/88',
                'url' => 'https://discovery.test/@dave/88',
                'created_at' => '2026-08-08T00:00:00Z',
                'content' => '<p>#aloha ist ein Hashtag</p>',
                'visibility' => 'public',
                'account' => [
                    'uri' => 'https://discovery.test/users/dave',
                    'url' => 'https://discovery.test/@dave',
                    'acct' => 'dave',
                ],
                'media_attachments' => [],
            ],
        ]);

        $result = $directory->search('#aloha', 'posts', 'activitypub');

        self::assertCount(1, $result['posts']);
        self::assertSame('#aloha ist ein Hashtag', $result['posts'][0]['content']);
        self::assertSame('@dave@discovery.test', $result['posts'][0]['author_handle']);
    }

    public function testSearchWithoutDiscoveryInstanceExplainsTheGap(): void
    {
        $directory = new RemoteDirectory($this->http, new FederationTargetGuard(), '');

        // Bluesky is still reachable through its public AppView.
        $this->http->json(
            'https://public.api.bsky.app/xrpc/app.bsky.actor.searchActors?q=aloha&limit=20',
            ['actors' => []],
        );
        $this->http->json(
            'https://public.api.bsky.app/xrpc/app.bsky.feed.searchPosts?q=aloha&limit=25&sort=latest',
            ['posts' => []],
        );

        $result = $directory->search('aloha', 'all', 'activitypub');

        self::assertSame([], $result['accounts']);
        self::assertSame([], $result['posts']);
        self::assertCount(1, $result['notes']);
        self::assertStringContainsString('discovery instance', $result['notes'][0]);
    }

    public function testMastodonEngagementListsLikesAndBoosts(): void
    {
        $likes = 'https://mastodon.test/api/v1/statuses/123/favourited_by?limit=40';
        $this->http->json($likes, [[
            'id' => '5',
            'uri' => 'https://mastodon.test/users/eve',
            'url' => 'https://mastodon.test/@eve',
            'acct' => 'eve',
            'display_name' => 'Eve',
            'avatar' => 'https://cdn.test/eve.png',
        ]]);

        $likesResult = $this->directory->remoteEngagement('https://mastodon.test/@alice/123', 'likes');
        self::assertSame('activitypub', $likesResult['network']);
        self::assertSame('likes', $likesResult['type']);
        self::assertCount(1, $likesResult['profiles']);
        self::assertSame('@eve@mastodon.test', $likesResult['profiles'][0]['handle']);
        self::assertNull($likesResult['cursor']);

        $reposts = 'https://mastodon.test/api/v1/statuses/123/reblogged_by?limit=40&max_id=9';
        $this->http->json($reposts, [[
            'id' => '9',
            'uri' => 'https://mastodon.test/users/frank',
            'url' => 'https://mastodon.test/@frank',
            'acct' => 'frank',
            'display_name' => 'Frank',
        ]]);

        $repostResult = $this->directory->remoteEngagement('https://mastodon.test/@alice/123', 'reposts', '9');
        self::assertSame('reposts', $repostResult['type']);
        self::assertCount(1, $repostResult['profiles']);
        self::assertSame('@frank@mastodon.test', $repostResult['profiles'][0]['handle']);
    }

    public function testBlueskyEngagementReadsLikesAndReposts(): void
    {
        $uri = 'at://did:plc:abc/app.bsky.feed.post/3kabc';
        $encoded = rawurlencode($uri);
        $likes = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getLikes?uri=' . $encoded . '&limit=50';
        $reposts = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getRepostedBy?uri=' . $encoded . '&limit=50';

        $this->http->json($likes, [
            'likes' => [[
                'actor' => [
                    'did' => 'did:plc:gina',
                    'handle' => 'gina.bsky.test',
                    'displayName' => 'Gina',
                ],
            ]],
        ]);
        $this->http->json($reposts, [
            'cursor' => 'reposts-next',
            'repostedBy' => [[
                'did' => 'did:plc:hans',
                'handle' => 'hans.bsky.test',
                'displayName' => 'Hans',
            ]],
        ]);

        $likeResult = $this->directory->remoteEngagement($uri, 'likes');
        self::assertSame('bluesky', $likeResult['network']);
        self::assertCount(1, $likeResult['profiles']);
        self::assertSame('@gina.bsky.test', $likeResult['profiles'][0]['handle']);

        $repostResult = $this->directory->remoteEngagement($uri, 'reposts');
        self::assertCount(1, $repostResult['profiles']);
        self::assertSame('@hans.bsky.test', $repostResult['profiles'][0]['handle']);
        self::assertSame('reposts-next', $repostResult['cursor']);
    }

    public function testUnknownEngagementTypeIsRejected(): void
    {
        try {
            $this->directory->remoteEngagement('https://mastodon.test/@alice/123', 'stars');
            self::fail('An unknown engagement type must not be accepted.');
        } catch (RemoteDirectoryException $exception) {
            self::assertSame('remote_engagement_type_invalid', $exception->errorCode);
            self::assertSame(422, $exception->status);
        }

        self::assertSame([], $this->http->requests);
    }

    public function testHtmlTextKeepsTextAndDropsMarkup(): void
    {
        self::assertSame(
            "Zeile eins\nZeile zwei",
            HtmlText::toPlainText('<p>Zeile eins</p><p>Zeile&nbsp;zwei</p>'),
        );
        self::assertSame('', HtmlText::toPlainText(null));
    }
}
