<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Security\OpenSslSecretBox;
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
final class FakeBlueskyHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, body: string}> */
    public array $requests = [];

    public int $expiresIn = 3600;

    public bool $rejectCredentials = false;

    public bool $rejectRefresh = false;

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 10,
    ): HttpResponse {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

        if (str_contains($url, 'com.atproto.identity.resolveHandle')) {
            return new HttpResponse(200, [], json_encode(['did' => 'did:plc:alice123'], JSON_THROW_ON_ERROR));
        }

        if (str_starts_with($url, 'https://plc.directory/')) {
            return new HttpResponse(200, [], json_encode([
                'id' => 'did:plc:alice123',
                'service' => [[
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://pds.example.test',
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

        throw new HttpClientException('Unexpected URL: ' . $url);
    }

    /**
     * @return array<string, mixed>
     */
    public function session(string $access, string $refresh): array
    {
        return [
            'did' => 'did:plc:alice123',
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
        $this->server = new TestServer();
        $this->server->createAccount('alice', 'alice@example.test');
        $this->server->createAccount('bob', 'bob@example.test');

        $this->http = new FakeBlueskyHttpClient();
        $this->bluesky = new BlueskyAccounts(
            $this->server->runtime->database,
            $this->http,
            OpenSslSecretBox::fromBase64Key(OpenSslSecretBox::generateKey()),
            $this->server->runtime->clock,
        );
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
