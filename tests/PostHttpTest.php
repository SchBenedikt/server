<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class PostHttpTest extends TestCase
{
    private TestServer $server;

    /** @var array<string, string> */
    private array $cookies = [];

    private string $csrf = '';

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount();

        $session = $this->server->signIn();
        $this->cookies = $session['cookies'];
        $this->csrf = $session['csrf'];
    }

    public function testAnonymousWriteIsRejected(): void
    {
        $response = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'Anonymous attempt',
            'visibility' => 'public',
        ]);

        self::assertSame(401, $response->status);
        self::assertStringContainsString('authentication_required', $response->body);
    }

    public function testSessionWriteWithoutCsrfIsRejected(): void
    {
        $response = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'No CSRF token',
            'visibility' => 'public',
        ], $this->cookies);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('csrf_token_invalid', $response->body);
    }

    public function testPostIsWrittenAndReadBackOverHttp(): void
    {
        $created = $this->server->json('POST', '/api/v1/posts', [
            'content' => 'Hello #fediverse from the standalone server',
            'visibility' => 'public',
        ], $this->cookies, $this->csrf);

        self::assertSame(201, $created->status);

        /** @var array{post: array{id: string, content: string}} $body */
        $body = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('post', $body);
        $id = $body['post']['id'];

        $read = $this->server->send('GET', '/api/v1/posts/' . $id);
        self::assertSame(200, $read->status);
        self::assertStringContainsString('Hello #fediverse', $read->body);

        $timeline = $this->server->send('GET', '/api/v1/timelines/public');
        self::assertSame(200, $timeline->status);
        self::assertStringContainsString('Hello #fediverse', $timeline->body);
    }

    public function testActorDocumentAndWebFingerResolveTheAccount(): void
    {
        $actor = $this->server->send('GET', '/@alice');
        self::assertSame(200, $actor->status);
        self::assertStringContainsString('http://localhost:3000/@alice', $actor->body);

        $webfinger = $this->server->send('GET', '/.well-known/webfinger', [
            'query' => ['resource' => 'acct:alice@localhost'],
        ]);
        self::assertSame(200, $webfinger->status);
        self::assertStringContainsString('acct:alice@localhost', $webfinger->body);
    }

    public function testWorkerIsIdleWithoutJobs(): void
    {
        self::assertSame(0, $this->server->runtime->app->worker->runOnce(10));
    }
}
