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
 * The profile timeline and the Nextcloud-compatible `/api/v1` aliases.
 */
final class AccountStatusesTest extends TestCase
{
    private TestServer $server;

    protected function setUp(): void
    {
        $this->server = new TestServer(['mastodon_api' => true]);
        $this->server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $this->server->createAccount('bob', 'bob@example.test');
    }

    public function testAnonymousProfileTimelineHidesRestrictedPosts(): void
    {
        $public = $this->post('alice', 'public');
        $this->post('alice', 'followers', Visibility::Followers);
        $this->post('alice', 'direct', Visibility::Direct, ['bob']);

        $response = $this->server->send('GET', '/api/v1/accounts/alice/statuses');

        self::assertSame(200, $response->status);
        $body = $this->decoded($response);
        self::assertSame([$public], array_column($body['posts'], 'id'));
        self::assertNull($body['next_before']);
    }

    public function testMastodonHashtagTimelineIsExposedThroughTheStandaloneHost(): void
    {
        $newest = $this->server->runtime->app->posts
            ->create(new CreatePost('alice', 'Newest #Aloha', postId: 'z-newest'))
            ->id;
        $older = $this->server->runtime->app->posts
            ->create(new CreatePost('alice', 'Older #aloha', postId: 'a-older'))
            ->id;
        $private = $this->server->runtime->app->posts
            ->create(new CreatePost('alice', 'Private #aloha', Visibility::Followers, postId: 'private-tag'))
            ->id;

        $first = $this->server->send('GET', '/api/mastodon/v1/timelines/tag/aloha', [
            'query' => ['limit' => '1'],
        ]);

        self::assertSame(200, $first->status);
        self::assertSame([$newest], array_column($this->decoded($first), 'id'));
        self::assertStringContainsString('max_id=' . $newest, (string) $first->header('Link'));

        $second = $this->server->send('GET', '/api/mastodon/v1/timelines/tag/aloha', [
            'query' => ['limit' => '1', 'max_id' => $newest],
        ]);

        self::assertSame(200, $second->status);
        self::assertSame([$older], array_column($this->decoded($second), 'id'));
        self::assertNotContains($private, array_column($this->decoded($first), 'id'));
    }

    public function testAnAcceptedFollowerSeesFollowersOnlyPosts(): void
    {
        $public = $this->post('alice', 'public');
        $followers = $this->post('alice', 'followers', Visibility::Followers);

        $bob = $this->server->signIn('bob');
        $this->server->json(
            'POST',
            '/api/v1/accounts/alice/follow',
            [],
            $bob['cookies'],
            $bob['csrf'],
        );

        $response = $this->server->send('GET', '/api/v1/accounts/alice/statuses', [
            'cookies' => $bob['cookies'],
        ]);

        self::assertSame(200, $response->status);		$ids = array_column($this->decoded($response)['posts'], 'id');
		$expected = [$public, $followers];
		sort($ids);
		sort($expected);
		self::assertSame($expected, $ids);
    }

    public function testFollowingFeedIncludesLocalFollowedPublicAndFollowersOnlyPosts(): void
    {
        $public = $this->post('bob', 'Bob public post');
        $followers = $this->post('bob', 'Bob followers post', Visibility::Followers);
        $this->server->createAccount('carol', 'carol@example.test');

        $alice = $this->server->signIn('alice');
        $follow = $this->server->json(
            'POST',
            '/api/v1/accounts/bob/follow',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $follow->status, $follow->body);

        $home = $this->server->send('GET', '/api/v1/timelines/home', [
            'cookies' => $alice['cookies'],
        ]);
        self::assertSame(200, $home->status, $home->body);
        $aliceIds = array_column($this->decoded($home)['posts'], 'id');
        self::assertContains($public, $aliceIds);
        self::assertContains($followers, $aliceIds);

        $carol = $this->server->signIn('carol');
        $unrelatedHome = $this->server->send('GET', '/api/v1/timelines/home', [
            'cookies' => $carol['cookies'],
        ]);
        self::assertSame(200, $unrelatedHome->status, $unrelatedHome->body);
        $carolIds = array_column($this->decoded($unrelatedHome)['posts'], 'id');
        self::assertNotContains($public, $carolIds);
        self::assertNotContains($followers, $carolIds);
    }

    public function testFollowingFeedPagesLocalPostsWithoutRepeatingOrSkippingThem(): void
    {
        for ($index = 0; $index < 9; $index++) {
            $this->post('bob', 'Bob page ' . $index);
        }

        $alice = $this->server->signIn('alice');
        $follow = $this->server->json(
            'POST',
            '/api/v1/accounts/bob/follow',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $follow->status, $follow->body);

        $actual = [];
        $query = ['limit' => '3'];
        do {
            $page = $this->server->send('GET', '/api/v1/timelines/home', [
                'cookies' => $alice['cookies'],
                'query' => $query,
            ]);
            self::assertSame(200, $page->status, $page->body);
            $body = $this->decoded($page);
            array_push($actual, ...array_column($body['posts'], 'id'));

            if ($body['has_more']) {
                self::assertIsString($body['next_before']);
                self::assertIsString($body['next_before_id']);
                $query['before'] = $body['next_before'];
                $query['before_id'] = $body['next_before_id'];
            }
        } while ($body['has_more']);

        $expected = array_column($this->server->runtime->database->fetchAll(
            'SELECT id FROM social_post WHERE author_id = :author ORDER BY published_at DESC, id DESC',
            ['author' => 'bob'],
        ), 'id');
        self::assertCount(9, $actual);
        self::assertCount(count(array_unique($actual)), $actual);
        self::assertSame($expected, $actual);
    }

    public function testProfileCursorCanBePassedBackUnmodified(): void
    {
        foreach (range(1, 5) as $index) {
            $id = $this->post('alice', 'cursor post ' . $index);
            $this->server->runtime->database->execute(
                'UPDATE social_post SET published_at = :at WHERE id = :id',
                ['at' => sprintf('2026-11-18T10:00:%02dZ', $index), 'id' => $id],
            );
        }

        $first = $this->server->send('GET', '/api/v1/accounts/alice/statuses', [
            'query' => ['limit' => '2'],
        ]);
        self::assertSame(200, $first->status, $first->body);
        $firstPage = $this->decoded($first);
        self::assertIsString($firstPage['next_before']);

        $second = $this->server->send('GET', '/api/v1/accounts/alice/statuses', [
            'query' => ['limit' => '2', 'before' => $firstPage['next_before']],
        ]);
        self::assertSame(200, $second->status, $second->body);
        $secondPage = $this->decoded($second);
        self::assertSame([], array_values(array_intersect(
            array_column($firstPage['posts'], 'id'),
            array_column($secondPage['posts'], 'id'),
        )));
        self::assertNotEmpty($secondPage['posts']);
    }

    public function testNextcloudCompatibleAliasesReachMastodonHandlers(): void
    {
        $public = $this->post('alice', 'public');

        $status = $this->server->send('GET', '/api/v1/statuses/' . $public);
        self::assertSame(200, $status->status, $status->body);
        self::assertSame($public, $this->decoded($status)['id']);

        $bookmarks = $this->server->send('GET', '/api/v1/bookmarks');
        self::assertSame(401, $bookmarks->status);
    }

    /**
     * @param list<string> $recipientIds
     */
    private function post(string $authorId, string $content, Visibility $visibility = Visibility::Public, array $recipientIds = []): string
    {
        return $this->server->runtime->app->posts
            ->create(new CreatePost($authorId, $content, $visibility, recipientIds: $recipientIds))
            ->id;
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
}
