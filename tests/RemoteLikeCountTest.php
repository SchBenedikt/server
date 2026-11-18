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
 * A remote Like lives in the core reaction table, while a local favourite uses
 * the favourite table. The card counter must see both as one number.
 */
final class RemoteLikeCountTest extends TestCase
{
    public function testInboundRemoteLikesIncreaseThePostCounter(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $postId = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Public post', Visibility::Public),
        )->id;

        $server->runtime->database->execute(
            'INSERT INTO social_reaction'
            . ' (id, direction, subject_id, object_id, local_post_id, kind, emoji, icon_url, activity_id, created_at)'
            . ' VALUES (:id, :direction, :subject, :object, :post, :kind, :emoji, NULL, :activity, :created)',
            [
                'id' => 'reaction-1',
                'direction' => 'in',
                'subject' => 'https://remote.example/@bob',
                'object' => 'https://social.example/api/v1/posts/' . $postId,
                'post' => $postId,
                'kind' => 'like',
                'emoji' => '',
                'activity' => 'https://remote.example/likes/1',
                'created' => '2026-11-18T10:00:00Z',
            ],
        );

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $postId],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['interactions'][$postId]['favourites_count']);
    }

    public function testPrivatePostsDoNotLeakInteractionCounts(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $postId = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Private post', Visibility::Followers),
        )->id;

        $response = $server->send('GET', '/api/v1/host/interactions', [
            'query' => ['ids' => $postId],
        ]);

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey($postId, $body['interactions']);
    }
}
