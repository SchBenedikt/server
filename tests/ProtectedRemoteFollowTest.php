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
