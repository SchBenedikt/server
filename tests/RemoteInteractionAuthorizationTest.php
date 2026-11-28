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
 * The host's remote like, boost and reply paths must use the core's single
 * block/audience/follow policy and fail closed when that policy is unavailable.
 */
final class RemoteInteractionAuthorizationTest extends TestCase
{
    private const ACTOR = 'https://remote.example/users/carol';

    private const OBJECT = 'https://remote.example/objects/post-1';

    private TestServer $server;

    /** @var array<string, string> */
    private array $cookies = [];

    private string $csrf = '';

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice');
        $session = $this->server->signIn('alice');
        $this->cookies = $session['cookies'];
        $this->csrf = $session['csrf'];
        $this->seedRemotePost('public');
    }

    public function testLocalBlockDeniesAllRemoteInteractionsWithoutWrites(): void
    {
        $this->server->runtime->database->execute(
            'INSERT INTO social_block (id, local_user_id, actor_id, blocked_at)'
            . " VALUES ('block-1', 'alice', :actor, '2026-11-27T10:00:00Z')",
            ['actor' => self::ACTOR],
        );

        self::assertSame(403, $this->like()->status);
        self::assertSame(403, $this->boost()->status);
        self::assertSame(403, $this->reply()->status);
        $this->assertNoWrites();
    }

    public function testInboundBlockDeniesAllRemoteInteractionsWithoutWrites(): void
    {
        $this->server->runtime->database->execute(
            'INSERT INTO social_inbound_block (local_user_id, actor_id, activity_id)'
            . " VALUES ('alice', :actor, 'https://remote.example/blocks/1')",
            ['actor' => self::ACTOR],
        );

        self::assertSame(403, $this->like()->status);
        self::assertSame(403, $this->boost()->status);
        self::assertSame(403, $this->reply()->status);
        $this->assertNoWrites();
    }

    public function testFollowersOnlyPostWithPendingFollowIsDenied(): void
    {
        $this->server->runtime->database->execute(
            'UPDATE social_remote_post SET audience = :audience WHERE object_id = :object',
            ['audience' => 'followers', 'object' => self::OBJECT],
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-pending', 'alice', :actor, 'https://remote.example/inbox', '2026-11-27T10:00:00Z', 'pending')",
            ['actor' => self::ACTOR],
        );

        self::assertSame(403, $this->like()->status);
        self::assertSame(403, $this->boost()->status);
        self::assertSame(404, $this->reply()->status);
        $this->assertNoWrites();
    }

    public function testFollowersOnlyPostWithoutAFollowIsDenied(): void
    {
        $this->server->runtime->database->execute(
            'UPDATE social_remote_post SET audience = :audience WHERE object_id = :object',
            ['audience' => 'followers', 'object' => self::OBJECT],
        );

        self::assertSame(403, $this->like()->status);
        self::assertSame(403, $this->boost()->status);
        self::assertSame(404, $this->reply()->status);
        $this->assertNoWrites();
    }

    public function testBlockStoreFailureFailsClosedWithoutWrites(): void
    {
        // Simulate an authorization-store outage. The old host code caught the
        // database error and treated the actor as not blocked.
        $this->server->runtime->database->execute('ALTER TABLE social_block RENAME TO social_block_unavailable');

        $like = $this->like();
        self::assertSame(503, $like->status, $like->body);
        self::assertSame('authorization_unavailable', $this->errorCode($like->body));

        $boost = $this->boost();
        self::assertSame(503, $boost->status, $boost->body);
        self::assertSame('authorization_unavailable', $this->errorCode($boost->body));

        $reply = $this->reply();
        self::assertSame(503, $reply->status, $reply->body);
        self::assertSame('authorization_unavailable', $this->errorCode($reply->body));

        $this->assertNoWrites();
    }

    private function like(): \AlohaSocial\Core\Http\Response
    {
        return $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode(self::OBJECT) . '/like',
            [],
            $this->cookies,
            $this->csrf,
        );
    }

    private function boost(): \AlohaSocial\Core\Http\Response
    {
        return $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode(self::OBJECT) . '/boost',
            [],
            $this->cookies,
            $this->csrf,
        );
    }

    private function reply(): \AlohaSocial\Core\Http\Response
    {
        return $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A reply that must not be stored',
            'visibility' => 'public',
            'in_reply_to_id' => self::OBJECT,
        ], $this->cookies, $this->csrf);
    }

    private function errorCode(string $body): string
    {
        /** @var array{error?: array{code?: string}} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return (string) ($decoded['error']['code'] ?? '');
    }

    private function seedRemotePost(string $audience): void
    {
        $this->server->runtime->database->execute(
            'INSERT INTO social_remote_post'
            . ' (activity_id, object_id, object_author_id, actor_id, kind, content, published_at, audience)'
            . " VALUES ('https://remote.example/activities/post-1', :object, :actor, :actor, 'post',"
            . " 'Remote post', '2026-11-27T10:00:00Z', :audience)",
            ['object' => self::OBJECT, 'actor' => self::ACTOR, 'audience' => $audience],
        );
    }

    private function assertNoWrites(): void
    {
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_reaction',
        ));
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_post',
        ));
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_job',
        ));
    }
}
