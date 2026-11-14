<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class HostExtensionTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $aliceCookies = [];

    private string $aliceCsrf = '';

    /** @var array<string, string> */
    private array $bobCookies = [];

    private string $bobCsrf = '';

    private string $postId = '';

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount('alice');
        $this->server->createAccount('bob');

        $alice = $this->server->signIn('alice');
        $this->aliceCookies = $alice['cookies'];
        $this->aliceCsrf = $alice['csrf'];

        $bob = $this->server->signIn('bob');
        $this->bobCookies = $bob['cookies'];
        $this->bobCsrf = $bob['csrf'];

        $created = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'A post for the host interaction layer',
            'visibility' => 'public',
        ], $this->aliceCookies, $this->aliceCsrf);

        self::assertSame(201, $created->status);
        /** @var array{post: array{id: string}} $body */
        $body = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);
        $this->postId = $body['post']['id'];
    }

    public function testLikeIsIdempotentAndViewerSpecific(): void
    {
        $liked = $this->server->json('POST', '/api/v1/posts/' . $this->postId . '/like', [], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $liked->status);
        /** @var array{interaction: array{favourites_count: int, favourited: bool}} $likeBody */
        $likeBody = json_decode($liked->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $likeBody['interaction']['favourites_count']);
        self::assertTrue($likeBody['interaction']['favourited']);

        $repeated = $this->server->json('POST', '/api/v1/posts/' . $this->postId . '/like', [], $this->bobCookies, $this->bobCsrf);
        /** @var array{interaction: array{favourites_count: int}} $repeatedBody */
        $repeatedBody = json_decode($repeated->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $repeatedBody['interaction']['favourites_count']);

        $viewer = $this->server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $this->bobCookies,
            'query' => ['ids' => $this->postId],
        ]);
        /** @var array{interactions: array<string, array{favourited: bool}>} $viewerBody */
        $viewerBody = json_decode($viewer->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($viewerBody['interactions'][$this->postId]['favourited']);

        $authorView = $this->server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $this->aliceCookies,
            'query' => ['ids' => $this->postId],
        ]);
        /** @var array{interactions: array<string, array{favourited: bool}>} $authorBody */
        $authorBody = json_decode($authorView->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($authorBody['interactions'][$this->postId]['favourited']);

        $unliked = $this->server->json('DELETE', '/api/v1/posts/' . $this->postId . '/like', [], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $unliked->status);
        /** @var array{interaction: array{favourites_count: int, favourited: bool}} $unlikedBody */
        $unlikedBody = json_decode($unliked->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $unlikedBody['interaction']['favourites_count']);
        self::assertFalse($unlikedBody['interaction']['favourited']);
    }

    public function testLikeRequiresAuthenticationAndCsrf(): void
    {
        $anonymous = $this->server->send('POST', '/api/v1/posts/' . $this->postId . '/like');
        self::assertSame(401, $anonymous->status);

        $withoutCsrf = $this->server->send('POST', '/api/v1/posts/' . $this->postId . '/like', [
            'cookies' => $this->bobCookies,
        ]);
        self::assertSame(403, $withoutCsrf->status);
        self::assertStringContainsString('csrf_token_invalid', $withoutCsrf->body);
    }

    public function testBatchPostHydrationReturnsFullPostsAndIgnoresMissingIds(): void
    {
        $response = $this->server->send('GET', '/api/v1/host/posts', [
            'query' => ['ids' => $this->postId . ',does-not-exist'],
        ]);

        self::assertSame(200, $response->status);
        /** @var array{posts: array<string, array{content: string}>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey($this->postId, $body['posts']);
        self::assertSame('A post for the host interaction layer', $body['posts'][$this->postId]['content']);
        self::assertArrayNotHasKey('does-not-exist', $body['posts']);
    }

    public function testProfileUpdateValidatesCsrfAndUpdatesTheHostDirectory(): void
    {
        $withoutCsrf = $this->server->json('PATCH', '/api/v1/host/profile', [
            'display_name' => 'Bobby',
        ], $this->bobCookies);
        self::assertSame(403, $withoutCsrf->status);

        $updated = $this->server->json('PATCH', '/api/v1/host/profile', [
            'display_name' => 'Bobby',
        ], $this->bobCookies, $this->bobCsrf);
        self::assertSame(200, $updated->status);
        /** @var array{account: array{handle: string, display_name: string}} $body */
        $body = json_decode($updated->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('bob', $body['account']['handle']);
        self::assertSame('Bobby', $body['account']['display_name']);

        $profiles = $this->server->send('GET', '/api/v1/host/profiles');
        self::assertSame(200, $profiles->status);
        self::assertStringContainsString('"bob":"Bobby"', $profiles->body);
    }
}
