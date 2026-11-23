<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Setup\SetupToken;
use AlohaSocial\Server\Support\InstallationReset;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'ALOHASOCIAL_INSTANCE_URL',
            'ALOHASOCIAL_INSTANCE_NAME',
            'ALOHASOCIAL_INSTANCE_DESCRIPTION',
            'ALOHASOCIAL_CONTACT_EMAIL',
            'ALOHASOCIAL_SECURE_COOKIES',
        ] as $key) {
            putenv($key);
        }
    }

    public function testSetupRequiresTheOneTimeToken(): void
    {
        $server = new TestServer();
        $setupToken = new SetupToken($server->config->varDirectory . '/setup.token');
        $token = $setupToken->generate();
        self::assertSame(0o600, fileperms($server->config->varDirectory . '/setup.token') & 0o777);

        self::assertSame('serialized', $setupToken->synchronized(static fn (): string => 'serialized'));

        $missing = $server->send('GET', '/setup');
        self::assertSame(403, $missing->status);
        self::assertStringContainsString('Setup is locked', $missing->body);

        $wrong = $server->send('GET', '/setup', ['query' => ['token' => 'not-the-token']]);
        self::assertSame(403, $wrong->status);

        $valid = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        self::assertSame(200, $valid->status);
        self::assertStringContainsString('Set up your instance', $valid->body);
        self::assertStringContainsString('name="token"', $valid->body);
    }

    public function testSetupWritesTheOriginAndCreatesTheFirstAdmin(): void
    {
        $server = new TestServer();
        $setupToken = new SetupToken($server->config->varDirectory . '/setup.token');
        $token = $setupToken->generate();

        $page = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        $cookies = $server->cookiesFrom($page);
        $csrf = $server->csrfFrom($page->body);
        self::assertNotNull($csrf);

        $response = $server->form('/setup', [
            '_csrf' => $csrf,
            'token' => $token,
            'url' => 'https://social.example',
            'name' => 'Kokua Community',
            'description' => 'Neighbours helping neighbours.',
            'contact' => 'admin@social.example',
            'handle' => 'admin',
            'email' => 'admin@social.example',
            'display_name' => 'Administrator',
            'password' => 'a-long-enough-password',
            'password_confirm' => 'a-long-enough-password',
        ], $cookies);

        self::assertSame(303, $response->status);
        self::assertSame('https://social.example/login?setup=1', $response->header('Location'));
        self::assertFalse($setupToken->exists());

        $account = $server->runtime->accounts->findByHandle('admin');
        self::assertNotNull($account);
        self::assertSame(Account::ROLE_ADMIN, $account->role);
        self::assertSame('Administrator', $account->name());

        $env = (string) file_get_contents($server->config->envFile);
        self::assertStringContainsString('ALOHASOCIAL_INSTANCE_URL=https://social.example', $env);
        self::assertStringContainsString('ALOHASOCIAL_INSTANCE_NAME=', $env);
        self::assertStringContainsString('Kokua Community', $env);
        self::assertStringContainsString('ALOHASOCIAL_SECURE_COOKIES=true', $env);

        $closed = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        self::assertSame(404, $closed->status);
    }

    public function testSetupRejectsInvalidInputWithoutCreatingAnAccount(): void
    {
        $server = new TestServer();
        $token = (new SetupToken($server->config->varDirectory . '/setup.token'))->generate();
        $page = $server->send('GET', '/setup', ['query' => ['token' => $token]]);
        $cookies = $server->cookiesFrom($page);
        $csrf = $server->csrfFrom($page->body);

        $response = $server->form('/setup', [
            '_csrf' => $csrf,
            'token' => $token,
            'url' => 'https://social.example/path',
            'name' => '',
            'handle' => 'A',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirm' => 'different',
        ], $cookies);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Public URL', $response->body);
        self::assertNull($server->runtime->accounts->findByHandle('a'));
        self::assertTrue((new SetupToken($server->config->varDirectory . '/setup.token'))->exists());
    }

    public function testResetDropsAllTablesAndCanRebuildTheEmptySchema(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $reset = new InstallationReset($server->runtime->database);
        $dropped = $reset->dropAllTables();
        self::assertContains('account', $dropped);
        self::assertContains('social_post', $dropped);

        $runner = new MigrationRunner($server->runtime->database, new SystemClock(), Migrations::all());
        $runner->migrate();

        self::assertSame(
            0,
            (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM account'),
        );
        self::assertSame(
            1,
            (int) $server->runtime->database->fetchValue(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'account'",
            ),
        );
    }
}
