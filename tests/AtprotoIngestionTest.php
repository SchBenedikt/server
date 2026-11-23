<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The host side of AT Protocol ingestion.
 *
 * The core stores raw records and tombstones; the reconciler is what makes
 * them visible as posts, reply counts and likes. These tests drive that
 * projection against the real schema, so a broken column or upsert fails here
 * instead of silently dropping a member's Bluesky interactions.
 */
final class AtprotoIngestionTest extends TestCase
{
    private TestServer $server;

    private Connection $db;

    private string $localDid;

    private string $localPostId;

    private string $localPostUri;

    protected function setUp(): void
    {
        $this->server = new TestServer(['atproto_jetstream' => 'wss://relay.example/subscribe']);
        $this->db = $this->server->runtime->database;

        $alice = $this->server->createAccount('alice');
        $post = $this->server->runtime->app->posts->create(
            new CreatePost($alice->id, 'A local post', Visibility::Public),
        );

        $this->localPostId = $post->id;
        $this->localDid = 'did:plc:localalice';
        $this->localPostUri = 'at://' . $this->localDid . '/app.bsky.feed.post/3klocalrecord';

        $now = Time::format($this->server->runtime->clock->now());
        $this->db->execute(
            'INSERT INTO social_following'
            . ' (id, local_user_id, actor_id, inbox_url, followed_at, state, follow_activity_id)'
            . " VALUES ('follow-1', :local, 'did:plc:followed', 'https://remote.example/inbox', :now, 'accepted', NULL)",
            ['local' => $alice->id, 'now' => $now],
        );
        $this->db->execute(
            'INSERT INTO social_atproto_publication (local_user_id, post_id, rkey, uri, published_at)'
            . ' VALUES (:local, :post, :rkey, :uri, :now)',
            [
                'local' => $alice->id,
                'post' => $post->id,
                'rkey' => '3klocalrecord',
                'uri' => $this->localPostUri,
                'now' => $now,
            ],
        );
    }

    public function testFollowedPostsRepliesAndLikesBecomeReadableSocialRows(): void
    {
        $this->record(
            'at://did:plc:followed/app.bsky.feed.post/3kpost',
            'did:plc:followed',
            'app.bsky.feed.post',
            '3kpost',
            ['text' => 'Hello from Bluesky', 'createdAt' => '2026-11-18T10:00:00.000Z'],
            1,
        );
        $this->record(
            'at://did:plc:fan/app.bsky.feed.like/3klike',
            'did:plc:fan',
            'app.bsky.feed.like',
            '3klike',
            [
                'subject' => ['uri' => $this->localPostUri, 'cid' => 'bafylocal'],
                'createdAt' => '2026-11-18T10:05:00.000Z',
            ],
            2,
        );
        $this->record(
            'at://did:plc:fan/app.bsky.feed.post/3kreply',
            'did:plc:fan',
            'app.bsky.feed.post',
            '3kreply',
            [
                'text' => 'A reply from Bluesky',
                'createdAt' => '2026-11-18T10:06:00.000Z',
                'reply' => [
                    'parent' => ['uri' => $this->localPostUri, 'cid' => 'bafylocal'],
                    'root' => ['uri' => $this->localPostUri, 'cid' => 'bafylocal'],
                ],
            ],
            3,
        );

        $reconciler = $this->server->runtime->atprotoReconciler;
        self::assertNotNull($reconciler);

        $result = $reconciler->run();
        self::assertGreaterThanOrEqual(3, $result['projected']);

        $post = $this->db->fetchOne(
            'SELECT content, deleted_at, actor_id FROM social_remote_post WHERE activity_id = :uri',
            ['uri' => 'at://did:plc:followed/app.bsky.feed.post/3kpost'],
        );
        self::assertNotNull($post);
        self::assertSame('Hello from Bluesky', $post['content']);
        self::assertNull($post['deleted_at']);
        self::assertSame('did:plc:followed', $post['actor_id']);

        $interactions = $this->server->runtime->interactions->forPosts([$this->localPostId], null)[$this->localPostId];
        self::assertSame(1, $interactions['favourites_count']);
        self::assertSame(1, $interactions['replies_count']);

        // The same rows the timeline hydrator reads.
        $response = $this->server->send('GET', '/api/v1/host/posts', [
            'query' => ['ids' => 'at://did:plc:followed/app.bsky.feed.post/3kpost'],
        ]);
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Hello from Bluesky', $body['posts']['at://did:plc:followed/app.bsky.feed.post/3kpost']['content'] ?? null);
        self::assertTrue($body['posts']['at://did:plc:followed/app.bsky.feed.post/3kpost']['is_remote'] ?? false);

        // A tombstone removes exactly what its record created.
        $this->db->execute(
            "UPDATE social_atproto_remote_record SET deleted = 1 WHERE collection IN ('app.bsky.feed.like', 'app.bsky.feed.post')",
        );
        $removed = $reconciler->run();
        self::assertGreaterThanOrEqual(2, $removed['removed']);

        $interactions = $this->server->runtime->interactions->forPosts([$this->localPostId], null)[$this->localPostId];
        self::assertSame(0, $interactions['favourites_count']);
        self::assertSame(0, $interactions['replies_count']);

        $deleted = $this->db->fetchValue(
            'SELECT deleted_at FROM social_remote_post WHERE activity_id = :uri',
            ['uri' => 'at://did:plc:followed/app.bsky.feed.post/3kpost'],
        );
        self::assertNotNull($deleted);
    }

