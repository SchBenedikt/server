<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Federation\BlueskyAccounts;
use AlohaSocial\Server\Federation\BlueskyException;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * A fake PDS and public AppView for the Bluesky handshake.
 *
 * The fake answers by URL so the test exercises the real service code,
 * including handle resolution, PDS discovery, session creation, token
 * sealing and refresh, without a network.
 */
final class FakeBlueskyHttpClient implements BoundedHttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    public int $expiresIn = 3600;

    public bool $rejectCredentials = false;

    public bool $rejectRefresh = false;

    public string $pdsEndpoint = 'https://pds.example.test';

    public string $sessionDid = 'did:plc:alice123';

    /** @var array<string, array{cid: string, value: array<string, mixed>}> */
    public array $records = [];

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 10,
    ): HttpResponse {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if (str_contains($url, 'com.atproto.identity.resolveHandle')) {
            return new HttpResponse(200, [], json_encode(['did' => 'did:plc:alice123'], JSON_THROW_ON_ERROR));
        }

        if (str_starts_with($url, 'https://plc.directory/')) {
            return new HttpResponse(200, [], json_encode([
                'id' => 'did:plc:alice123',
                'service' => [[
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => $this->pdsEndpoint,
                ]],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'com.atproto.server.createSession')) {
            if ($this->rejectCredentials) {
                return new HttpResponse(401, [], json_encode(['message' => 'Invalid identifier or password'], JSON_THROW_ON_ERROR));
            }
            $decoded = json_decode($body, true);
            if (($decoded['identifier'] ?? null) !== 'alice.bsky.social' || ($decoded['password'] ?? null) !== 'abcd-efgh-ijkl-mnop') {
                return new HttpResponse(401, [], json_encode(['message' => 'Invalid identifier or password'], JSON_THROW_ON_ERROR));
            }

            return new HttpResponse(200, [], json_encode($this->session('access-1', 'refresh-1'), JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'com.atproto.server.refreshSession')) {
            if ($this->rejectRefresh) {
                return new HttpResponse(401, [], '{"message":"Expired"}');
            }

            return new HttpResponse(200, [], json_encode($this->session('access-2', 'refresh-2'), JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'com.atproto.repo.putRecord')) {
            $record = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            $uri = 'at://' . $record['repo'] . '/' . $record['collection'] . '/' . $record['rkey'];
            $cid = 'bafybeigdyrzt5sfp7udm7hu76uh72fbac7e4j4z3xq';
            $this->records[$uri] = ['cid' => $cid, 'value' => $record['record']];

            return new HttpResponse(200, [], json_encode([
                'uri' => $uri,
                'cid' => $cid,
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'com.atproto.repo.getRecord')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $uri = is_string($query['uri'] ?? null) ? $query['uri'] : '';
            $record = $this->records[$uri] ?? null;
            return $record === null
                ? new HttpResponse(404, [], '{}')
                : new HttpResponse(200, [], json_encode([
                    'uri' => $uri,
                    'cid' => $record['cid'],
                    'value' => $record['value'],
                ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'com.atproto.repo.deleteRecord')) {
            $record = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            unset($this->records['at://' . $record['repo'] . '/' . $record['collection'] . '/' . $record['rkey']]);
            return new HttpResponse(200, [], '{}');
        }

        throw new HttpClientException('Unexpected URL: ' . $url);
    }

    public function requestBounded(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 10,
        int $maxBytes = 1_048_576,
        array $resolvedIps = [],
    ): HttpResponse {
        $response = $this->request($method, $url, $headers, $body, $timeoutSeconds);
        if (strlen($response->body) > $maxBytes) {
            throw new ResponseLimitExceededException('The fake response exceeded the test limit.');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function session(string $access, string $refresh): array
    {
        return [
            'did' => $this->sessionDid,
            'handle' => 'alice.bsky.social',
            'accessJwt' => $this->jwt($access, time() + $this->expiresIn),
            'refreshJwt' => $this->jwt($refresh, time() + 86400),
        ];
    }

    private function jwt(string $label, int $expiresAt): string
    {
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(
            json_encode($value, JSON_THROW_ON_ERROR),
        ), '+/', '-_'), '=');

        return $encode(['alg' => 'none']) . '.' . $encode(['sub' => $label, 'exp' => $expiresAt]) . '.signature';
    }
}

final class BlueskyAccountTest extends TestCase
{
    private TestServer $server;

    private FakeBlueskyHttpClient $http;

    private BlueskyAccounts $bluesky;

    protected function setUp(): void
    {
        $this->http = new FakeBlueskyHttpClient();
        $this->server = new TestServer([], $this->http, new class implements HostResolver {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'public.api.bsky.app' => ['8.8.8.8'],
                    'plc.directory' => ['1.1.1.1'],
                    'pds.example.test' => ['9.9.9.9'],
                    default => [],
                };
            }
        });
        $this->server->createAccount('alice', 'alice@example.test');
        $this->server->createAccount('bob', 'bob@example.test');

        $this->bluesky = $this->server->runtime->blueskyAccounts;
    }

    public function testConnectDiscoversThePdsAndKeepsTokensSealed(): void
    {
        $status = $this->bluesky->connect('alice', '@Alice.BSky.Social', 'abcd-efgh-ijkl-mnop');

        self::assertTrue($status['connected']);
        self::assertSame('did:plc:alice123', $status['did']);
        self::assertSame('alice.bsky.social', $status['handle']);
        self::assertSame('https://pds.example.test', $status['pds_url']);

        $row = $this->server->runtime->database->fetchOne(
            'SELECT * FROM atproto_account WHERE account_id = :id',
            ['id' => 'alice'],
        );
        self::assertNotNull($row);
        self::assertStringNotContainsString('access-1', (string) $row['access_token']);
        self::assertStringNotContainsString('refresh-1', (string) $row['refresh_token']);

        $account = $this->bluesky->resolve('alice');
        self::assertNotNull($account);
        self::assertSame('did:plc:alice123', $account->did);
        self::assertSame('https://pds.example.test', $account->pdsUrl);
        self::assertSame(
            $this->http->session('access-1', 'refresh-1')['accessJwt'],
            $account->accessToken,
        );
    }

    public function testAnHttpPdsEndpointIsRejectedBeforeCredentialsAreSent(): void
    {
        $this->http->pdsEndpoint = 'http://pds.example.test';

        $this->expectException(BlueskyException::class);
        $this->expectExceptionMessage('does not specify a PDS');
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
    }

    public function testASessionForAnotherDidIsRejected(): void
    {
        $this->http->sessionDid = 'did:plc:someoneelse';

        $this->expectException(BlueskyException::class);
        $this->expectExceptionMessage('different DID');
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
    }

    public function testAnExpiredAccessTokenIsRefreshed(): void
    {
        $this->http->expiresIn = -10;
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $this->http->expiresIn = 3600;

        $account = $this->bluesky->resolve('alice');

        self::assertNotNull($account);
        self::assertSame($this->http->session('access-2', 'refresh-2')['accessJwt'], $account->accessToken);

        $refreshes = array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'refreshSession'),
        );
        self::assertCount(1, $refreshes);

        $row = $this->server->runtime->database->fetchOne(
            'SELECT * FROM atproto_account WHERE account_id = :id',
            ['id' => 'alice'],
        );
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['active']);
    }

    public function testAFailedRefreshDeactivatesTheConnection(): void
    {
        $this->http->expiresIn = -10;
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $this->http->rejectRefresh = true;

        self::assertNull($this->bluesky->resolve('alice'));

        $row = $this->server->runtime->database->fetchOne(
            'SELECT * FROM atproto_account WHERE account_id = :id',
            ['id' => 'alice'],
        );
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['active']);
        self::assertFalse($this->bluesky->status('alice')['connected']);
    }

    public function testDisconnectRemovesTheConnection(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');

        self::assertTrue($this->bluesky->disconnect('alice'));
        self::assertFalse($this->bluesky->disconnect('alice'));
        self::assertNull($this->bluesky->resolve('alice'));
        self::assertSame(['connected' => false], $this->bluesky->status('alice'));
    }

    public function testExternalPdsPublishesWithoutTheOptionalNativePds(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $post = $this->server->runtime->app->posts->create(new CreatePost('alice', 'published from standalone'));
        $auth = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/host/bluesky/publish',
            ['post_id' => $post->id],
            $auth['cookies'],
            $auth['csrf'],
        );

        self::assertSame(201, $response->status, $response->body);
        $request = array_values(array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.putRecord'),
        ))[0] ?? null;
        self::assertNotNull($request, 'The connected account must write through its discovered external PDS.');
        self::assertSame('https://pds.example.test/xrpc/com.atproto.repo.putRecord', $request['url']);
        $authorization = $request['headers']['Authorization'] ?? '';
        self::assertStringStartsWith('Bearer ', $authorization);
        $jwtParts = explode('.', substr($authorization, strlen('Bearer ')));
        self::assertCount(3, $jwtParts);
        $claims = json_decode(base64_decode(strtr($jwtParts[1], '-_', '+/')), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('access-1', $claims['sub'] ?? null);
        $payload = json_decode($request['body'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('did:plc:alice123', $payload['repo']);
        self::assertSame('published from standalone', $payload['record']['text']);
    }

    public function testBlueskyFollowActionsPersistAndDeleteThePdsRecord(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $auth = $this->server->signIn('alice');

        $follow = $this->server->json(
            'POST',
            '/api/v1/host/bluesky/follows',
            ['subject_did' => 'did:plc:bob123'],
            $auth['cookies'],
            $auth['csrf'],
        );
        self::assertSame(201, $follow->status, $follow->body);
        $result = json_decode($follow->body, true, 64, JSON_THROW_ON_ERROR)['follow'];
        self::assertSame('active', $result['state']);
        self::assertSame('did:plc:bob123', $result['did']);
        self::assertArrayHasKey($result['uri'], $this->http->records);

        $status = $this->server->send('GET', '/api/v1/host/bluesky/follows', [
            'cookies' => $auth['cookies'],
            'query' => ['did' => 'did:plc:bob123'],
        ]);
        self::assertSame(200, $status->status, $status->body);
        self::assertSame($result, json_decode($status->body, true, 64, JSON_THROW_ON_ERROR)['follow']);

        $unfollow = $this->server->json(
            'DELETE',
            '/api/v1/host/bluesky/follows',
            ['subject_did' => 'did:plc:bob123'],
            $auth['cookies'],
            $auth['csrf'],
        );
        self::assertSame(200, $unfollow->status, $unfollow->body);
        self::assertArrayNotHasKey($result['uri'], $this->http->records);
    }

    public function testInvalidInputsAndCredentialsAreRejected(): void
    {
        try {
            $this->bluesky->connect('alice', 'not a handle', 'abcd');
            self::fail('An invalid handle must be rejected.');
        } catch (BlueskyException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame('bluesky_handle_invalid', $exception->errorCode);
        }

        try {
            $this->bluesky->connect('alice', 'alice.bsky.social', '');
            self::fail('A missing app password must be rejected.');
        } catch (BlueskyException $exception) {
            self::assertSame('bluesky_password_missing', $exception->errorCode);
        }

        $this->http->rejectCredentials = true;
        try {
            $this->bluesky->connect('alice', 'alice.bsky.social', 'wrong-password');
            self::fail('Wrong credentials must be rejected.');
        } catch (BlueskyException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame('bluesky_credentials_invalid', $exception->errorCode);
        }

        self::assertNull($this->bluesky->resolve('alice'));
    }

    public function testTheSameBlueskyAccountCannotBeLinkedTwice(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');

        try {
            $this->bluesky->connect('bob', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
            self::fail('A DID must not be linked to two local accounts.');
        } catch (BlueskyException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame('bluesky_already_connected', $exception->errorCode);
        }
    }
}
