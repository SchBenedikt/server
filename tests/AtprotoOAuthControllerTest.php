<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Server\Tests\Support\TestServer;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthException;
use AlohaSocial\Server\Federation\BlueskyException;
use PHPUnit\Framework\TestCase;

final class FakeOAuthDiscoveryClient implements BoundedHttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];
    public string $state = '';

    /** @var array<string, string> */
    public array $tokenForm = [];

    public string $tokenDpopProof = '';

    public bool $pdsUnavailable = false;

    public function request(string $method, string $url, array $headers = [], string $body = '', int $timeoutSeconds = 10): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if (str_contains($url, 'com.atproto.identity.resolveHandle')) return new HttpResponse(200, [], '{"did":"did:plc:alice123"}');
        if (str_starts_with($url, 'https://plc.directory/')) return new HttpResponse(200, [], '{"service":[{"id":"#atproto_pds","type":"AtprotoPersonalDataServer","serviceEndpoint":"https://pds.example.test"}]}');
        if ($url === 'https://pds.example.test/.well-known/oauth-protected-resource') {
            return $this->pdsUnavailable
                ? new HttpResponse(503, [], '{}')
                : new HttpResponse(200, [], '{"authorization_servers":["https://auth.example.test"]}');
        }
        if ($url === 'https://auth.example.test/.well-known/oauth-authorization-server') return new HttpResponse(200, [], json_encode([
            'issuer' => 'https://auth.example.test', 'authorization_endpoint' => 'https://auth.example.test/authorize',
            'token_endpoint' => 'https://auth.example.test/token', 'pushed_authorization_request_endpoint' => 'https://auth.example.test/par',
            'require_pushed_authorization_requests' => true, 'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => true, 'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'], 'dpop_signing_alg_values_supported' => ['ES256'], 'scopes_supported' => ['atproto'],
        ], JSON_THROW_ON_ERROR));
        if ($url === 'https://auth.example.test/par') {
            parse_str($body, $form);
            $this->state = is_string($form['state'] ?? null) ? $form['state'] : '';
            return new HttpResponse(201, ['dpop-nonce' => 'par-nonce'], '{"request_uri":"urn:ietf:params:oauth:request_uri:abc"}');
        }
        if ($url === 'https://auth.example.test/token') {
            parse_str($body, $this->tokenForm);
            $this->tokenDpopProof = $headers['DPoP'] ?? '';
            if (($this->tokenForm['grant_type'] ?? '') === 'refresh_token') {
                return new HttpResponse(200, ['dpop-nonce' => 'refresh-nonce'], json_encode([
                    'access_token' => 'oauth-refreshed-access', 'refresh_token' => 'oauth-refreshed-refresh',
                    'token_type' => 'DPoP', 'scope' => 'atproto', 'sub' => 'did:plc:alice123', 'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR));
            }
            return new HttpResponse(200, ['dpop-nonce' => 'token-nonce'], json_encode([
                'access_token' => 'oauth-access-token', 'refresh_token' => 'oauth-refresh-token',
                'token_type' => 'DPoP', 'scope' => 'atproto', 'sub' => 'did:plc:alice123', 'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, 'com.atproto.repo.putRecord')) {
            $record = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $uri = 'at://' . $record['repo'] . '/' . $record['collection'] . '/' . $record['rkey'];
            return new HttpResponse(200, [], json_encode(['uri' => $uri, 'cid' => 'bafybeigdyrzt5sfp7udm7hu76uh72fbac7e4j4z3xq'], JSON_THROW_ON_ERROR));
        }
        throw new \RuntimeException('Unexpected OAuth test request: ' . $method . ' ' . $url);
    }

    public function requestBounded(string $method, string $url, array $headers = [], string $body = '', int $timeoutSeconds = 10, int $maxBytes = 1048576, array $resolvedIps = []): HttpResponse
    {
        return $this->request($method, $url, $headers, $body, $timeoutSeconds);
    }
}

final class FakeOAuthHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        return match ($host) {
            'public.api.bsky.app' => ['8.8.8.8'], 'plc.directory' => ['1.1.1.1'],
            'pds.example.test', 'auth.example.test' => ['9.9.9.9'], default => [],
        };
    }
}

final class AtprotoOAuthControllerTest extends TestCase
{
    public function testPublishesValidPublicClientMetadata(): void
    {
        $server = new TestServer();
        $response = $server->send('GET', '/oauth/atproto/client-metadata.json');
        $metadata = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status);
        self::assertSame('http://localhost:3000/oauth/atproto/client-metadata.json', $metadata['client_id']);
        self::assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types']);
        self::assertSame(['http://localhost:3000/oauth/atproto/callback'], $metadata['redirect_uris']);
        self::assertSame('atproto', $metadata['scope']);
        self::assertTrue($metadata['dpop_bound_access_tokens']);
    }

    public function testStoresOAuthCredentialsSealedAndResolvesAccountWithDpop(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $session = new AtprotoOAuthSession(
            'did:plc:alice123', 'https://auth.example.test/token',
            'http://localhost:3000/oauth/atproto/client-metadata.json', 'oauth-access',
            'oauth-refresh', 'atproto', new \DateTimeImmutable('+1 hour'),
        );

        $status = $server->runtime->blueskyAccounts->storeOAuth(
            'alice', 'alice.bsky.social', 'https://pds.example.test',
            'https://auth.example.test', $session, $pem,
        );
        $account = $server->runtime->blueskyAccounts->resolve('alice');

        self::assertSame('oauth', $status['auth_method']);
        self::assertNotNull($account);
        self::assertNotNull($account->dpop);
        $row = $server->runtime->database->fetchOne('SELECT * FROM atproto_account WHERE account_id = :id', ['id' => 'alice']);
        self::assertStringNotContainsString('oauth-access', (string) $row['access_token']);
        $oauth = $server->runtime->database->fetchOne('SELECT * FROM atproto_oauth_account WHERE account_id = :id', ['id' => 'alice']);
        self::assertStringNotContainsString('BEGIN EC PRIVATE KEY', (string) $oauth['dpop_private_key']);
    }

    public function testStartsSessionBoundParFlowWithEncryptedVerifier(): void
    {
        $server = new TestServer([], new FakeOAuthDiscoveryClient(), new FakeOAuthHostResolver());
        $server->createAccount('alice');
        $url = $server->runtime->atprotoOAuth->begin('alice', str_repeat('a', 64), 'alice.bsky.social');
        $flow = $server->runtime->database->fetchOne('SELECT * FROM atproto_oauth_flow');

        self::assertStringStartsWith('https://auth.example.test/authorize?', $url);
        self::assertStringContainsString('request_uri=', $url);
        self::assertNotNull($flow);
        self::assertSame('alice', $flow['account_id']);
        self::assertSame(str_repeat('a', 64), $flow['browser_session_hash']);
        self::assertStringNotContainsString('code_verifier', (string) $flow['payload']);
        self::assertStringNotContainsString('BEGIN EC PRIVATE KEY', (string) $flow['payload']);
    }

    public function testCallbackExchangesCodeAndConsumesSessionBoundStateOnce(): void
    {
        $http = new FakeOAuthDiscoveryClient();
        $browserSession = str_repeat('b', 64);
        $server = new TestServer([], $http, new FakeOAuthHostResolver());
        $server->createAccount('alice');
        $server->runtime->atprotoOAuth->begin('alice', $browserSession, 'alice.bsky.social');

        try {
            $server->runtime->atprotoOAuth->complete('alice', str_repeat('c', 64), $http->state, 'auth-code', 'https://auth.example.test');
            self::fail('A callback from another browser session must be rejected.');
        } catch (BlueskyException $error) {
            self::assertSame('bluesky_oauth_state_invalid', $error->errorCode);
        }

        $server->runtime->atprotoOAuth->complete('alice', $browserSession, $http->state, 'auth-code', 'https://auth.example.test');
        self::assertSame('oauth', $server->runtime->blueskyAccounts->status('alice')['auth_method']);
        self::assertSame('authorization_code', $http->tokenForm['grant_type']);
        self::assertSame('auth-code', $http->tokenForm['code']);
        self::assertNotEmpty($http->tokenForm['code_verifier']);
        self::assertNotEmpty($http->tokenDpopProof);
        self::assertSame(0, $server->runtime->database->execute('DELETE FROM atproto_oauth_flow'));

        try {
            $server->runtime->atprotoOAuth->complete('alice', $browserSession, $http->state, 'auth-code', 'https://auth.example.test');
            self::fail('A consumed callback state must not be reusable.');
        } catch (BlueskyException $error) {
            self::assertSame('bluesky_oauth_state_invalid', $error->errorCode);
        }
    }

    public function testExpiredOAuthAccessTokenIsSafelyRefreshedAndRotated(): void
    {
        $http = new FakeOAuthDiscoveryClient();
        $server = new TestServer([], $http, new FakeOAuthHostResolver());
        $server->createAccount('alice');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $session = new AtprotoOAuthSession(
            'did:plc:alice123', 'https://auth.example.test/token',
            'http://localhost:3000/oauth/atproto/client-metadata.json', 'old-access',
            'old-refresh', 'atproto', new \DateTimeImmutable('-1 hour'),
        );
        $server->runtime->blueskyAccounts->storeOAuth('alice', 'alice.bsky.social', 'https://pds.example.test', 'https://auth.example.test', $session, $pem);

        $account = $server->runtime->blueskyAccounts->resolve('alice');
        $stored = $server->runtime->database->fetchOne('SELECT * FROM atproto_account WHERE account_id = :id', ['id' => 'alice']);

        self::assertNotNull($account);
        self::assertSame('oauth-refreshed-access', $account->accessToken);
        self::assertSame('refresh_token', $http->tokenForm['grant_type']);
        self::assertNotEmpty($http->tokenDpopProof);
        self::assertSame('oauth', $server->runtime->blueskyAccounts->status('alice')['auth_method']);
        self::assertStringNotContainsString('oauth-refreshed-refresh', (string) $stored['refresh_token']);
    }

    public function testUnavailablePdsAuthorizationMetadataStopsLinking(): void
    {
        $http = new FakeOAuthDiscoveryClient();
        $http->pdsUnavailable = true;
        $server = new TestServer([], $http, new FakeOAuthHostResolver());
        $server->createAccount('alice');

        try {
            $server->runtime->atprotoOAuth->begin('alice', str_repeat('a', 64), 'alice.bsky.social');
            self::fail('OAuth must not start when the PDS authorization metadata is unavailable.');
        } catch (AtprotoOAuthException) {
            self::assertNull($server->runtime->database->fetchOne('SELECT * FROM atproto_oauth_flow'));
        }
    }

    public function testUnlinkRemovesOAuthTokensAndDpopMetadata(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $session = new AtprotoOAuthSession(
            'did:plc:alice123', 'https://auth.example.test/token',
            'http://localhost:3000/oauth/atproto/client-metadata.json', 'access-to-remove',
            'refresh-to-remove', 'atproto', new \DateTimeImmutable('+1 hour'),
        );
        $server->runtime->blueskyAccounts->storeOAuth('alice', 'alice.bsky.social', 'https://pds.example.test', 'https://auth.example.test', $session, $pem);

        self::assertTrue($server->runtime->blueskyAccounts->disconnect('alice'));
        self::assertFalse($server->runtime->blueskyAccounts->status('alice')['connected']);
        self::assertNull($server->runtime->database->fetchOne('SELECT * FROM atproto_account WHERE account_id = :id', ['id' => 'alice']));
        self::assertNull($server->runtime->database->fetchOne('SELECT * FROM atproto_oauth_account WHERE account_id = :id', ['id' => 'alice']));
    }

    public function testLinkedOAuthAccountPublishesThroughItsPdsWithDpop(): void
    {
        $http = new FakeOAuthDiscoveryClient();
        $server = new TestServer([], $http, new FakeOAuthHostResolver());
        $server->createAccount('alice');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $session = new AtprotoOAuthSession(
            'did:plc:alice123', 'https://auth.example.test/token',
            'http://localhost:3000/oauth/atproto/client-metadata.json', 'oauth-publish-access',
            'oauth-publish-refresh', 'atproto', new \DateTimeImmutable('+1 hour'),
        );
        $server->runtime->blueskyAccounts->storeOAuth('alice', 'alice.bsky.social', 'https://pds.example.test', 'https://auth.example.test', $session, $pem);
        $post = $server->runtime->app->posts->create(new \AlohaSocial\Core\Service\CreatePost('alice', 'published with OAuth'));
        $auth = $server->signIn('alice');

        $response = $server->json('POST', '/api/v1/host/bluesky/publish', ['post_id' => $post->id], $auth['cookies'], $auth['csrf']);
        $publishRequest = array_values(array_filter($http->requests, static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.putRecord')))[0] ?? null;

        self::assertSame(201, $response->status, $response->body);
        self::assertNotNull($publishRequest);
        self::assertNotEmpty($publishRequest['headers']['DPoP'] ?? null);
        self::assertStringContainsString('oauth-publish-access', (string) ($publishRequest['headers']['Authorization'] ?? ''));
        self::assertStringContainsString('published with OAuth', (string) $publishRequest['body']);
        self::assertStringContainsString('"uri":"at://did:plc:alice123/app.bsky.feed.post/', $response->body);
    }
}
