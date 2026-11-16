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
 * The host exposes the viewer's remote follow edges so the profile UI can
 * show "Requested"/"Following" and offer an unfollow button.
 */
final class RemoteFollowingTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies;

    /** @var array<string, string> */
    private array $bobCookies;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice');
        $this->server->createAccount('bob');

        $this->aliceCookies = $this->server->signIn('alice')['cookies'];
        $this->bobCookies = $this->server->signIn('bob')['cookies'];

        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-remote-1', 'alice', 'https://remote.test/users/carol', 'https://remote.test/inbox', '2026-11-16T00:00:00Z', 'accepted')",
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('f-remote-2', 'alice', 'https://remote.test/users/dave', 'https://remote.test/inbox', '2026-11-16T00:00:00Z', 'pending')",
        );
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

    public function testListsOnlyTheViewersRemoteFollows(): void
    {
        $response = $this->server->send('GET', '/api/v1/host/following', [
            'cookies' => $this->aliceCookies,
        ]);
        self::assertSame(200, $response->status);
        $body = $this->decoded($response);
        self::assertCount(2, $body['following']);

        $byActor = [];
        foreach ($body['following'] as $entry) {
            $byActor[$entry['actor']] = $entry;
        }

        self::assertSame('accepted', $byActor['https://remote.test/users/carol']['state']);
        self::assertSame('pending', $byActor['https://remote.test/users/dave']['state']);

        $other = $this->server->send('GET', '/api/v1/host/following', [
            'cookies' => $this->bobCookies,
        ]);
        self::assertSame(200, $other->status);
        self::assertSame([], $this->decoded($other)['following']);
    }

    public function testAnonymousCallersAreRejected(): void
    {
        $response = $this->server->send('GET', '/api/v1/host/following');

        self::assertSame(401, $response->status);
        self::assertSame('not_authenticated', $this->decoded($response)['error']['code']);
    }
}
