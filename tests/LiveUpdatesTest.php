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
 * The live cursor must report counter refreshes the viewer can see, and only
 * those: another instance's posts that this account does not follow are not
 * part of its page.
 */
final class LiveUpdatesTest extends TestCase
{
    public function testEngagementRefreshReachesOnlyFollowedRemotePosts(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $session = $server->signIn('alice');
        $database = $server->runtime->database;

        $followed = 'https://remote.example/@bob';
        $unfollowed = 'https://remote.example/@carol';
        $database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-live', 'alice', :actor, 'https://remote.example/inbox', '2026-11-20T10:00:00Z', 'accepted')",
            ['actor' => $followed],
        );
        foreach ([
            ['https://remote.example/activities/1', 'https://remote.example/objects/1', $followed],
            ['https://remote.example/activities/2', 'https://remote.example/objects/2', $unfollowed],
            ] as [$activityId, $objectId, $actorId]) {
            $database->execute(
                'INSERT INTO social_remote_post'
                . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at)'
                . ' VALUES (:activity_id, :object_id, :author, :actor, :kind, :content, :published)',
                [
                    'activity_id' => $activityId,
                    'object_id' => $objectId,
                    'author' => $actorId,
                    'actor' => $actorId,
                    'kind' => 'post',
                    'content' => 'Federated post',
                    'published' => '2026-11-20T10:00:00Z',
                ],
            );
        }

        $server->runtime->remoteEngagement->rememberCounts(
            'https://remote.example/objects/1',
            ['likes' => 5, 'boosts' => 1, 'replies' => 2],
        );
        $server->runtime->remoteEngagement->rememberCounts(
            'https://remote.example/objects/2',
            ['likes' => 9, 'boosts' => 4, 'replies' => 3],
        );

        $response = $server->send('GET', '/api/v1/host/updates', [
            'query' => ['since' => '2026-11-01T00:00:00Z'],
            'cookies' => $session['cookies'],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['engagement'], 'Only followed actors may appear in the cursor.');

        $update = $body['engagement'][0];
        self::assertSame('https://remote.example/activities/1', $update['id']);
        self::assertSame('https://remote.example/objects/1', $update['object_id']);
        self::assertSame(5, $update['likes_count']);
        self::assertSame(1, $update['boosts_count']);
        self::assertSame(2, $update['replies_count']);
    }
}
