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
 * An ATProto client signs in with the member account, then uses the access
 * token for the core's XRPC routes. The host must issue and rotate both
 * tokens, and must reject a token after deleteSession.
 */
final class AtprotoSessionTest extends TestCase
{
    private TestServer $server;

    protected function setUp(): void
    {
        $this->server = new TestServer([
            'atproto_pds' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);
        $this->server->createAccount('alice', 'alice@example.test');

        $this->server->runtime->database->execute(
            'INSERT INTO social_pds_identity'
            . ' (local_user_id, did, handle, signing_key_id, rotation_key_id, head_cid, head_rev, seq, created_at, updated_at)'
            . " VALUES ('alice', 'did:plc:test', 'alice.social.example', 'sign', 'rotate', NULL, NULL, 0,"
            . " '2026-11-18T10:00:00Z', '2026-11-18T10:00:00Z')",
        );
    }

    public function testCreateSessionIssuesTokensAndGetSessionAuthenticates(): void
    {
        $created = $this->server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice',
            'password' => TestServer::PASSWORD,
        ]);

        self::assertSame(200, $created->status, $created->body);
        $session = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('did:plc:test', $session['did']);
        self::assertSame('alice.social.example', $session['handle']);
        self::assertTrue($session['active']);
        self::assertNotSame('', $session['accessJwt']);
        self::assertNotSame('', $session['refreshJwt']);

        $read = $this->server->send('GET', '/xrpc/com.atproto.server.getSession', [
            'headers' => ['Authorization' => 'Bearer ' . $session['accessJwt']],
        ]);
        self::assertSame(200, $read->status, $read->body);
        $body = json_decode($read->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('did:plc:test', $body['did']);
    }

    public function testRefreshRotatesTheAccessTokenAndDeleteRevokesIt(): void
    {
        $created = $this->server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice@example.test',
            'password' => TestServer::PASSWORD,
        ]);
        $session = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);

        $refreshed = $this->server->send('POST', '/xrpc/com.atproto.server.refreshSession', [
            'headers' => ['Authorization' => 'Bearer ' . $session['refreshJwt']],
        ]);
        self::assertSame(200, $refreshed->status, $refreshed->body);
        $next = json_decode($refreshed->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($session['accessJwt'], $next['accessJwt']);
        self::assertSame('did:plc:test', $next['did']);

        $deleted = $this->server->send('POST', '/xrpc/com.atproto.server.deleteSession', [
            'headers' => ['Authorization' => 'Bearer ' . $next['accessJwt']],
        ]);
        self::assertSame(200, $deleted->status, $deleted->body);

        $after = $this->server->send('GET', '/xrpc/com.atproto.server.getSession', [
            'headers' => ['Authorization' => 'Bearer ' . $next['accessJwt']],
        ]);
        self::assertSame(401, $after->status);

        // Deleting with the access token must also invalidate the refresh
        // token; otherwise the session was not really revoked.
        $refreshAfter = $this->server->send('POST', '/xrpc/com.atproto.server.refreshSession', [
            'headers' => ['Authorization' => 'Bearer ' . $next['refreshJwt']],
        ]);
        self::assertSame(401, $refreshAfter->status);
    }

    public function testCreateSessionWithoutAnIdentityExplainsTheMissingRepository(): void
    {
        $this->server->runtime->database->execute(
            'DELETE FROM social_pds_identity WHERE local_user_id = :id',
            ['id' => 'alice'],
        );

        $response = $this->server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice',
            'password' => TestServer::PASSWORD,
        ]);

        self::assertSame(409, $response->status);
        self::assertStringContainsString('RepoNotFound', $response->body);
    }

    public function testCreateSessionLimitsAttemptsPerClientAddressAcrossIdentifiers(): void
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $response = $this->server->json('POST', '/xrpc/com.atproto.server.createSession', [
                'identifier' => 'unknown-' . $attempt,
                'password' => 'incorrect-password',
            ]);
            self::assertSame(401, $response->status, $response->body);
        }

        $limited = $this->server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice',
            'password' => TestServer::PASSWORD,
        ]);

        self::assertSame(429, $limited->status, $limited->body);
        self::assertStringContainsString('RateLimitExceeded', $limited->body);

        $otherClient = $this->server->send('POST', '/xrpc/com.atproto.server.createSession', [
            'ip' => '198.51.100.24',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'identifier' => 'alice',
                'password' => TestServer::PASSWORD,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(200, $otherClient->status, $otherClient->body);
    }

    public function testAdminDisableRevokesNativePdsSessionPermanently(): void
    {
        $server = new TestServer([
            'atproto_pds' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $server->createAccount('bob', 'bob@example.test');
        $admin = $server->signIn('admin');
        $server->runtime->database->execute(
            'INSERT INTO social_pds_identity'
            . ' (local_user_id, did, handle, signing_key_id, rotation_key_id, head_cid, head_rev, seq, created_at, updated_at)'
            . " VALUES ('bob', 'did:plc:bob', 'bob.social.example', 'sign', 'rotate', NULL, NULL, 0,"
            . " '2026-11-18T10:00:00Z', '2026-11-18T10:00:00Z')",
        );

        $created = $server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'bob',
            'password' => TestServer::PASSWORD,
        ]);
        self::assertSame(200, $created->status, $created->body);
        $accessToken = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR)['accessJwt'];

        $disabled = $server->json(
            'POST',
            '/api/v1/host/admin/users/bob/disable',
            [],
            $admin['cookies'],
            $admin['csrf'],
        );
        self::assertSame(200, $disabled->status, $disabled->body);
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM atproto_session WHERE account_id = :account',
            ['account' => 'bob'],
        ));

        $enabled = $server->json(
            'POST',
            '/api/v1/host/admin/users/bob/enable',
            [],
            $admin['cookies'],
            $admin['csrf'],
        );
        self::assertSame(200, $enabled->status, $enabled->body);
        $afterReactivation = $server->send('GET', '/xrpc/com.atproto.server.getSession', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        self::assertSame(401, $afterReactivation->status, $afterReactivation->body);
    }
}
