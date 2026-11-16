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
 * Protected profiles and the follow requests they collect.
 *
 * A pending follow is not an edge: the follower graph only changes when the
 * owner accepts. These tests drive the real front controller and the real
 * core follower service, so the counts the rest of the app reads stay honest.
 */
final class FollowRequestTest extends TestCase
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

    private function lock(string $handle): void
    {
        $response = $this->server->json('PATCH', '/api/v1/host/preferences', [
            'locked' => true,
        ], $this->aliceCookies, $this->aliceCsrf);
        self::assertSame(200, $response->status);
    }

    public function testLockedAccountCollectsARequestInsteadOfAFollower(): void
    {
        $this->lock('alice');
        self::assertTrue($this->decoded(
            $this->server->send('GET', '/api/v1/host/preferences', ['cookies' => $this->aliceCookies]),
        )['preferences']['locked']);

        // Bob follows Alice while she is protected.
        $follow = $this->server->json(
            'POST',
            '/api/v1/accounts/alice/follow',
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(200, $follow->status);
        $body = $this->decoded($follow);
        self::assertTrue($body['requested']);
        self::assertFalse($body['relationship']['following']);
        self::assertTrue($body['relationship']['requested']);

        // The follower graph has not changed yet.
        self::assertSame(0, $this->server->runtime->app->followers->count('alice'));

        $list = $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]);
        self::assertSame(200, $list->status);
        $requests = $this->decoded($list)['requests'];
        self::assertCount(1, $requests);
        self::assertSame('bob', $requests[0]['actor_handle']);
        self::assertStringEndsWith('/@bob', $requests[0]['actor_id']);

        // Only the target sees the request.
        $bobList = $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->bobCookies]);
        self::assertCount(0, $this->decoded($bobList)['requests']);

        // Asking twice must not duplicate the request.
        $again = $this->server->json(
            'POST',
            '/api/v1/accounts/alice/follow',
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(200, $again->status);
        self::assertCount(1, $this->decoded(
            $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]),
        )['requests']);
    }

    public function testAcceptingARequestCreatesTheFollowerEdgeAndNotifies(): void
    {
        $this->lock('alice');
        $this->server->json('POST', '/api/v1/accounts/alice/follow', [], $this->bobCookies, $this->bobCsrf);

        $requests = $this->decoded(
            $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]),
        )['requests'];
        $requestId = $requests[0]['id'];

        // A foreign account cannot decide on Alice's request.
        $foreign = $this->server->json(
            'POST',
            '/api/v1/host/follow-requests/' . $requestId . '/accept',
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(404, $foreign->status);

        $accepted = $this->server->json(
            'POST',
            '/api/v1/host/follow-requests/' . $requestId . '/accept',
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $accepted->status);
        self::assertSame('bob', $this->decoded($accepted)['request']['actor_handle']);

        self::assertSame(1, $this->server->runtime->app->followers->count('alice'));
        self::assertCount(0, $this->decoded(
            $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]),
        )['requests']);

        // The relationship now reports the accepted edge.
        $relationship = $this->server->send(
            'GET',
            '/api/v1/accounts/alice',
            ['cookies' => $this->bobCookies],
        );
        self::assertSame(200, $relationship->status);
        self::assertTrue($this->decoded($relationship)['account']['relationship']['following']);

        $notifications = $this->decoded(
            $this->server->send('GET', '/api/v1/notifications', ['cookies' => $this->aliceCookies]),
        )['notifications'];
        self::assertNotEmpty($notifications);
        self::assertSame('follow', $notifications[0]['type']);
    }

    public function testRejectingAndWithdrawingLeaveNoEdge(): void
    {
        $this->lock('alice');
        $this->server->json('POST', '/api/v1/accounts/alice/follow', [], $this->bobCookies, $this->bobCsrf);
        $requests = $this->decoded(
            $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]),
        )['requests'];

        $rejected = $this->server->json(
            'POST',
            '/api/v1/host/follow-requests/' . $requests[0]['id'] . '/reject',
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $rejected->status);
        self::assertSame(0, $this->server->runtime->app->followers->count('alice'));

        // A second withdraw is a successful no-op for the follower.
        $this->server->json('POST', '/api/v1/accounts/alice/follow', [], $this->bobCookies, $this->bobCsrf);
        self::assertCount(1, $this->decoded(
            $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]),
        )['requests']);

        $unfollow = $this->server->json(
            'DELETE',
            '/api/v1/accounts/alice/follow',
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );
        self::assertSame(200, $unfollow->status);
        self::assertCount(0, $this->decoded(
            $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]),
        )['requests']);
        self::assertSame(0, $this->server->runtime->app->followers->count('alice'));
    }

    public function testAnUnlockedAccountStillFollowsImmediately(): void
    {
        $follow = $this->server->json(
            'POST',
            '/api/v1/accounts/alice/follow',
            [],
            $this->bobCookies,
            $this->bobCsrf,
        );

        self::assertSame(200, $follow->status);
        self::assertTrue($this->decoded($follow)['relationship']['following']);
        self::assertSame(1, $this->server->runtime->app->followers->count('alice'));
        self::assertCount(0, $this->decoded(
            $this->server->send('GET', '/api/v1/host/follow-requests', ['cookies' => $this->aliceCookies]),
        )['requests']);
    }
}
