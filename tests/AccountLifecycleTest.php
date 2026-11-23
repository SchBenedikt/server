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

final class AccountLifecycleTest extends TestCase
{
    public function testAccountDeletionErasesCoreDataAndRetiresHostCredentials(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $login = $server->signIn('alice');
        $post = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Private account data', Visibility::Public),
        );

        $response = $server->json(
            'DELETE',
            '/api/v1/account/data',
            [],
            $login['cookies'],
            $login['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('erased', $body['deletion']['state']);
        self::assertTrue($body['account_disabled']);
        self::assertSame('ActivityPub and Bluesky data at remote servers may persist.', $body['remote_deletion']);
        self::assertNotEmpty($body['deletion']['deleted']);

        $account = $server->runtime->accounts->find('alice');
        self::assertNotNull($account);
        self::assertTrue($account->isDisabled());
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_post WHERE id = :id',
            ['id' => $post->id],
        ));
        self::assertSame(401, $server->send(
            'GET',
            '/api/v1/host/preferences',
            ['cookies' => $login['cookies']],
        )->status);
        self::assertSame(404, $server->send('GET', '/@alice')->status);
        self::assertSame('erased', $server->runtime->app->accountData?->deletionStatus('alice')->state);
    }

    public function testLastAdministratorCannotDeleteTheirOwnAccount(): void
    {
        $server = new TestServer();
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $login = $server->signIn('admin');

        $response = $server->json(
            'DELETE',
            '/api/v1/account/data',
            [],
            $login['cookies'],
            $login['csrf'],
        );

        self::assertSame(409, $response->status);
        self::assertFalse($server->runtime->accounts->find('admin')?->isDisabled());
        self::assertSame('active', $server->runtime->app->accountData?->deletionStatus('admin')->state);
    }
}
