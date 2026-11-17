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
 * A peer sees the ids this instance advertises. They must resolve to the same
 * ActivityPub documents the outbox and delivery use, not to the internal API
 * JSON or a 404.
 */
final class FederationDiscoveryTest extends TestCase
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

    public function testTheAdvertisedActivityUrlReturnsTheCreateActivity(): void
    {
        $id = $this->createPost('Dereferenceable activity');

        $response = $this->server->send('GET', '/api/v1/posts/' . $id . '/activity', [
            'headers' => ['Accept' => 'application/activity+json'],
        ]);

        self::assertSame(200, $response->status);
        self::assertSame('application/activity+json; charset=utf-8', $response->header('Content-Type'));

        $activity = $this->decode($response->body);
        self::assertSame('Create', $activity['type']);
        self::assertSame('http://localhost:3000/api/v1/posts/' . $id . '/activity', $activity['id']);

        $note = $activity['object'];
        self::assertIsArray($note);
        self::assertSame('Note', $note['type']);
        self::assertSame('http://localhost:3000/api/v1/posts/' . $id, $note['id']);
        self::assertSame('Dereferenceable activity', $note['content']);
    }

    public function testTheNoteUrlNegotiatesActivityPubWithoutChangingTheApiShape(): void
    {
        $id = $this->createPost('Content negotiated');

        $noteResponse = $this->server->send('GET', '/api/v1/posts/' . $id, [
            'headers' => [
                'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            ],
        ]);

        self::assertSame(200, $noteResponse->status);
        self::assertSame('application/activity+json; charset=utf-8', $noteResponse->header('Content-Type'));
        self::assertSame('Accept', $noteResponse->header('Vary'));

        $note = $this->decode($noteResponse->body);
        self::assertSame('*', $noteResponse->header('Access-Control-Allow-Origin'));
        self::assertSame('Note', $note['type']);
        self::assertSame('http://localhost:3000/api/v1/posts/' . $id, $note['id']);
        self::assertSame('http://localhost:3000/@alice', $note['attributedTo']);

        $apiResponse = $this->server->send('GET', '/api/v1/posts/' . $id, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertSame(200, $apiResponse->status);
        self::assertSame('application/json; charset=utf-8', $apiResponse->header('Content-Type'));
        self::assertSame($id, $this->decode($apiResponse->body)['post']['id']);
    }

    public function testAPrivatePostIsNotAddressableAsAPublicNoteOrActivity(): void
    {
        $id = $this->createPost('Only for followers', 'followers');

        foreach (['/api/v1/posts/' . $id, '/api/v1/posts/' . $id . '/activity'] as $path) {
            $response = $this->server->send('GET', $path, [
                'headers' => ['Accept' => 'application/activity+json'],
            ]);

            self::assertSame(404, $response->status, $path);
            self::assertSame('post_not_found', $this->decode($response->body)['error']['code']);
        }
    }

    public function testWebFingerIsCorsReadable(): void
    {
        $response = $this->server->send('GET', '/.well-known/webfinger', [
            'query' => ['resource' => 'acct:alice@localhost:3000'],
        ]);

        self::assertSame(200, $response->status);
        self::assertSame('*', $response->header('Access-Control-Allow-Origin'));
        self::assertSame(
            'http://localhost:3000/@alice',
            $this->decode($response->body)['links'][0]['href'],
        );
    }

    public function testNodeInfoAdvertisesActivityPubAndTheInstanceCounts(): void
    {
        $index = $this->server->send('GET', '/.well-known/nodeinfo');
        self::assertSame(200, $index->status);
        self::assertSame('application/jrd+json; charset=utf-8', $index->header('Content-Type'));

        $links = $this->decode($index->body)['links'];
        self::assertIsArray($links);
        self::assertSame(
            'http://localhost:3000/nodeinfo/2.0',
            $links[0]['href'],
        );

        $document = $this->server->send('GET', '/nodeinfo/2.0');
        self::assertSame(200, $document->status);

        $nodeInfo = $this->decode($document->body);
        self::assertSame('2.0', $nodeInfo['version']);
        self::assertSame('alohasocial', $nodeInfo['software']['name']);
        self::assertSame(['activitypub'], $nodeInfo['protocols']);
        self::assertSame(1, $nodeInfo['usage']['users']['total']);
        self::assertSame('Aloha Test', $nodeInfo['metadata']['nodeName']);

        $v21 = $this->server->send('GET', '/nodeinfo/2.1');
        self::assertSame(200, $v21->status);
        self::assertSame('2.1', $this->decode($v21->body)['version']);
    }

    private function createPost(string $content, string $visibility = 'public'): string
    {
        $response = $this->server->json('POST', '/api/v1/posts', [
            'content' => $content,
            'visibility' => $visibility,
        ], $this->cookies, $this->csrf);

        self::assertSame(201, $response->status, $response->body);

        return (string) $this->decode($response->body)['post']['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
