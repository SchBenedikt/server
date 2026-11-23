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
