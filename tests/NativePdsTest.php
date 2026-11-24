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
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Server\Federation\NativePdsAccountResolver;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The native PDS is not only readable metadata: a signed-in account can create
 * a repository record through the same XRPC route a real ATProto client uses.
 */
final class NativePdsTest extends TestCase
{
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

    public function testBearerSessionCanWriteAndReadANativeRepositoryRecord(): void
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

        $stored = $server->runtime->pdsRepository->record(
            $identity['did'],
            'app.bsky.feed.post',
            'test-record',
        );
        self::assertNotNull($stored);
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
