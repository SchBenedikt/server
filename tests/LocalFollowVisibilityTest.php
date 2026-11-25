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
 * A local follow is stored as a follower edge, not as a remote
 * `social_following` row. These features used to query only the remote table
 * and compare its actor URLs with local handles, so a followed local account
 * disappeared from stories and live updates and got no For You bonus.
 */
final class LocalFollowVisibilityTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies;

    private string $aliceCsrf;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test');
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

    public function testLocalFollowPowersStoriesLiveUpdatesAndForYou(): void
    {
        $followed = $this->server->json(
            'POST',
            '/api/v1/accounts/bob/follow',
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $followed->status, $followed->body);
        self::assertTrue($this->decoded($followed)['relationship']['following']);

        $edge = $this->server->runtime->database->fetchOne(
            'SELECT actor_id, local_user_id, is_local, state FROM social_follower WHERE local_user_id = :target',
            ['target' => 'bob'],
        );
        self::assertNotNull($edge);
        self::assertSame('http://localhost:3000/@alice', (string) $edge['actor_id']);
        self::assertSame(1, (int) $edge['is_local']);
        self::assertSame('accepted', (string) $edge['state']);
        self::assertSame([], $this->server->runtime->database->fetchAll(
            'SELECT id FROM social_following WHERE local_user_id = :viewer',
            ['viewer' => 'alice'],
        ));

        $story = $this->server->runtime->stories->create('bob', 'Bob story', 'sunset');
        $stories = $this->decoded($this->server->send('GET', '/api/v1/host/stories', [
            'cookies' => $this->aliceCookies,
        ]));
        self::assertContains($story['id'], array_column($stories['stories'], 'id'));
        self::assertContains('bob', array_column($stories['groups'], 'account_id'));

        $seen = $this->server->json(
            'POST',
            '/api/v1/host/stories/seen',
            ['ids' => [$story['id']]],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(1, $this->decoded($seen)['seen']);
        $again = $this->server->json(
            'POST',
            '/api/v1/host/stories/seen',
            ['ids' => [$story['id']]],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(0, $this->decoded($again)['seen']);

        $post = $this->server->runtime->app->posts
            ->create(new CreatePost('bob', 'A local follow should see this', Visibility::Public))
            ->id;
        $updates = $this->decoded($this->server->send('GET', '/api/v1/host/updates', [
            'query' => ['since' => '2000-01-01T00:00:00Z'],
            'cookies' => $this->aliceCookies,
        ]));
        self::assertContains($post, $updates['created']);

        $forYou = $this->decoded($this->server->send('GET', '/api/v1/host/for-you', [
            'query' => ['limit' => '20'],
            'cookies' => $this->aliceCookies,
        ]));
        self::assertContains($post, $forYou['posts']);
        self::assertSame('Because you follow this account', $forYou['reasons'][$post]);
    }

    public function testFollowingAgainThroughTheApiIsIdempotent(): void
    {
        foreach ([1, 2] as $attempt) {
            $response = $this->server->json(
                'POST',
                '/api/v1/accounts/bob/follow',
                [],
                $this->aliceCookies,
                $this->aliceCsrf,
            );
            self::assertSame(200, $response->status, 'attempt ' . $attempt . ': ' . $response->body);
        }

        $count = $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_follower WHERE local_user_id = :target',
            ['target' => 'bob'],
        );
        self::assertSame(1, (int) $count);
    }
}