    public function testUnrelatedRecordsAreNotStoredAsPosts(): void
    {
        $this->record(
            'at://did:plc:stranger/app.bsky.feed.post/3kother',
            'did:plc:stranger',
            'app.bsky.feed.post',
            '3kother',
            ['text' => 'Not followed', 'createdAt' => '2026-11-18T10:00:00.000Z'],
            1,
        );

        $this->server->runtime->atprotoReconciler?->run();

        self::assertSame(0, (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM social_remote_post WHERE activity_id = :uri',
            ['uri' => 'at://did:plc:stranger/app.bsky.feed.post/3kother'],
        ));
    }

    public function testActiveAtprotoFollowProjectsPostsIntoTheHomeFeed(): void
    {
        $alice = $this->server->runtime->accounts->findByHandle('alice');
        self::assertNotNull($alice);
        $now = Time::format($this->server->runtime->clock->now());
        $this->db->execute(
            'INSERT INTO social_atproto_follow'
            . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
            . ' VALUES (:local, :account, :subject, :rkey, :uri, :cid, :state, :followed_at)',
            [
                'local' => $alice->id,
                'account' => $this->localDid,
                'subject' => 'did:plc:followed',
                'rkey' => '3kfollowrecord',
                'uri' => 'at://' . $this->localDid . '/app.bsky.graph.follow/3kfollowrecord',
                'cid' => 'bafyfollow',
                'state' => 'active',
                'followed_at' => $now,
            ],
        );
        $uri = 'at://did:plc:followed/app.bsky.feed.post/3kpost';
        $this->record(
            $uri,
            'did:plc:followed',
            'app.bsky.feed.post',
            '3kpost',
            ['text' => 'Visible in home', 'createdAt' => '2026-11-18T10:00:00.000Z'],
            1,
        );

        $this->server->runtime->atprotoReconciler?->run();
        $ids = array_map(
            static fn ($entry): string => $entry->id,
            $this->server->runtime->app->timelines->home($alice->id, 20),
        );

        self::assertContains($uri, $ids);
        self::assertNotContains($uri, array_map(
            static fn ($entry): string => $entry->id,
            $this->server->runtime->app->timelines->home('another-user', 20),
        ));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function record(
        string $uri,
        string $did,
        string $collection,
        string $rkey,
        array $body,
        int $sequence,
    ): void {
        $now = Time::format($this->server->runtime->clock->now());
        $this->db->execute(
            'INSERT INTO social_atproto_remote_record'
            . ' (source, uri, did, collection, rkey, cid, rev, sequence, record_json, deleted,'
            . ' published_at, received_at, updated_at)'
            . " VALUES ('jetstream', :uri, :did, :collection, :rkey, 'bafyrecord', NULL, :sequence,"
            . ' :record_json, 0, NULL, :now, :now)',
            [
                'uri' => $uri,
                'did' => $did,
                'collection' => $collection,
                'rkey' => $rkey,
                'sequence' => $sequence,
                'record_json' => json_encode($body, JSON_THROW_ON_ERROR),
                'now' => $now,
            ],
        );
    }
}
