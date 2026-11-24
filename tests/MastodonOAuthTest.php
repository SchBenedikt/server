<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class MastodonOAuthTest extends TestCase
{
    private const REDIRECT = 'https://client.example/callback';

    public function testPkceAuthorizationIssuesScopedRotatingAndRevocableBearerTokens(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount();
        $session = $server->signIn();
        $app = $this->registerApp($server);
        $verifier = str_repeat('a', 43);
        $challenge = self::challenge($verifier);
        $state = 'csrf-resistant-state';
        $authorizeQuery = [
            'client_id' => $app['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'read',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $page = $server->send('GET', '/oauth/authorize', ['query' => $authorizeQuery, 'cookies' => $session['cookies']]);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Authorize Test client?', $page->body);
        $post = $server->form('/oauth/authorize', $authorizeQuery + [
            '_csrf' => $session['csrf'],
            'decision' => 'approve',
        ], $session['cookies']);
        self::assertSame(303, $post->status);
        self::assertSame(self::REDIRECT, parse_url((string) $post->header('Location'), PHP_URL_SCHEME) . '://'
            . parse_url((string) $post->header('Location'), PHP_URL_HOST) . parse_url((string) $post->header('Location'), PHP_URL_PATH));
        parse_str((string) parse_url((string) $post->header('Location'), PHP_URL_QUERY), $redirectParams);
        self::assertSame($state, $redirectParams['state']);
        $code = $redirectParams['code'];
        $invalidVerifier = $server->form('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => str_repeat('c', 43),
        ]);
        self::assertSame(400, $invalidVerifier->status);

        $tokenResponse = $server->form('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ]);
        self::assertSame(200, $tokenResponse->status, $tokenResponse->body);
        $tokens = json_decode($tokenResponse->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame('read', $tokens['scope']);
        self::assertNotSame('', $tokens['refresh_token']);
        self::assertSame(2_592_000, $tokens['expires_in']);
        $stored = $server->runtime->database->fetchOne(
            'SELECT access_hash, refresh_hash FROM mastodon_oauth_token WHERE access_hash = :hash',
            ['hash' => hash('sha256', $tokens['access_token'])],
        );
        self::assertSame(hash('sha256', $tokens['access_token']), $stored['access_hash']);
        self::assertSame(hash('sha256', $tokens['refresh_token']), $stored['refresh_hash']);
        $codeReplay = $server->form('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ]);
        self::assertSame(400, $codeReplay->status);

        $verified = $server->send('GET', '/api/mastodon/v1/accounts/verify_credentials', [
            'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
        ]);
        self::assertSame(200, $verified->status, $verified->body);
        $home = $server->send('GET', '/api/v1/timelines/home', [
            'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
        ]);
        self::assertSame(200, $home->status, $home->body);
        self::assertSame([], json_decode($home->body, true, 32, JSON_THROW_ON_ERROR));
        $nativeHome = $server->send('GET', '/api/v1/timelines/home', ['cookies' => $session['cookies']]);
        self::assertSame(200, $nativeHome->status, $nativeHome->body);
        self::assertArrayHasKey('posts', json_decode($nativeHome->body, true, 32, JSON_THROW_ON_ERROR));
        $write = $server->send('POST', '/api/mastodon/v1/statuses', [
            'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token'], 'Content-Type' => 'application/json'],
            'body' => '{"status":"hello"}',
        ]);
        self::assertSame(403, $write->status);
        self::assertStringContainsString('insufficient_scope', $write->body);
        $nativeWrite = $server->send('POST', '/api/v1/posts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $tokens['access_token'],
                'Content-Type' => 'application/json',
            ],
            'body' => '{"content":"must not be authorized"}',
        ]);
        self::assertSame(401, $nativeWrite->status);

        $refresh = $server->form('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertSame(200, $refresh->status, $refresh->body);
        $rotated = json_decode($refresh->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        $oldAccess = $server->send('GET', '/api/mastodon/v1/accounts/verify_credentials', [
            'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
        ]);
        self::assertSame(401, $oldAccess->status);
        $replay = $server->form('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertSame(400, $replay->status);

        $revoked = $server->form('/oauth/revoke', [
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'token' => $rotated['access_token'],
        ]);
        self::assertSame(200, $revoked->status);
        $rejected = $server->send('GET', '/api/mastodon/v1/accounts/verify_credentials', [
            'headers' => ['Authorization' => 'Bearer ' . $rotated['access_token']],
        ]);
        self::assertSame(401, $rejected->status);

    }

    public function testRegistrationRejectsUnsafeRedirectsUnknownScopesAndMalformedWebsite(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        foreach ([
            ['javascript:alert(1)', 'read', null],
            ['https://client.example/callback#fragment', 'read', null],
            [self::REDIRECT, 'admin', null],
            [self::REDIRECT, 'read', 'http://client.example/'],
        ] as [$redirect, $scopes, $website]) {
            $response = $server->json('POST', '/api/v1/apps', [
                'client_name' => 'Test client',
                'redirect_uris' => $redirect,
                'scopes' => $scopes,
                'website' => $website,
            ]);
            self::assertSame(422, $response->status);
        }
    }

    public function testAuthorizationRequiresExactRegisteredRedirectAndPkce(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount();
        $session = $server->signIn();
        $app = $this->registerApp($server);
        $response = $server->send('GET', '/oauth/authorize', [
            'cookies' => $session['cookies'],
            'query' => [
                'client_id' => $app['client_id'],
                'redirect_uri' => 'https://attacker.example/callback',
                'response_type' => 'code',
                'scope' => 'read',
                'state' => 'state',
                'code_challenge' => self::challenge(str_repeat('b', 43)),
                'code_challenge_method' => 'S256',
            ],
        ]);
        self::assertSame(400, $response->status);

        $missingPkce = $server->send('GET', '/oauth/authorize', [
            'cookies' => $session['cookies'],
            'query' => [
                'client_id' => $app['client_id'],
                'redirect_uri' => self::REDIRECT,
                'response_type' => 'code',
                'scope' => 'read',
                'state' => 'state',
            ],
        ]);
        self::assertSame(400, $missingPkce->status);
    }

    public function testDisablingAnAccountRevokesItsOAuthAccess(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $server->createAccount('alice');
        $session = $server->signIn('alice');
        $app = $this->registerApp($server);
        $verifier = str_repeat('d', 43);
        $params = [
            'client_id' => $app['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'read',
            'state' => 'state',
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ];
        $authorization = $server->form('/oauth/authorize', $params + [
            '_csrf' => $session['csrf'],
            'decision' => 'approve',
        ], $session['cookies']);
        parse_str((string) parse_url((string) $authorization->header('Location'), PHP_URL_QUERY), $redirectParams);
        $token = $server->form('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $redirectParams['code'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ]);
        $accessToken = json_decode($token->body, true, 32, JSON_THROW_ON_ERROR)['access_token'];
        $before = $server->send('GET', '/api/mastodon/v1/accounts/verify_credentials', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        self::assertSame(200, $before->status);

        $pendingVerifier = str_repeat('e', 43);
        $pendingParams = [
            'client_id' => $app['client_id'],
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'read',
            'state' => 'pending-state',
            'code_challenge' => self::challenge($pendingVerifier),
            'code_challenge_method' => 'S256',
        ];
        $pendingAuthorization = $server->form('/oauth/authorize', $pendingParams + [
            '_csrf' => $session['csrf'],
            'decision' => 'approve',
        ], $session['cookies']);
        parse_str((string) parse_url((string) $pendingAuthorization->header('Location'), PHP_URL_QUERY), $pendingRedirect);

        $server->runtime->admin->disableUser('admin', 'alice');
        $lateExchange = $server->form('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $pendingRedirect['code'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $pendingVerifier,
        ]);
        self::assertSame(400, $lateExchange->status);
        $after = $server->send('GET', '/api/mastodon/v1/accounts/verify_credentials', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        self::assertSame(401, $after->status);
    }

    /** @return array{client_id:string,client_secret:string} */
    private function registerApp(TestServer $server): array
    {
        $response = $server->json('POST', '/api/v1/apps', [
            'client_name' => 'Test client',
            'redirect_uris' => self::REDIRECT,
            'scopes' => 'read write:statuses',
            'website' => 'https://client.example/',
        ]);
        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        return ['client_id' => $data['client_id'], 'client_secret' => $data['client_secret']];
    }

    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
