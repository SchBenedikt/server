<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class EmailChangeTest extends TestCase
{
    private function server(): TestServer
    {
        return new TestServer();
    }

    /**
     * @return list<string> raw .eml contents, oldest first
     */
    private function mails(TestServer $server): array
    {
        $files = glob($server->config->mailLogDirectory . '/*.eml') ?: [];
        sort($files);

        return array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            $files,
        );
    }

    private function changeToken(TestServer $server): string
    {
        $server->runtime->mailOutbox->dispatch(25);
        foreach ($this->mails($server) as $message) {
            if (str_contains($message, 'verify-email-change?token=')) {
                self::assertSame(1, preg_match('/token=([A-Za-z0-9_-]+)/', $message, $match));

                return $match[1];
            }
        }
        self::fail('No email change confirmation mail was sent.');
    }

    public function testConfirmedChangeSwitchesAddressAndMailsBothSides(): void
    {
        $server = $this->server();
        $server->createAccount();
        $auth = $server->signIn();

        $response = $server->json('POST', '/api/v1/host/email-change', [
            'email' => 'Alice.New@Example.Test',
            'password' => TestServer::PASSWORD,
        ], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $response->status, $response->body);
        self::assertSame(
            'alice.new@example.test',
            json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['pending_email'],
        );

        // The verified address is untouched until the link is consumed.
        self::assertSame('alice@example.test', $server->runtime->accounts->find('alice')?->email);
        // An unconfirmed address must not be usable for recovery.
        self::assertFalse($server->runtime->passwordReset->request('alice.new@example.test'));

        $token = $this->changeToken($server);
        $mails = $this->mails($server);
        self::assertCount(2, $mails);
        self::assertStringContainsString('alice.new@example.test', $mails[0] . $mails[1]);

        $confirm = $server->send('GET', '/verify-email-change', ['query' => ['token' => $token]]);
        self::assertSame(200, $confirm->status, $confirm->body);

        $account = $server->runtime->accounts->find('alice');
        self::assertSame('alice.new@example.test', $account?->email);
        self::assertNotNull($account?->emailVerifiedAt);
        self::assertNull($server->runtime->database->fetchValue(
            'SELECT pending_email FROM account WHERE id = :id',
            ['id' => 'alice'],
        ));

        // The token is single-use.
        self::assertSame(400, $server->send('GET', '/verify-email-change', ['query' => ['token' => $token]])->status);

        // Recovery now targets the new address only.
        self::assertTrue($server->runtime->passwordReset->request('alice.new@example.test'));
        self::assertFalse($server->runtime->passwordReset->request('alice@example.test'));
    }

    public function testRequestRequiresTheAccountPassword(): void
    {
        $server = $this->server();
        $server->createAccount();
        $auth = $server->signIn();

        $response = $server->json('POST', '/api/v1/host/email-change', [
            'email' => 'alice.new@example.test',
            'password' => 'wrong-password',
        ], $auth['cookies'], $auth['csrf']);
        self::assertSame(403, $response->status, $response->body);
        self::assertSame('invalid_password', json_decode($response->body, true)['error']['code']);

        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM email_change',
        ));
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM mail_outbox',
        ));
    }

    public function testTakenAndUnchangedAddressesAreRefused(): void
    {
        $server = $this->server();
        $server->createAccount('alice');
        $server->createAccount('bob', 'bob@example.test');
        $auth = $server->signIn('alice');

        $taken = $server->json('POST', '/api/v1/host/email-change', [
            'email' => 'bob@example.test',
            'password' => TestServer::PASSWORD,
        ], $auth['cookies'], $auth['csrf']);
        self::assertSame(409, $taken->status, $taken->body);

        $same = $server->json('POST', '/api/v1/host/email-change', [
            'email' => 'alice@example.test',
            'password' => TestServer::PASSWORD,
        ], $auth['cookies'], $auth['csrf']);
        self::assertSame(422, $same->status, $same->body);
        self::assertSame('email_unchanged', json_decode($same->body, true)['error']['code']);
    }

    public function testAnewerRequestSupersedesTheEarlierLink(): void
    {
        $server = $this->server();
        $server->createAccount();
        $auth = $server->signIn();

        foreach (['first@example.test', 'second@example.test'] as $email) {
            $response = $server->json('POST', '/api/v1/host/email-change', [
                'email' => $email,
                'password' => TestServer::PASSWORD,
            ], $auth['cookies'], $auth['csrf']);
            self::assertSame(200, $response->status, $response->body);
        }

        $server->runtime->mailOutbox->dispatch(25);
        // The first request's mails were cancelled before the second was
        // queued, so only the newest pair is delivered.
        self::assertCount(2, $this->mails($server));

        $tokens = [];
        foreach ($this->mails($server) as $message) {
            if (str_contains($message, 'verify-email-change?token=')) {
                preg_match('/token=([A-Za-z0-9_-]+)/', $message, $match);
                $tokens[] = $match[1];
            }
        }
        self::assertCount(1, $tokens);

        // Only one token row exists; it points at the second address.
        $rows = $server->runtime->database->fetchAll('SELECT new_email FROM email_change');
        self::assertCount(1, $rows);
        self::assertSame('second@example.test', $rows[0]['new_email']);

        self::assertSame(200, $server->send('GET', '/verify-email-change', ['query' => ['token' => $tokens[0]]])->status);
        self::assertSame('second@example.test', $server->runtime->accounts->find('alice')?->email);
    }

    public function testPasswordResetCancelsThePendingChange(): void
    {
        $server = $this->server();
        $server->createAccount();
        $auth = $server->signIn();

        $response = $server->json('POST', '/api/v1/host/email-change', [
            'email' => 'alice.new@example.test',
            'password' => TestServer::PASSWORD,
        ], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $response->status, $response->body);

        // A credential reset invalidates the candidate reserved under the
        // old password.
        self::assertTrue($server->runtime->passwordReset->request('alice'));
        $server->runtime->mailOutbox->dispatch(25);
        $resetToken = null;
        foreach ($this->mails($server) as $message) {
            if (str_contains($message, 'reset-password?token=')) {
                preg_match('/token=([A-Za-z0-9_-]+)/', $message, $match);
                $resetToken = $match[1];
            }
        }
        self::assertNotNull($resetToken);
        $server->runtime->passwordReset->reset($resetToken, 'a-brand-new-password-1');

        self::assertNull($server->runtime->database->fetchValue(
            'SELECT pending_email FROM account WHERE id = :id',
            ['id' => 'alice'],
        ));
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM email_change',
        ));
    }

    public function testUnauthenticatedRequestsAreRefused(): void
    {
        $server = $this->server();
        $server->createAccount();

        $response = $server->json('POST', '/api/v1/host/email-change', [
            'email' => 'alice.new@example.test',
            'password' => TestServer::PASSWORD,
        ]);
        self::assertSame(401, $response->status, $response->body);
    }
}
