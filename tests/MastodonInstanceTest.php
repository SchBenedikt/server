<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Mastodon instance metadata endpoints (#176): the /api/v2/instance alias
 * and the peers/rules/custom_emojis/announcements sub-resources.
 */
final class MastodonInstanceTest extends TestCase
{
    private const PREFIX = '/api/mastodon/v1';

    private TestServer $server;

    protected function setUp(): void
    {
        $this->server = new TestServer(['mastodon_api' => true]);
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

    public function testV2InstanceAliasAnswersLikeV1(): void
    {
        $v1 = $this->server->send('GET', self::PREFIX . '/instance');
        self::assertSame(200, $v1->status, $v1->body);

        $v2 = $this->server->send('GET', '/api/v2/instance');
        self::assertSame(200, $v2->status, $v2->body);
        self::assertSame($this->decoded($v1), $this->decoded($v2));
        self::assertSame('Aloha Test', $this->decoded($v2)['title']);
        self::assertSame('*', $v2->header('Access-Control-Allow-Origin'));
    }

    public function testInstanceSubresources(): void
    {
        $rules = $this->server->send('GET', self::PREFIX . '/instance/rules');
        self::assertSame(200, $rules->status);
        self::assertSame([], json_decode($rules->body, true));

        $emojis = $this->server->send('GET', self::PREFIX . '/instance/custom_emojis');
        self::assertSame(200, $emojis->status);
        self::assertSame([], json_decode($emojis->body, true));

        $peers = $this->server->send('GET', self::PREFIX . '/instance/peers');
        self::assertSame(200, $peers->status);
        self::assertSame([], json_decode($peers->body, true));
    }

    public function testPeersListKnownFederationDomains(): void
    {
        $repository = new PdoRemotePostRepository($this->server->runtime->database);
        $now = $this->server->runtime->clock->now();
        foreach ([
            'https://mastodon.example/@alice/123',
            'https://mastodon.example/@alice/124',
            'https://bsky.social/app.bsky.feed.post/3kx',
        ] as $index => $activityId) {
            $repository->insert(new RemotePost(
                activityId: $activityId,
                objectId: $activityId,
                actorId: $activityId,
                kind: RemotePostKind::Post,
                content: 'remote ' . $index,
                contentWarning: null,
                publishedAt: $now,
            ));
        }
        $this->server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . " VALUES ('follow-1', 'alice', 'https://friends.example/@bob', 'https://friends.example/inbox', '2026-01-01T00:00:00Z', 'accepted')",
        );

        $peers = $this->decoded($this->server->send('GET', self::PREFIX . '/instance/peers'));
        sort($peers);
        self::assertSame(['bsky.social', 'friends.example', 'mastodon.example'], $peers);
    }

    public function testAnnouncementsProjectToMastodonShape(): void
    {
        $this->server->runtime->announcements->create('admin', 'Wartungsfenster am Sonntag');

        $list = $this->decoded($this->server->send('GET', self::PREFIX . '/instance/announcements'));
        self::assertCount(1, $list);
        self::assertSame('Wartungsfenster am Sonntag', $list[0]['content']);
        self::assertSame('live', $list[0]['status']);
        self::assertArrayHasKey('published_at', $list[0]);

        $id = (string) $list[0]['id'];
        $single = $this->decoded(
            $this->server->send('GET', self::PREFIX . '/instance/announcements/' . rawurlencode($id)),
        );
        self::assertSame($id, $single['id']);

        $missing = $this->server->send('GET', self::PREFIX . '/instance/announcements/nope');
        self::assertSame(404, $missing->status);
    }

    public function testSubroutesRequireGetAndEnabledApi(): void
    {
        $post = $this->server->send('POST', self::PREFIX . '/instance/peers');
        self::assertNotSame(200, $post->status);

        $disabled = new TestServer(['mastodon_api' => false]);
        $response = $disabled->send('GET', self::PREFIX . '/instance/peers');
        self::assertNotSame(200, $response->status);
    }
}
