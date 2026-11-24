<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsIdentity;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsRepository;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Server\Federation\NativePdsAccountResolver;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The native PDS is not only readable metadata: a signed-in account can create
 * records and blobs through the XRPC routes a real ATProto client uses.
 */
final class NativePdsTest extends TestCase
{
    public function testRotatedPlcOperationCanBeRetriedAfterDirectoryFailure(): void
    {
        $http = new NativePdsPlcHttpClient([200, 503, 200]);
        $server = new TestServer([
            'atproto_pds' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.example',
        ], $http);
        $server->createAccount('alice', 'alice@example.test');
        $webSession = $server->signIn();

        $created = $server->json(
            'POST',
            '/api/v1/host/atproto/identity',
            ['handle' => 'alice.social.example'],
            $webSession['cookies'],
            $webSession['csrf'],
        );
        self::assertSame(201, $created->status, $created->body);
        $did = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR)['identity']['did'];

        $rotation = $server->json(
            'POST',
            '/api/v1/host/atproto/identity/rotate',
            [],
            $webSession['cookies'],
            $webSession['csrf'],
        );
        self::assertSame(502, $rotation->status);
        self::assertSame(2, $server->runtime->pdsRepository->plcOperations($did)[1]->seq);

        $createdToken = $server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice',
            'password' => TestServer::PASSWORD,
        ]);
        self::assertSame(200, $createdToken->status, $createdToken->body);
        $token = json_decode($createdToken->body, true, 512, JSON_THROW_ON_ERROR)['accessJwt'];
        $retry = $server->send('POST', '/xrpc/com.atproto.identity.submitPlcOperation', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        self::assertSame(200, $retry->status, $retry->body);
        self::assertSame(3, count($http->requests));
        $retriedOperation = json_decode($http->requests[2]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('at://alice.social.example', $retriedOperation['alsoKnownAs'][0] ?? null);
        self::assertSame(2, json_decode($retry->body, true, 512, JSON_THROW_ON_ERROR)['seq']);
    }

    public function testFrozenDeletionBlocksNativePdsSessionsAndBearerWrites(): void
    {
        $server = new TestServer([
            'atproto_pds' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);
        $server->createAccount('alice', 'alice@example.test');

        $secret = $server->config->secretKey
            ?? trim((string) file_get_contents($server->config->secretKeyFile));
        $pds = new NativePdsService(
            $server->config->coreConfig(),
            $server->runtime->pdsRepository,
            OpenSslSecretBox::fromBase64Key($secret),
            $server->runtime->clock,
            new LocalBlobStore($server->config->blobDirectory),
            null,
        );
        $identity = $pds->createIdentity('alice');
        $created = $server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice',
            'password' => TestServer::PASSWORD,
        ]);
        self::assertSame(200, $created->status, $created->body);
        $session = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);

        $server->runtime->database->execute(
            'INSERT INTO social_account_deletion'
            . ' (user_id, state, requested_at, updated_at, completed_at, deleted_counts,'
            . ' blobs_deleted, blob_failures, attempts, lease_until)'
            . ' VALUES (:user, :state, :requested, :updated, NULL, :counts, 0, :failures, 0, NULL)',
            [
                'user' => 'alice',
                'state' => 'frozen',
                'requested' => '2026-11-24T00:00:00Z',
                'updated' => '2026-11-24T00:00:00Z',
                'counts' => '{}',
                'failures' => '[]',
            ],
        );

        $refresh = $server->send('POST', '/xrpc/com.atproto.server.refreshSession', [
            'headers' => ['Authorization' => 'Bearer ' . $session['refreshJwt']],
        ]);
        self::assertSame(401, $refresh->status, $refresh->body);

        $newSession = $server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice',
            'password' => TestServer::PASSWORD,
        ]);
        self::assertSame(401, $newSession->status, $newSession->body);

        $readSession = $server->send('GET', '/xrpc/com.atproto.server.getSession', [
            'headers' => ['Authorization' => 'Bearer ' . $session['accessJwt']],
        ]);
        self::assertSame(401, $readSession->status, $readSession->body);

        $write = $server->send('POST', '/xrpc/com.atproto.repo.putRecord', [
            'headers' => [
                'Authorization' => 'Bearer ' . $session['accessJwt'],
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'repo' => $identity['did'],
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'frozen-write',
                'record' => [
                    '$type' => 'app.bsky.feed.post',
                    'text' => 'Must not write during deletion',
                    'createdAt' => '2026-11-24T00:00:00Z',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(401, $write->status, $write->body);

        $deleteSession = $server->send('POST', '/xrpc/com.atproto.server.deleteSession', [
            'headers' => ['Authorization' => 'Bearer ' . $session['accessJwt']],
        ]);
        self::assertSame(200, $deleteSession->status, $deleteSession->body);
        self::assertNull($server->runtime->database->fetchValue(
            'SELECT account_id FROM atproto_session WHERE account_id = :account',
            ['account' => 'alice'],
        ));
    }

    public function testBearerSessionCanRoundtripRecordsAndBlobsAcrossTheNativePds(): void
    {
        $server = new TestServer([
            'atproto_pds' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);
        $server->createAccount('alice', 'alice@example.test');
        $server->createAccount('bob', 'bob@example.test');

        $secret = $server->config->secretKey
            ?? trim((string) file_get_contents($server->config->secretKeyFile));
        $pds = new NativePdsService(
            $server->config->coreConfig(),
            $server->runtime->pdsRepository,
            OpenSslSecretBox::fromBase64Key($secret),
            $server->runtime->clock,
            new LocalBlobStore($server->config->blobDirectory),
            null,
        );
        $identity = $pds->createIdentity('alice');
        $bobIdentity = $pds->createIdentity('bob');

        $created = $server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'alice',
            'password' => TestServer::PASSWORD,
        ]);
        self::assertSame(200, $created->status, $created->body);
        $session = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR);

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => 'A record written through the native PDS',
            'createdAt' => '2026-11-18T10:00:00Z',
        ];
        $write = $server->send('POST', '/xrpc/com.atproto.repo.putRecord', [
            'headers' => [
                'Authorization' => 'Bearer ' . $session['accessJwt'],
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'repo' => $identity['did'],
                'collection' => 'app.bsky.feed.post',
                'rkey' => 'test-record',
                'record' => $record,
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(200, $write->status, $write->body);
        self::assertStringContainsString('at://' . $identity['did'] . '/app.bsky.feed.post/test-record', $write->body);

        $read = $server->send('GET', '/xrpc/com.atproto.repo.getRecord', ['query' => [
            'repo' => $identity['did'],
            'collection' => 'app.bsky.feed.post',
            'rkey' => 'test-record',
        ]]);
        self::assertSame(200, $read->status, $read->body);
        $readValue = json_decode($read->body, true, 512, JSON_THROW_ON_ERROR)['value'];
        self::assertSame($record['$type'], $readValue['$type']);
        self::assertSame($record['text'], $readValue['text']);
        self::assertSame($record['createdAt'], $readValue['createdAt']);

        $export = $server->send('GET', '/xrpc/com.atproto.sync.getRepo', ['query' => ['did' => $identity['did']]]);
        self::assertSame(200, $export->status, $export->body);
        self::assertSame('application/vnd.ipld.car', $export->header('Content-Type'));
        // Import is intentionally forbidden into a non-empty repository, so
        // export/import must target a second account with its own bearer token.
        $bobSessionResponse = $server->json('POST', '/xrpc/com.atproto.server.createSession', [
            'identifier' => 'bob',
            'password' => TestServer::PASSWORD,
        ]);
        self::assertSame(200, $bobSessionResponse->status, $bobSessionResponse->body);
        $bobSession = json_decode($bobSessionResponse->body, true, 512, JSON_THROW_ON_ERROR);
        $import = $server->send('POST', '/xrpc/com.atproto.repo.importRepo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $bobSession['accessJwt'],
                'Content-Type' => 'application/vnd.ipld.car',
            ],
            'body' => $export->body,
        ]);
        self::assertSame(200, $import->status, $import->body);
        $importedRead = $server->send('GET', '/xrpc/com.atproto.repo.getRecord', ['query' => [
            'repo' => $bobIdentity['did'],
            'collection' => 'app.bsky.feed.post',
            'rkey' => 'test-record',
        ]]);
        self::assertSame(200, $importedRead->status, $importedRead->body);
        $importedValue = json_decode($importedRead->body, true, 512, JSON_THROW_ON_ERROR)['value'];
        self::assertSame($record['$type'], $importedValue['$type']);
        self::assertSame($record['text'], $importedValue['text']);
        self::assertSame($record['createdAt'], $importedValue['createdAt']);

        $blobBytes = "\x89PNG\r\n\x1a\nserver-host-blob";
        $upload = $server->send('POST', '/xrpc/com.atproto.repo.uploadBlob', [
            'headers' => [
                'Authorization' => 'Bearer ' . $session['accessJwt'],
                'Content-Type' => 'image/png',
            ],
            'body' => $blobBytes,
        ]);
        self::assertSame(200, $upload->status, $upload->body);
        $blob = json_decode($upload->body, true, 512, JSON_THROW_ON_ERROR)['blob'];
        $download = $server->send('GET', '/xrpc/com.atproto.sync.getBlob', ['query' => [
            'did' => $identity['did'],
            'cid' => $blob['ref']['$link'],
        ]]);
        self::assertSame(200, $download->status, $download->body);
        self::assertSame('image/png', $download->header('Content-Type'));
        self::assertSame($blobBytes, $download->body);
    }

    public function testLocalPostIsQueuedIntoTheNativeRepository(): void
    {
        $server = new TestServer([
            'atproto_pds' => true,
            'atproto_publish' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);
        $server->createAccount('alice', 'alice@example.test');

        $secret = $server->config->secretKey
            ?? trim((string) file_get_contents($server->config->secretKeyFile));
        $pds = new NativePdsService(
            $server->config->coreConfig(),
            $server->runtime->pdsRepository,
            OpenSslSecretBox::fromBase64Key($secret),
            $server->runtime->clock,
            new LocalBlobStore($server->config->blobDirectory),
            null,
        );
        $identity = $pds->createIdentity('alice');

        $session = $server->signIn();
        $created = $server->json('POST', '/api/v1/posts', [
            'content' => 'A local post that belongs in the native repository',
            'visibility' => 'public',
        ], $session['cookies'], $session['csrf']);
        self::assertSame(201, $created->status, $created->body);
        $post = json_decode($created->body, true, 512, JSON_THROW_ON_ERROR)['post'];

        for ($tick = 0; $tick < 10; $tick++) {
            if ($server->runtime->app->worker->runOnce(20) === 0) {
                break;
            }
        }

        $records = $server->runtime->pdsRepository->records($identity['did']);
        $matched = array_values(array_filter(
            array_keys($records),
            static fn (string $key): bool => str_starts_with($key, 'app.bsky.feed.post/'),
        ));
        self::assertNotSame([], $matched);

        $publication = $server->runtime->database->fetchOne(
            'SELECT uri FROM social_atproto_publication WHERE local_user_id = :user AND post_id = :post',
            ['user' => 'alice', 'post' => $post['id']],
        );
        self::assertNotNull($publication);
        self::assertStringStartsWith('at://' . $identity['did'] . '/', (string) $publication['uri']);
    }

    public function testDisabledHostAccountCannotResolveItsNativePdsIdentity(): void
    {
        $server = new TestServer([
            'atproto_pds' => true,
            'atproto_pds_url' => 'https://social.example',
            'atproto_pds_handle_domain' => 'social.example',
            'atproto_plc_url' => 'https://plc.directory',
        ]);
        $server->createAccount('alice', 'alice@example.test');
        $identities = $this->createMock(PdsRepository::class);
        $identities->expects(self::once())->method('identityForUser')->with('alice')->willReturn(new PdsIdentity(
            'alice',
            'did:plc:alice123',
            'alice.social.example',
            'signing-key',
            'rotation-key',
            null,
            null,
            0,
            '2026-11-23T00:00:00Z',
            '2026-11-23T00:00:00Z',
        ));
        $resolver = new NativePdsAccountResolver(
            $server->config,
            $identities,
            $server->runtime->database,
        );

        self::assertCount(1, $resolver->resolveAll('alice'));
        $server->runtime->accounts->disable('alice');
        self::assertNull($resolver->resolve('alice'));
    }
}

/** @internal Deterministic PLC directory for native PDS retry integration tests. */
final class NativePdsPlcHttpClient implements BoundedHttpClient
{
    /** @var list<array{method: string, url: string, body: string}> */
    public array $requests = [];

    /** @param list<int> $statuses */
    public function __construct(private array $statuses)
    {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 10,
    ): HttpResponse {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        $status = array_shift($this->statuses) ?? 500;

        return new HttpResponse($status, [], '{}');
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
        return $this->request($method, $url, $headers, $body, $timeoutSeconds);
    }
}
