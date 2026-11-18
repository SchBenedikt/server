<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * A remote peer must see the same protected-account policy the host UI enforces.
 * The real server bootstrap passes a host profile provider into the core inbox.
 */
final class ProtectedRemoteFollowTest extends TestCase
{
    public function testLockedAccountAdvertisesManualApprovalAndRealDisplayName(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $server->runtime->accounts->updateDisplayName('alice', 'Alice Example');

        $session = $server->signIn('alice');
        $update = $server->json(
            'PATCH',
            '/api/v1/host/preferences',
            ['locked' => true],
            $session['cookies'],
            $session['csrf'],
        );
        self::assertSame(200, $update->status);

        $actor = $this->actor($server);
        self::assertSame('Alice Example', $actor['name']);
        self::assertTrue($actor['manuallyApprovesFollowers'] ?? false);

        $unlock = $server->json(
            'PATCH',
            '/api/v1/host/preferences',
            ['locked' => false],
            $session['cookies'],
            $session['csrf'],
        );
        self::assertSame(200, $unlock->status);

        $actor = $this->actor($server);
        self::assertArrayNotHasKey('manuallyApprovesFollowers', $actor);
    }

    public function testTheOwnerCanAcceptARemoteFollowRequestFromTheUiRoute(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $server->runtime->app->followers->follow(
            new FollowActor('alice', 'https://peer.example/@bob', 'https://peer.example/inbox'),
            requiresApproval: true,
            followActivity: [
                'id' => 'https://peer.example/activities/follow-1',
                'type' => 'Follow',
                'actor' => 'https://peer.example/@bob',
                'object' => 'http://localhost:3000/@alice',
            ],
        );

        $session = $server->signIn('alice');
        $list = $server->send('GET', '/api/v1/host/follow-requests', [
            'cookies' => $session['cookies'],
        ]);
        self::assertSame(200, $list->status);
        $requests = json_decode($list->body, true, 512, JSON_THROW_ON_ERROR)['requests'];
        self::assertCount(1, $requests);
        self::assertFalse($requests[0]['is_local']);

        $accept = $server->json(
            'POST',
            '/api/v1/host/follow-requests/' . $requests[0]['id'] . '/accept',
            [],
            $session['cookies'],
            $session['csrf'],
        );
        self::assertSame(200, $accept->status);

        $after = $server->send('GET', '/api/v1/host/follow-requests', [
            'cookies' => $session['cookies'],
        ]);
        self::assertSame([], json_decode($after->body, true, 512, JSON_THROW_ON_ERROR)['requests']);
    }

    /** @return array<string, mixed> */
    private function actor(TestServer $server): array
    {
        $response = $server->send('GET', '/@alice', [
            'headers' => ['Accept' => 'application/activity+json'],
        ]);
        self::assertSame(200, $response->status);

        /** @var array<string, mixed> $actor */
        $actor = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return $actor;
    }
}
