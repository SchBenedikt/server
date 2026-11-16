<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Community\PinnedPosts;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Pinned posts.
 *
 * A pin is a private host marker that keeps an own, non-direct post at the top
 * of the profile. It never changes the post, is bounded per account and is
 * readable by everyone the profile is visible to.
 */
final class PinnedPostTest extends TestCase
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

    private function post(string $authorId, string $content, Visibility $visibility = Visibility::Public, array $recipientIds = []): string
    {
        return $this->server->runtime->app->posts
            ->create(new CreatePost($authorId, $content, $visibility, recipientIds: $recipientIds))
            ->id;
    }

    public function testPinAndUnpinKeepTheOrderAndAreOwnerOnly(): void
    {
        $first = $this->post('alice', 'Erster Beitrag');
        $second = $this->post('alice', 'Zweiter Beitrag');
        $foreign = $this->post('bob', 'Nicht deiner');

        $anonymous = $this->server->send('GET', '/api/v1/host/pinned');
        self::assertSame(404, $anonymous->status);
        $public = $this->server->send('GET', '/api/v1/host/pinned', [
            'query' => ['account' => 'alice'],
        ]);
        self::assertSame(200, $public->status);
        self::assertSame([], $this->decoded($public)['pins']);

        $forbidden = $this->server->json(
            'POST',
            '/api/v1/host/pinned',
            ['post_id' => $foreign],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(403, $forbidden->status);

        $pinned = $this->server->json(
            'POST',
            '/api/v1/host/pinned',
            ['post_id' => $first],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(201, $pinned->status);
        self::assertTrue($this->decoded($pinned)['pin']['pinned']);
        self::assertSame([$first], $this->decoded($pinned)['pin']['pins']);

        $this->server->json(
            'POST',
            '/api/v1/host/pinned',
            ['post_id' => $second],
            $this->aliceCookies,
            $this->aliceCsrf,
        );

        $list = $this->server->send('GET', '/api/v1/host/pinned', [
            'query' => ['account' => 'alice'],
        ]);
        self::assertSame([$first, $second], $this->decoded($list)['pins']);

        $again = $this->server->json(
            'POST',
            '/api/v1/host/pinned',
            ['post_id' => $first],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(201, $again->status);
        self::assertSame([$first, $second], $this->decoded($again)['pin']['pins']);

        $unpinned = $this->server->json(
            'DELETE',
            '/api/v1/host/pinned/' . $first,
            [],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(200, $unpinned->status);
        self::assertFalse($this->decoded($unpinned)['pin']['pinned']);
        self::assertSame([$second], $this->decoded($unpinned)['pin']['pins']);
    }

    public function testDirectPostsCannotBePinned(): void
    {
        $direct = $this->post('alice', 'Nur für Bob', Visibility::Direct, ['bob']);

        $response = $this->server->json(
            'POST',
            '/api/v1/host/pinned',
            ['post_id' => $direct],
            $this->aliceCookies,
            $this->aliceCsrf,
        );

        self::assertSame(422, $response->status);
        self::assertSame([], $this->server->runtime->pinnedPosts->idsFor('alice'));
    }

    public function testThePinCountIsBounded(): void
    {
        $this->server->runtime->pinnedPosts; // ensure the service is wired

        for ($index = 0; $index < PinnedPosts::MAX_PINS; $index++) {
            $post = $this->post('alice', 'Beitrag ' . $index);
            $this->server->runtime->pinnedPosts->pin('alice', $post);
        }

        $overflow = $this->post('alice', 'Ein weiterer Beitrag');
        $response = $this->server->json(
            'POST',
            '/api/v1/host/pinned',
            ['post_id' => $overflow],
            $this->aliceCookies,
            $this->aliceCsrf,
        );

        self::assertSame(422, $response->status);
        self::assertCount(PinnedPosts::MAX_PINS, $this->server->runtime->pinnedPosts->idsFor('alice'));
    }

    public function testPinsAreScopedPerAccount(): void
    {
        $alicePost = $this->post('alice', 'Alice');
        $bobPost = $this->post('bob', 'Bob');

        $this->server->runtime->pinnedPosts->pin('alice', $alicePost);
        $this->server->runtime->pinnedPosts->pin('bob', $bobPost);

        $bobList = $this->server->send('GET', '/api/v1/host/pinned', [
            'query' => ['account' => 'bob'],
        ]);
        self::assertSame([$bobPost], $this->decoded($bobList)['pins']);

        $aliceList = $this->server->send('GET', '/api/v1/host/pinned', [
            'query' => ['account' => 'alice'],
        ]);
        self::assertSame([$alicePost], $this->decoded($aliceList)['pins']);

        $missing = $this->server->json(
            'POST',
            '/api/v1/host/pinned',
            ['post_id' => 'does-not-exist'],
            $this->aliceCookies,
            $this->aliceCsrf,
        );
        self::assertSame(404, $missing->status);
    }

    public function testDeletingAPostLeavesAStalePinHarmless(): void
    {
        $post = $this->post('alice', 'Wird gelöscht');
        $this->server->runtime->pinnedPosts->pin('alice', $post);
        $this->server->runtime->app->posts->delete('alice', $post);

        $list = $this->server->send('GET', '/api/v1/host/pinned', [
            'query' => ['account' => 'alice'],
        ]);

        self::assertSame(200, $list->status);
        self::assertSame([$post], $this->decoded($list)['pins']);
    }
}
