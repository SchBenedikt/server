<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class EmailVerificationTest extends TestCase
{
    private function server(): TestServer
    {
        return new TestServer([
            'registrations' => true,
            'registration_approval' => false,
            'registration_email_verify' => true,
        ]);
    }

    public function testRegistrationRequiresEmailVerificationBeforeLogin(): void
    {
        $server = $this->server();

        $form = $server->send('GET', '/signup');
        $cookies = $server->cookiesFrom($form);
        $csrf = $server->csrfFrom($form->body);
        self::assertNotNull($csrf);

        $response = $server->form('/signup', [
            '_csrf' => $csrf,
            'handle' => 'newbie',
            'email' => 'newbie@example.test',
            'display_name' => '',
            'password' => 'correct-horse',
            'password_confirm' => 'correct-horse',
        ], $cookies);
        self::assertSame(303, $response->status);
        self::assertSame('/login?verify=1', $response->header('Location'));

        // Disabled until the mailed link is consumed; no session can start.
        self::assertNotSame(303, $server->login('newbie', 'correct-horse')['response']->status);

        $server->runtime->mailOutbox->dispatch(10);
        $mails = glob($server->config->mailLogDirectory . '/*.eml');
        self::assertCount(1, $mails);
        $message = (string) file_get_contents($mails[0]);
        self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', $message, $match));
        $token = $match[1];

        // The stored row holds only the hash, never the plain token.
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM email_verification WHERE token_hash = :hash',
            ['hash' => $token],
        ));

        $verified = $server->send('GET', '/verify-email', [
            'query' => ['token' => $token],
        ]);
        self::assertSame(200, $verified->status);
        self::assertStringContainsString('Email verified', $verified->body);

        self::assertSame(303, $server->login('newbie', 'correct-horse')['response']->status);

        // Single use: the same link cannot be replayed.
        $replay = $server->send('GET', '/verify-email', [
            'query' => ['token' => $token],
        ]);
        self::assertSame(400, $replay->status);
    }

    public function testVerificationWithApprovalKeepsAccountDisabled(): void
    {
        $server = new TestServer([
            'registrations' => true,
            'registration_approval' => true,
            'registration_email_verify' => true,
        ]);

        $account = $server->runtime->registration->register(
            'pending',
            'pending@example.test',
            'correct-horse',
        );
        self::assertTrue($account->isDisabled());

        $server->runtime->mailOutbox->dispatch(10);
        $mails = glob($server->config->mailLogDirectory . '/*.eml');
        self::assertCount(1, $mails);
        self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', (string) file_get_contents($mails[0]), $match));

        self::assertTrue($server->runtime->emailVerification->verify($match[1], false));
        $verified = $server->runtime->accounts->find('pending');
        self::assertNotNull($verified);
        self::assertNotNull($verified->emailVerifiedAt);
        self::assertTrue($verified->isDisabled(), 'Approval must still gate sign-in after verification.');
    }

    public function testResendIsGenericAndIssuesFreshLink(): void
    {
        $server = $this->server();
        $server->runtime->registration->register('newbie', 'newbie@example.test', 'correct-horse');

        $before = (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM email_verification');
        self::assertSame(1, $before);

        $server->runtime->emailVerification->resend('newbie');
        self::assertSame(1, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM email_verification'));

        // Unknown identifiers change nothing and reveal nothing.
        $server->runtime->emailVerification->resend('nobody');
        self::assertSame(1, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM email_verification'));
    }
}
