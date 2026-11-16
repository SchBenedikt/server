<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class PasswordResetTest extends TestCase
{
    public function testEmailedLinkChangesThePasswordAndRevokesOldSessions(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $signedIn = $server->signIn();

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $sent = $server->form('/forgot-password', [
            '_csrf' => $csrf,
            'identifier' => 'alice',
        ], $cookies);

        self::assertSame(200, $sent->status);
        self::assertStringContainsString('email', $sent->body);

        $mails = $this->mails($server);
        self::assertCount(1, $mails);

        $message = (string) file_get_contents($mails[0]);
        self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', $message, $match));
        $token = $match[1];
        self::assertStringContainsString('@alice', $message);

        $resetPage = $server->send('GET', '/reset-password', [
            'query' => ['token' => $token],
            'cookies' => $cookies,
        ]);
        self::assertSame(200, $resetPage->status);

        $resetCsrf = $server->csrfFrom($resetPage->body);
        self::assertNotNull($resetCsrf);

        $reset = $server->form('/reset-password', [
            '_csrf' => $resetCsrf,
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirm' => 'brand-new-password',
        ], $cookies);

        self::assertSame(303, $reset->status);
        self::assertSame('/login?reset=1', $reset->header('Location'));

        $oldSession = $server->send('GET', '/', ['cookies' => $signedIn['cookies']]);
        self::assertSame(303, $oldSession->status);
        self::assertSame('/login', $oldSession->header('Location'));

        self::assertSame(303, $server->login('alice', 'brand-new-password')['response']->status);
        self::assertSame(401, $server->login('alice', TestServer::PASSWORD)['response']->status);

        $reused = $server->send('GET', '/reset-password', [
            'query' => ['token' => $token],
            'cookies' => $cookies,
        ]);
        self::assertSame(400, $reused->status);
    }

    public function testUnknownIdentifierRevealsNothingAndSendsNoMail(): void
    {
        $server = new TestServer();

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $response = $server->form('/forgot-password', [
            '_csrf' => $csrf,
            'identifier' => 'nobody@example.test',
        ], $cookies);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('If an account', $response->body);
        self::assertCount(0, $this->mails($server));
    }

    public function testExpiredTokenIsRefusedWithoutTouchingThePassword(): void
    {
        $server = new TestServer();
        $server->createAccount();

        $token = 'expired-token-for-the-test';
        $server->runtime->database->execute(
            'INSERT INTO password_reset (token_hash, account_id, created_at, expires_at, used_at)'
            . ' VALUES (:hash, :account, :created, :expires, NULL)',
            [
                'hash' => hash('sha256', $token),
                'account' => 'alice',
                'created' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200),
                'expires' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            ],
        );

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $expiredPage = $server->send('GET', '/reset-password', [
            'query' => ['token' => $token],
            'cookies' => $cookies,
        ]);
        self::assertSame(400, $expiredPage->status);

        $response = $server->form('/reset-password', [
            '_csrf' => $csrf,
            'token' => $token,
            'password' => 'should-not-be-set',
            'password_confirm' => 'should-not-be-set',
        ], $cookies);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('expired', $response->body);
        self::assertSame(303, $server->login('alice', TestServer::PASSWORD)['response']->status);
    }

    public function testRepeatedRequestsReuseOneValidTokenPerAccount(): void
    {
        $server = new TestServer();
        $server->createAccount();

        $form = $server->send('GET', '/forgot-password');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        foreach ([1, 2] as $attempt) {
            $response = $server->form('/forgot-password', [
                '_csrf' => $csrf,
                'identifier' => 'alice',
            ], $cookies);
            self::assertSame(200, $response->status);
        }

        $mails = $this->mails($server);
        self::assertCount(2, $mails);

        $tokens = [];
        foreach ($mails as $mail) {
            self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', (string) file_get_contents($mail), $match));
            $tokens[] = $match[1];
        }

        self::assertNotSame($tokens[0], $tokens[1]);

        // Only the newest link works; the older row was invalidated.
        $older = $server->send('GET', '/reset-password', [
            'query' => ['token' => $tokens[0]],
            'cookies' => $cookies,
        ]);
        $newer = $server->send('GET', '/reset-password', [
            'query' => ['token' => $tokens[1]],
            'cookies' => $cookies,
        ]);

        // Mail files of the same second have no guaranteed order, so assert
        // that exactly one of the two links survived the second request.
        self::assertSame(1, count(array_filter([$older->status, $newer->status], static fn (int $status): bool => $status === 200)));
        self::assertSame(1, count(array_filter([$older->status, $newer->status], static fn (int $status): bool => $status === 400)));
    }

    /**
     * @return list<string>
     */
    private function mails(TestServer $server): array
    {
        $files = glob($server->config->mailLogDirectory . '/*.eml');

        return is_array($files) ? array_values($files) : [];
    }
}
