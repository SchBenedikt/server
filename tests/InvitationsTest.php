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

final class InvitationsTest extends TestCase
{
    public function testInvitationBypassesClosedRegistration(): void
    {
        $server = new TestServer(['registrations' => false]);
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $created = $server->runtime->invitations->create('alice', 1, null, null);

        $account = $server->runtime->registration->register(
            'newbie',
            'new@example.test',
            'correct-horse',
            null,
            $created['code'],
        );

        self::assertFalse($account->isDisabled());
        self::assertSame('newbie', $account->handle);
    }

    public function testInvitationBypassesTheApprovalQueue(): void
    {
        $server = new TestServer([
            'registrations' => true,
            'registration_approval' => true,
        ]);
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $created = $server->runtime->invitations->create('alice', 1, null, null);

        $account = $server->runtime->registration->register(
            'newbie',
            'new@example.test',
            'correct-horse',
            null,
            $created['code'],
        );

        self::assertFalse($account->isDisabled());
        self::assertNull($server->runtime->database->fetchValue(
            "SELECT disabled_at FROM account WHERE id = 'newbie'",
        ));
    }

    public function testUnknownAndRevokedInvitationsAreRejected(): void
    {
        $server = new TestServer(['registrations' => true, 'registration_approval' => false]);
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $created = $server->runtime->invitations->create('alice', 5, null, null);

        try {
            $server->runtime->registration->register('one', 'one@example.test', 'correct-horse', null, 'inv_bogus');
            self::fail('An unknown code must be refused.');
        } catch (RegistrationException $exception) {
            self::assertSame(['invite' => 'Check the invitation code and try again.'], $exception->fields);
        }

        self::assertTrue($server->runtime->invitations->revoke($created['invitation']['id']));

        try {
            $server->runtime->registration->register('two', 'two@example.test', 'correct-horse', null, $created['code']);
            self::fail('A revoked code must be refused.');
        } catch (RegistrationException $exception) {
            self::assertArrayHasKey('invite', $exception->fields);
        }
    }

    public function testExpiredAndExhaustedInvitationsAreRejected(): void
    {
        $server = new TestServer(['registrations' => true, 'registration_approval' => false]);
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);

        $expired = $server->runtime->invitations->create('alice', 5, null, null);
        $server->runtime->database->execute(
            "UPDATE account_invitation SET expires_at = '2000-01-01T00:00:00Z' WHERE id = :id",
            ['id' => $expired['invitation']['id']],
        );

        try {
            $server->runtime->registration->register('one', 'one@example.test', 'correct-horse', null, $expired['code']);
            self::fail('An expired code must be refused.');
        } catch (RegistrationException $exception) {
            self::assertArrayHasKey('invite', $exception->fields);
        }

        $single = $server->runtime->invitations->create('alice', 1, null, null);
        $server->runtime->registration->register('two', 'two@example.test', 'correct-horse', null, $single['code']);

        try {
            $server->runtime->registration->register('three', 'three@example.test', 'correct-horse', null, $single['code']);
            self::fail('An exhausted code must be refused.');
        } catch (RegistrationException $exception) {
            self::assertArrayHasKey('invite', $exception->fields);
        }
    }

    public function testAFailedRegistrationDoesNotBurnAUse(): void
    {
        $server = new TestServer(['registrations' => true, 'registration_approval' => false]);
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $server->runtime->registration->register('taken', 'taken@example.test', 'correct-horse');
        $created = $server->runtime->invitations->create('alice', 1, null, null);

        try {
            $server->runtime->registration->register('taken', 'other@example.test', 'correct-horse', null, $created['code']);
            self::fail('The duplicate handle must still be a conflict.');
        } catch (RegistrationException $exception) {
            self::assertSame(409, $exception->status);
        }

        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT uses FROM account_invitation WHERE id = :id',
            ['id' => $created['invitation']['id']],
        ));

        $account = $server->runtime->registration->register(
            'fresh',
            'fresh@example.test',
            'correct-horse',
            null,
            $created['code'],
        );

        self::assertSame('fresh', $account->handle);
        self::assertSame(1, (int) $server->runtime->database->fetchValue(
            'SELECT uses FROM account_invitation WHERE id = :id',
            ['id' => $created['invitation']['id']],
        ));
    }

    public function testOnlyTheHashOfAnInvitationIsStored(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $created = $server->runtime->invitations->create('alice', 1, 30, 'meetup group');

        self::assertStringStartsWith('inv_', $created['code']);
        self::assertStringNotContainsString($created['code'], (string) json_encode(
            $server->runtime->database->fetchAll('SELECT * FROM account_invitation'),
        ));
        self::assertSame(
            hash('sha256', strtolower(trim($created['code']))),
            $server->runtime->database->fetchValue('SELECT code_hash FROM account_invitation'),
        );

        $listed = $server->runtime->invitations->all();
        self::assertCount(1, $listed);
        self::assertSame('active', $listed[0]['state']);
        self::assertSame('inv_' . mb_substr($created['code'], 4, 8), $listed[0]['code_prefix']);
        self::assertSame('alice', $listed[0]['created_by_handle']);
    }

    public function testAdminInvitationEndpoints(): void
    {
        $server = new TestServer(['registrations' => false]);
        $server->createAccount('alice', 'alice@example.test', TestServer::PASSWORD, true);
        $session = $server->signIn('alice');
        $cookies = $session['cookies'];
        $csrf = $session['csrf'];

        $created = $server->json('POST', '/api/v1/host/admin/invitations', [
            'max_uses' => 2,
            'expires_in_days' => 7,
            'note' => 'friends',
        ], $cookies, $csrf);
        self::assertSame(201, $created->status);
        $body = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('inv_', $body['code']);
        self::assertSame(2, $body['invitation']['max_uses']);
        self::assertNotNull($body['invitation']['expires_at']);

        $list = $server->json('GET', '/api/v1/host/admin/invitations', [], $cookies);
        self::assertSame(200, $list->status);
        $entries = json_decode($list->body, true, 512, JSON_THROW_ON_ERROR)['invitations'];
        self::assertCount(1, $entries);
        self::assertSame('active', $entries[0]['state']);
        self::assertSame('friends', $entries[0]['note']);

        $id = $body['invitation']['id'];
        $revoked = $server->json('POST', '/api/v1/host/admin/invitations/' . $id . '/revoke', [], $cookies, $csrf);
        self::assertSame(200, $revoked->status);

        $again = $server->json('POST', '/api/v1/host/admin/invitations/' . $id . '/revoke', [], $cookies, $csrf);
        self::assertSame(422, $again->status);
    }

    public function testAdminInvitationEndpointsRequireAdmin(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $session = $server->signIn('alice');

        $response = $server->send('GET', '/api/v1/host/admin/invitations', ['cookies' => $session['cookies']]);
        self::assertSame(403, $response->status);
    }
}
