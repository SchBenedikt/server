<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccountStatus;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollow;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteRecord;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Federation\BlueskyAccounts;
use AlohaSocial\Server\Federation\BlueskyException;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInboundRepository;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Support\Time;
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

    public int $blobUploadFailures = 0;

    public ?int $recordWriteFailureStatus = null;

    public bool $rejectCredentials = false;

    public bool $rejectRefresh = false;

    public ?int $refreshFailureStatus = null;

    public bool $omitRefreshToken = false;

    public string $pdsEndpoint = 'https://pds.example.test';

    public string $sessionDid = 'did:plc:alice123';

    public string $resolvedDid = 'did:plc:alice123';

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
            return new HttpResponse(200, [], json_encode(['did' => $this->resolvedDid], JSON_THROW_ON_ERROR));
        }

        if (str_starts_with($url, 'https://plc.directory/')) {
            return new HttpResponse(200, [], json_encode([
                'id' => $this->resolvedDid,
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
            if ($this->refreshFailureStatus !== null) {
                return new HttpResponse($this->refreshFailureStatus, [], '{"message":"Temporary failure"}');
            }

            $session = $this->session('access-2', 'refresh-2');
            if ($this->omitRefreshToken) {
                unset($session['refreshJwt']);
            }

            return new HttpResponse(200, [], json_encode($session, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'com.atproto.repo.uploadBlob')) {
            if ($this->blobUploadFailures > 0) {
                $this->blobUploadFailures--;

                return new HttpResponse(503, [], '{"error":"ServiceUnavailable"}');
            }

            return new HttpResponse(200, [], json_encode([
                'blob' => [
                    '$type' => 'blob',
                    'ref' => ['$link' => 'bafy-uploaded-image'],
                    'mimeType' => $headers['Content-Type'] ?? 'application/octet-stream',
                    'size' => strlen($body),
                ],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, 'com.atproto.repo.putRecord')) {
            if ($this->recordWriteFailureStatus !== null) {
                return new HttpResponse($this->recordWriteFailureStatus, [], '{}');
            }

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
	private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

    public function testDisabledHostAccountCannotResolveItsBlueskyIdentity(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        self::assertNotNull($this->bluesky->resolve('alice'));

        $this->server->runtime->accounts->disable('alice');

        self::assertNull($this->bluesky->resolve('alice'));
        self::assertSame(['connected' => false], $this->bluesky->status('alice'));

        $this->server->runtime->accounts->enable('alice');
        self::assertNotNull($this->bluesky->resolve('alice'));
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

    public function testTemporaryRefreshFailuresKeepTheConnectionAvailableForRetry(): void
    {
        $this->http->expiresIn = -10;
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $this->http->expiresIn = 3600;
        $this->http->refreshFailureStatus = 503;

        self::assertNull($this->bluesky->resolve('alice'));
        self::assertTrue($this->bluesky->status('alice')['connected']);
        self::assertSame(1, (int) $this->server->runtime->database->fetchValue(
            'SELECT active FROM atproto_account WHERE account_id = :id',
            ['id' => 'alice'],
        ));

        $this->http->refreshFailureStatus = 429;
        self::assertNull($this->bluesky->resolve('alice'));
        self::assertTrue($this->bluesky->status('alice')['connected']);
    }

    public function testMalformedRefreshResponseDoesNotReplaceTheStoredCredential(): void
    {
        $this->http->expiresIn = -10;
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $this->http->expiresIn = 3600;
        $this->http->omitRefreshToken = true;

        $before = $this->server->runtime->database->fetchValue(
            'SELECT refresh_token FROM atproto_account WHERE account_id = :id',
            ['id' => 'alice'],
        );
        self::assertNull($this->bluesky->resolve('alice'));

        self::assertSame($before, $this->server->runtime->database->fetchValue(
            'SELECT refresh_token FROM atproto_account WHERE account_id = :id',
            ['id' => 'alice'],
        ));
        self::assertTrue($this->bluesky->status('alice')['connected']);
    }

    public function testDisconnectRemovesTheConnection(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $this->server->runtime->database->execute(
            'INSERT INTO social_atproto_follow'
            . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
            . " VALUES ('alice', 'did:plc:alice123', 'did:plc:followed', '3kfollow',"
            . " 'at://did:plc:alice123/app.bsky.graph.follow/3kfollow', 'bafyfollow', 'active', '2026-11-20T10:00:00Z')",
        );
        $this->insertInteractionRecord('did:plc:alice123', 'connected-like');
        $this->insertInteractionRecord('did:plc:native', 'native-like');

        self::assertTrue($this->bluesky->disconnect('alice'));
        self::assertFalse($this->bluesky->disconnect('alice'));
        self::assertNull($this->bluesky->resolve('alice'));
        self::assertSame(['connected' => false], $this->bluesky->status('alice'));
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_follow WHERE local_user_id = 'alice'",
        ));
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_interaction WHERE local_user_id = 'alice' AND account_did = 'did:plc:alice123'",
        ));
        self::assertSame(1, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_interaction WHERE local_user_id = 'alice' AND account_did = 'did:plc:native'",
        ));
    }

    public function testConnectingADifferentBlueskyAccountRemovesOldAccountFollows(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $this->server->runtime->database->execute(
            'INSERT INTO social_atproto_follow'
            . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
            . " VALUES ('alice', 'did:plc:alice123', 'did:plc:followed', '3kfollow',"
            . " 'at://did:plc:alice123/app.bsky.graph.follow/3kfollow', 'bafyfollow', 'active', '2026-11-20T10:00:00Z')",
        );
        $this->server->runtime->database->execute(
            'INSERT INTO social_atproto_follow'
            . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
            . " VALUES ('alice', 'did:plc:native', 'did:plc:native-followed', 'native-follow',"
            . " 'at://did:plc:native/app.bsky.graph.follow/native-follow', 'bafyfollow', 'active', '2026-11-20T10:00:00Z')",
        );
        $this->insertInteractionRecord('did:plc:alice123', 'connected-like');
        $this->insertInteractionRecord('did:plc:native', 'native-like');

        $this->http->resolvedDid = 'did:plc:alice456';
        $this->http->sessionDid = 'did:plc:alice456';
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');

        self::assertSame('did:plc:alice456', $this->bluesky->status('alice')['did']);
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_follow WHERE local_user_id = 'alice' AND account_did = 'did:plc:alice123'",
        ));
        self::assertSame(1, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_follow WHERE local_user_id = 'alice' AND account_did = 'did:plc:native'",
        ));
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_interaction WHERE local_user_id = 'alice' AND account_did = 'did:plc:alice123'",
        ));
        self::assertSame(1, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_interaction WHERE local_user_id = 'alice' AND account_did = 'did:plc:native'",
        ));
    }

    private function insertInteractionRecord(string $accountDid, string $rkey): void
    {
        $this->server->runtime->database->execute(
            'INSERT INTO social_atproto_interaction'
            . ' (local_user_id, account_did, post_id, kind, rkey, uri, created_at)'
            . ' VALUES (:user, :did, :post, :kind, :rkey, :uri, :created)',
            [
                'user' => 'alice',
                'did' => $accountDid,
                'post' => hash('sha256', 'at://did:plc:remote/app.bsky.feed.post/post1'),
                'kind' => 'like',
                'rkey' => $rkey,
                'uri' => 'at://' . $accountDid . '/app.bsky.feed.like/' . $rkey,
                'created' => '2026-11-24T10:00:00Z',
            ],
        );
    }

    public function testExternalPdsPublishesWithoutTheOptionalNativePds(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $post = $this->server->runtime->app->posts->create(new CreatePost('alice', 'published from standalone'));
        $auth = $this->server->signIn('alice');

        $response = $this->server->json(
            'POST',
            '/api/v1/host/bluesky/publish',
            ['post_id' => $post->id, 'account_did' => 'did:plc:alice123'],
            $auth['cookies'],
            $auth['csrf'],
        );

        self::assertSame(201, $response->status, $response->body);
        self::assertSame('did:plc:alice123', json_decode($response->body, true, 64, JSON_THROW_ON_ERROR)['publication']['account_did']);
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

    public function testAutomaticImagePublicationRetriesTemporaryBlobUploadFailure(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $png = base64_decode(self::PIXEL_PNG, true);
        self::assertIsString($png);
        $attachment = $this->server->runtime->app->media?->upload('alice', $png, 'Pixel', 'image/png');
        self::assertNotNull($attachment);
        $post = $this->server->runtime->app->posts->create(new CreatePost(
            'alice',
            'Image that retries #media',
            attachmentIds: [$attachment->id],
        ));

        $this->http->blobUploadFailures = 1;
        self::assertSame(1, $this->server->runtime->app->worker->runOnce());
        self::assertSame(1, $this->server->runtime->app->queue->pendingCount());
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_atproto_publication WHERE post_id = :post',
            ['post' => $post->id],
        ));

        $this->server->runtime->database->execute(
            'UPDATE social_job SET available_at = :available WHERE type = :type',
            [
                'available' => Time::format($this->server->runtime->clock->now()->modify('-1 minute')),
                'type' => JobType::ATPROTO_PUBLISH,
            ],
        );
        self::assertSame(1, $this->server->runtime->app->worker->runOnce());
        self::assertSame(0, $this->server->runtime->app->queue->pendingCount());
        self::assertSame(1, (int) $this->server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_atproto_publication WHERE post_id = :post',
            ['post' => $post->id],
        ));
        self::assertCount(2, array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.uploadBlob'),
        ));
    }

    public function testPublicAtprotoLikesRequireThePerAccountOptIn(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $post = $this->server->runtime->app->posts->create(new CreatePost('alice', 'like opt-in target'));
        $auth = $this->server->signIn('alice');
        $published = $this->server->json(
            'POST',
            '/api/v1/host/bluesky/publish',
            ['post_id' => $post->id],
            $auth['cookies'],
            $auth['csrf'],
        );
        self::assertSame(201, $published->status, $published->body);

        $this->http->requests = [];
        $likePath = '/api/v1/posts/' . rawurlencode($post->id) . '/like';
        $localLike = $this->server->json('POST', $likePath, [], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $localLike->status, $localLike->body);
        self::assertSame([], $this->http->requests, 'A local like must stay local before opt-in.');

        $this->server->runtime->preferences->update('alice', [
            'mirror_likes_to_atproto' => true,
            'mirror_atproto_account_did' => 'did:plc:alice123',
        ]);
        $this->server->json(
            'POST',
            $likePath,
            [],
            $auth['cookies'],
            $auth['csrf'],
        );
        $likeWrite = array_values(array_filter(
            $this->http->requests,
            static function (array $request): bool {
                if (!str_contains($request['url'], 'com.atproto.repo.putRecord')) {
                    return false;
                }
                $payload = json_decode($request['body'], true);

                return ($payload['collection'] ?? null) === 'app.bsky.feed.like';
            },
        ));
        self::assertCount(1, $likeWrite);
        $payload = json_decode($likeWrite[0]['body'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('app.bsky.feed.like', $payload['record']['$type']);
        self::assertSame(1, json_decode($localLike->body, true, 64, JSON_THROW_ON_ERROR)['interaction']['favourites_count']);

        $optOut = $this->server->json(
            'PATCH',
            '/api/v1/host/preferences',
            ['mirror_likes_to_atproto' => false],
            $auth['cookies'],
            $auth['csrf'],
        );
        self::assertSame(200, $optOut->status, $optOut->body);
        self::assertFalse(json_decode($optOut->body, true, 64, JSON_THROW_ON_ERROR)['mirror_cleanup_pending']['mirror_likes_to_atproto']);
        self::assertSame(0, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_interaction WHERE local_user_id = 'alice' AND kind = 'like'",
        ));

        $unlike = $this->server->json('DELETE', $likePath, [], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $unlike->status, $unlike->body);
        self::assertSame(0, json_decode($unlike->body, true, 64, JSON_THROW_ON_ERROR)['interaction']['favourites_count']);
        self::assertNotEmpty(array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.deleteRecord'),
        ));

        $this->server->runtime->preferences->update('alice', ['mirror_boosts_to_atproto' => true]);
        $boostPath = '/api/v1/posts/' . rawurlencode($post->id) . '/boost';
        $boost = $this->server->json('POST', $boostPath, [], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $boost->status, $boost->body);
        $repostWrite = array_values(array_filter(
            $this->http->requests,
            static function (array $request): bool {
                if (!str_contains($request['url'], 'com.atproto.repo.putRecord')) {
                    return false;
                }
                $payload = json_decode($request['body'], true);

                return ($payload['collection'] ?? null) === 'app.bsky.feed.repost';
            },
        ));
        self::assertCount(1, $repostWrite);

        $unboost = $this->server->json('DELETE', $boostPath, [], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $unboost->status, $unboost->body);
        $repostDeletes = array_values(array_filter(
            $this->http->requests,
            static function (array $request): bool {
                if (!str_contains($request['url'], 'com.atproto.repo.deleteRecord')) {
                    return false;
                }
                $payload = json_decode($request['body'], true);

                return ($payload['collection'] ?? null) === 'app.bsky.feed.repost';
            },
        ));
        self::assertCount(1, $repostDeletes);
    }

    public function testLocalLikeAndBoostReportAndRetryTransientAtprotoFailures(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $post = $this->server->runtime->app->posts->create(new CreatePost('alice', 'mirror retry target'));
        $auth = $this->server->signIn('alice');
        $this->server->json(
            'POST',
            '/api/v1/host/bluesky/publish',
            ['post_id' => $post->id],
            $auth['cookies'],
            $auth['csrf'],
        );
        $this->server->runtime->preferences->update('alice', [
            'mirror_likes_to_atproto' => true,
            'mirror_boosts_to_atproto' => true,
            'mirror_atproto_account_did' => 'did:plc:alice123',
        ]);
        $this->http->recordWriteFailureStatus = 503;

        $likePath = '/api/v1/posts/' . rawurlencode($post->id) . '/like';
        $failedLike = $this->server->json('POST', $likePath, [], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $failedLike->status, $failedLike->body);
        $failedLikeBody = json_decode($failedLike->body, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($failedLikeBody['interaction']['favourited']);
        self::assertSame('failed', $failedLikeBody['interaction']['atproto_mirror']['state']);
        self::assertTrue($failedLikeBody['interaction']['atproto_mirror']['retryable']);

        $this->http->recordWriteFailureStatus = null;
        $retriedLike = $this->server->json('POST', $likePath, [], $auth['cookies'], $auth['csrf']);
        $retriedLikeBody = json_decode($retriedLike->body, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('published', $retriedLikeBody['interaction']['atproto_mirror']['state']);
        self::assertSame(1, $retriedLikeBody['interaction']['favourites_count']);

        $boostPath = '/api/v1/posts/' . rawurlencode($post->id) . '/boost';
        $this->http->recordWriteFailureStatus = 503;
        $failedBoost = $this->server->json('POST', $boostPath, [], $auth['cookies'], $auth['csrf']);
        self::assertSame(200, $failedBoost->status, $failedBoost->body);
        $failedBoostBody = json_decode($failedBoost->body, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('failed', $failedBoostBody['atproto_mirror']['state']);
        self::assertTrue($failedBoostBody['atproto_mirror']['retryable']);

        $this->http->recordWriteFailureStatus = null;
        $retriedBoost = $this->server->json('POST', $boostPath, [], $auth['cookies'], $auth['csrf']);
        self::assertSame('published', json_decode($retriedBoost->body, true, 64, JSON_THROW_ON_ERROR)['atproto_mirror']['state']);
    }

    public function testInteractionPreferenceSelectsExternalIdentityWhenNativeIdentityAlsoExists(): void
    {
        $this->server = new TestServer([
            'atproto_pds' => true,
            'atproto_publish' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ], $this->http, new class implements HostResolver {
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
        $pds = new \AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService(
            $this->server->config->coreConfig(),
            $this->server->runtime->pdsRepository,
            \AlohaSocial\Core\Security\OpenSslSecretBox::fromBase64Key(
                $this->server->config->secretKey ?? trim((string) file_get_contents($this->server->config->secretKeyFile)),
            ),
            $this->server->runtime->clock,
            new \AlohaSocial\Core\Platform\Storage\LocalBlobStore($this->server->config->blobDirectory),
            null,
        );
        $nativeIdentity = $pds->createIdentity('alice');
        $this->server->runtime->blueskyAccounts->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $auth = $this->server->signIn('alice');
        $post = $this->server->runtime->app->posts->create(new CreatePost('alice', 'identity selection target'));
        $published = $this->server->json('POST', '/api/v1/host/bluesky/publish', [
            'post_id' => $post->id,
            'account_did' => 'did:plc:alice123',
        ], $auth['cookies'], $auth['csrf']);
        self::assertSame(201, $published->status, $published->body);
        self::assertNotSame('did:plc:alice123', $nativeIdentity['did']);

        $this->server->runtime->preferences->update('alice', [
            'mirror_likes_to_atproto' => true,
            'mirror_atproto_account_did' => 'did:plc:alice123',
        ]);
        $this->http->requests = [];
        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($post->id) . '/like',
            [],
            $auth['cookies'],
            $auth['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $likeWrites = array_filter($this->http->requests, static function (array $request): bool {
            if (!str_contains($request['url'], 'com.atproto.repo.putRecord')) {
                return false;
            }
            $payload = json_decode($request['body'], true);

            return ($payload['collection'] ?? null) === 'app.bsky.feed.like';
        });
        self::assertCount(1, $likeWrites, 'The selected external DID must be used even though a native identity exists.');
    }

    public function testOptOutStaysPendingWhenAnIdentityWithInteractionsIsDisconnected(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $this->insertInteractionRecord('did:plc:alice123', 'connected-like');
        $this->insertInteractionRecord('did:plc:alice456', 'disconnected-like');
        $auth = $this->server->signIn('alice');

        $response = $this->server->json(
            'PATCH',
            '/api/v1/host/preferences',
            ['mirror_likes_to_atproto' => false],
            $auth['cookies'],
            $auth['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($body['mirror_cleanup_pending']['mirror_likes_to_atproto']);
        self::assertSame(2, (int) $this->server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_atproto_interaction WHERE local_user_id = 'alice' AND kind = 'like'",
        ));
        self::assertSame([], array_values(array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.deleteRecord'),
        )));
    }

    public function testRemoteLikeCanBeRemovedAfterTheFeedTargetIsTombstoned(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $now = $this->server->runtime->clock->now();
        $source = 'jetstream';
        $remoteDid = 'did:plc:remoteauthor';
        $uri = 'at://' . $remoteDid . '/app.bsky.feed.post/remote-post';
        $inbound = new PdoAtprotoInboundRepository($this->server->runtime->database);
        $inbound->saveAccount(new AtprotoRemoteAccount(
            $source,
            $remoteDid,
            'author.example',
            AtprotoAccountStatus::Active,
            true,
            $now,
        ));
        $inbound->saveRecord(new AtprotoRemoteRecord(
            $source,
            $remoteDid,
            'app.bsky.feed.post',
            'remote-post',
            $uri,
            'bafy-remote-post',
            null,
            1,
            ['text' => 'Remote target', 'createdAt' => '2026-11-24T10:00:00Z'],
            false,
            $now,
            $now,
            $now,
        ));
        (new PdoAtprotoFollowRepository($this->server->runtime->database))->save(new AtprotoFollow(
            'alice',
            'did:plc:alice123',
            $remoteDid,
            'follow-remote-author',
            'at://did:plc:alice123/app.bsky.graph.follow/follow-remote-author',
            'bafy-follow',
            AtprotoFollow::ACTIVE,
            $now,
        ));

        $session = $this->server->signIn('alice');
        $path = '/api/v1/posts/' . rawurlencode($uri) . '/like';
        $liked = $this->server->json(
            'POST',
            $path,
            ['account_did' => 'did:plc:alice123'],
            $session['cookies'],
            $session['csrf'],
        );
        self::assertSame(200, $liked->status, $liked->body);

        $inbound->saveRecord(new AtprotoRemoteRecord(
            $source,
            $remoteDid,
            'app.bsky.feed.post',
            'remote-post',
            $uri,
            null,
            null,
            2,
            [],
            true,
            null,
            $now,
            $now,
        ));
        $this->http->requests = [];
        $unliked = $this->server->json(
            'DELETE',
            $path,
            ['account_did' => 'did:plc:alice123'],
            $session['cookies'],
            $session['csrf'],
        );

        self::assertSame(200, $unliked->status, $unliked->body);
        $deletes = array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.deleteRecord'),
        );
        self::assertCount(1, $deletes);
    }

    public function testRemotePostCanBeLikedWithoutFollowingItsAuthor(): void
    {
        $this->bluesky->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $now = $this->server->runtime->clock->now();
        $source = 'jetstream';
        $remoteDid = 'did:plc:unfollowedauthor';
        $uri = 'at://' . $remoteDid . '/app.bsky.feed.post/unfollowed-post';
        $inbound = new PdoAtprotoInboundRepository($this->server->runtime->database);
        $inbound->saveAccount(new AtprotoRemoteAccount(
            $source,
            $remoteDid,
            'unfollowed.example',
            AtprotoAccountStatus::Active,
            true,
            $now,
        ));
        $inbound->saveRecord(new AtprotoRemoteRecord(
            $source,
            $remoteDid,
            'app.bsky.feed.post',
            'unfollowed-post',
            $uri,
            'bafy-unfollowed-post',
            null,
            1,
            ['text' => 'Unfollowed target', 'createdAt' => '2026-11-24T10:00:00Z'],
            false,
            $now,
            $now,
            $now,
        ));

        $session = $this->server->signIn('alice');
        $path = '/api/v1/posts/' . rawurlencode($uri) . '/like';
        $this->http->requests = [];
        $liked = $this->server->json(
            'POST',
            $path,
            ['account_did' => 'did:plc:alice123'],
            $session['cookies'],
            $session['csrf'],
        );

        self::assertSame(200, $liked->status, $liked->body);
        $likedBody = json_decode($liked->body, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($likedBody['interaction']['favourited']);
        self::assertCount(1, array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.putRecord')
                && str_contains($request['body'], 'app.bsky.feed.like'),
        ));

        // The card state must be available for the same record that was just
        // liked, even though no follow edge to its author exists.
        $state = $this->server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $session['cookies'],
            'query' => ['ids' => $uri],
        ]);
        self::assertSame(200, $state->status);
        self::assertTrue(
            json_decode($state->body, true, 64, JSON_THROW_ON_ERROR)['interactions'][$uri]['favourited'],
        );
    }

    public function testRemoteLikeWithoutABlueskyIdentityExplainsWhatIsMissing(): void
    {
        $now = $this->server->runtime->clock->now();
        $source = 'jetstream';
        $remoteDid = 'did:plc:unconnectedauthor';
        $uri = 'at://' . $remoteDid . '/app.bsky.feed.post/unconnected-post';
        $inbound = new PdoAtprotoInboundRepository($this->server->runtime->database);
        $inbound->saveAccount(new AtprotoRemoteAccount(
            $source,
            $remoteDid,
            'unconnected.example',
            AtprotoAccountStatus::Active,
            true,
            $now,
        ));
        $inbound->saveRecord(new AtprotoRemoteRecord(
            $source,
            $remoteDid,
            'app.bsky.feed.post',
            'unconnected-post',
            $uri,
            'bafy-unconnected-post',
            null,
            1,
            ['text' => 'Unconnected target', 'createdAt' => '2026-11-24T10:00:00Z'],
            false,
            $now,
            $now,
            $now,
        ));

        $session = $this->server->signIn('alice');
        $response = $this->server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($uri) . '/like',
            [],
            $session['cookies'],
            $session['csrf'],
        );

        self::assertSame(409, $response->status, $response->body);
        self::assertSame(
            'atproto_account_not_connected',
            json_decode($response->body, true, 64, JSON_THROW_ON_ERROR)['error']['code'],
        );
    }

    public function testPublishedRecordsCanBeDeletedWhenOutboundPublishingIsDisabled(): void
    {
        $disabled = new TestServer(['atproto_publish' => false], $this->http, new class implements HostResolver {
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
        $disabled->createAccount('alice', 'alice-disabled@example.test');
        $disabled->runtime->blueskyAccounts->connect('alice', 'alice.bsky.social', 'abcd-efgh-ijkl-mnop');
        $post = $disabled->runtime->app->posts->create(new CreatePost('alice', 'previously published'));
        $disabled->runtime->database->execute(
            'INSERT INTO social_atproto_publication'
            . ' (local_user_id, account_did, post_id, rkey, uri, pds_url, published_at)'
            . ' VALUES (:user, :did, :post, :rkey, :uri, :pds, :published)',
            [
                'user' => 'alice',
                'did' => 'did:plc:alice123',
                'post' => $post->id,
                'rkey' => 'post1',
                'uri' => 'at://did:plc:alice123/app.bsky.feed.post/post1',
                'pds' => 'https://pds.example.test',
                'published' => '2026-11-24T00:00:00Z',
            ],
        );

        $disabled->runtime->app->atproto?->deleteRemote('alice', $post->id);

        self::assertNotEmpty(array_filter(
            $this->http->requests,
            static fn (array $request): bool => str_contains($request['url'], 'com.atproto.repo.deleteRecord'),
        ));
        self::assertSame(0, (int) $disabled->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_atproto_publication WHERE local_user_id = :user AND post_id = :post',
            ['user' => 'alice', 'post' => $post->id],
        ));
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
