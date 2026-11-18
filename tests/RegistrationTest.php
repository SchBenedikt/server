<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Accounts\RegistrationException;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class RegistrationTest extends TestCase
{
    public function testClosedRegistrationRefusesNewAccounts(): void
    {
        $server = new TestServer(['registrations' => false]);

        $this->expectException(RegistrationException::class);
        $server->runtime->registration->register('newbie', 'new@example.test', 'correct-horse');
    }

    public function testApprovalCreatesADisabledAccount(): void
    {
        $server = new TestServer([
            'registrations' => true,
            'registration_approval' => true,
        ]);

        $account = $server->runtime->registration->register('newbie', 'new@example.test', 'correct-horse');

        self::assertTrue($account->isDisabled());
        self::assertSame('newbie', $account->handle);
    }

    public function testWithoutApprovalTheAccountCanSignInImmediately(): void
    {
        $server = new TestServer([
            'registrations' => true,
            'registration_approval' => false,
        ]);

        $account = $server->runtime->registration->register('newbie', 'new@example.test', 'correct-horse');

        self::assertFalse($account->isDisabled());
        $login = $server->login('newbie', 'correct-horse');
        self::assertSame(303, $login['response']->status);
    }

    public function testNativePdsStatusIsExposedToItsOwnerOnly(): void
    {
        $server = new TestServer([
            'atproto_pds' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);
        $server->createAccount('alice', 'alice@example.test');
        $session = $server->signIn('alice');

        $anonymous = $server->send('GET', '/api/v1/host/atproto');
        self::assertSame(401, $anonymous->status);

        $response = $server->send('GET', '/api/v1/host/atproto', [
            'cookies' => $session['cookies'],
        ]);
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['enabled']);
        self::assertNull($body['identity']);
        self::assertSame('social.example', $body['handle_domain']);
    }

    public function testProtectedPagesSendAnonymousVisitorsBackAfterSignIn(): void
    {
        $server = new TestServer();

        $app = $server->send('GET', '/admin');
        self::assertSame(303, $app->status);
        self::assertSame('/login?next=%2Fadmin', $app->headers['Location'] ?? '');

        $login = $server->send('GET', '/login', ['query' => ['next' => '/admin']]);
        self::assertSame(200, $login->status);
        self::assertStringContainsString('name="return_to" value="/admin"', $login->body);
    }

    public function testTheAdminConsoleIsServedOutsideTheSocialShell(): void
    {
        $server = new TestServer();
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $session = $server->signIn('admin');

        $response = $server->send('GET', '/admin', ['cookies' => $session['cookies']]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('/admin/admin.css', $response->body);
        self::assertStringContainsString('Standalone console', $response->body);
        self::assertStringNotContainsString('aloha-social', $response->body);
    }

    public function testAdminSettingsWriteThePersistentEnvFile(): void
    {
        $server = new TestServer();
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);

        $result = $server->runtime->admin->updateSettings('admin', [
            'registrations' => true,
            'registration_approval' => false,
            'core_channel' => 'release',
            'core_version' => '0.7.0',
            'atproto_pds' => false,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);

        self::assertTrue($result['restart_required']);
        self::assertTrue($result['registrations']['enabled']);
        self::assertFalse($result['registrations']['approval']);

        $env = (string) file_get_contents($server->config->envFile);
        self::assertStringContainsString('ALOHASOCIAL_REGISTRATIONS=true', $env);
        self::assertStringContainsString('ALOHASOCIAL_REGISTRATION_APPROVAL=false', $env);
        self::assertStringContainsString('ALOHASOCIAL_CORE_CHANNEL=release', $env);
        self::assertStringContainsString('ALOHASOCIAL_CORE_VERSION=0.7.0', $env);
    }
}
