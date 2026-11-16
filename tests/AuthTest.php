<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private TestServer $server;

    protected function setUp(): void
    {
        $this->server = new TestServer();
        $this->server->createAccount();
    }

    public function testLoginPageIssuesASessionAndACsrfToken(): void
    {
        $response = $this->server->send('GET', '/login');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="_csrf"', $response->body);

        $cookie = $response->header('Set-Cookie');
        self::assertNotNull($cookie);
        self::assertStringContainsString(TestServer::COOKIE . '=', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    public function testLoginWithoutCsrfIsRejected(): void
    {
        $form = $this->server->send('GET', '/login');
        $cookies = $this->server->cookiesFrom($form);

        $response = $this->server->form('/login', [
            'handle' => 'alice',
            'password' => TestServer::PASSWORD,
        ], $cookies);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('expired', $response->body);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $form = $this->server->send('GET', '/login');
        $cookies = $this->server->cookiesFrom($form);
        $csrf = $this->server->csrfFrom($form->body);

        $response = $this->server->form('/login', [
            '_csrf' => $csrf,
            'handle' => 'alice',
            'password' => 'wrong-password',
        ], $cookies);

        self::assertSame(401, $response->status);
        self::assertStringContainsString('incorrect', $response->body);
    }

    public function testLoginRotatesTheSessionId(): void
    {
        $form = $this->server->send('GET', '/login');
        $before = $this->server->cookiesFrom($form)[TestServer::COOKIE];
        $csrf = $this->server->csrfFrom($form->body);

        $response = $this->server->form('/login', [
            '_csrf' => $csrf,
            'handle' => 'alice',
            'password' => TestServer::PASSWORD,
        ], $this->server->cookiesFrom($form));

        self::assertSame(303, $response->status);
        self::assertSame('/', $response->header('Location'));

        $after = $this->server->cookiesFrom($response)[TestServer::COOKIE];
        self::assertNotSame($before, $after);
    }

    public function testSignedInHomeShowsTheAccountAndActor(): void
    {
        $session = $this->server->signIn();

        self::assertSame(200, $session['home']->status);
        self::assertStringContainsString('@alice', $session['home']->body);
        self::assertStringContainsString('http://localhost:3000/@alice', $session['home']->body);
    }

    public function testHomeRedirectsAnonymousVisitorsToTheLogin(): void
    {
        $response = $this->server->send('GET', '/');

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->header('Location'));
    }

    public function testLogoutClearsTheSession(): void
    {
        $session = $this->server->signIn();

        $withoutCsrf = $this->server->form('/logout', [], $session['cookies']);
        self::assertSame(403, $withoutCsrf->status);

        $logout = $this->server->form('/logout', ['_csrf' => $session['csrf']], $session['cookies']);
        self::assertSame(303, $logout->status);

        $home = $this->server->send('GET', '/', ['cookies' => $session['cookies']]);
        self::assertSame(303, $home->status);
        self::assertSame('/login', $home->header('Location'));
    }

    public function testDisabledAccountCannotSignIn(): void
    {
        $this->server->createAccount('bob');
        $this->server->runtime->accounts->disable('bob');

        $login = $this->server->login('bob', TestServer::PASSWORD);

        self::assertSame(401, $login['response']->status);
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->server->runtime->throttle->recordFailure('alice', '127.0.0.1');
        }

        $login = $this->server->login('alice', TestServer::PASSWORD);

        self::assertSame(429, $login['response']->status);
        self::assertStringContainsString('failed attempts', $login['response']->body);
    }
}
