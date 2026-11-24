<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsSchema;
use AlohaSocial\Core\Domain\Atproto\Pds\PdoPdsRepository;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class AccountLifecycleTest extends TestCase
{
    public function testFrozenDeletionBlocksHostWritesAndLoginButCanBeRetried(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $login = $server->signIn('alice');
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

        $profile = $server->json(
            'PATCH',
            '/api/v1/host/profile',
            ['display_name' => 'Must remain unchanged'],
            $login['cookies'],
            $login['csrf'],
        );
        self::assertSame(403, $profile->status, $profile->body);
        self::assertSame(
            'account_deletion_in_progress',
            json_decode($profile->body, true, 512, JSON_THROW_ON_ERROR)['error']['code'],
        );
        self::assertNull($server->runtime->accounts->find('alice')?->displayName);

        $newLogin = $server->login('alice', TestServer::PASSWORD);
        self::assertNotSame(303, $newLogin['response']->status);

        $retry = $server->json(
            'DELETE',
            '/api/v1/account/data',
            [],
            $login['cookies'],
            $login['csrf'],
        );
        self::assertSame(200, $retry->status, $retry->body);
        self::assertSame('erased', $server->runtime->app->accountData?->deletionStatus('alice')->state);
    }

    public function testAccountDeletionErasesCoreDataAndRetiresHostCredentials(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $login = $server->signIn('alice');
        $post = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Private account data', Visibility::Public),
        );
        $server->runtime->database->execute(
            'INSERT INTO social_follower'
            . ' (id, local_user_id, actor_id, inbox_url, shared_inbox_url, followed_at, is_local, state, follow_activity)'
            . ' VALUES (:id, :user, :actor, :inbox, :shared, :followed_at, 0, :state, NULL)',
            [
                'id' => 'remote-follower',
                'user' => 'alice',
                'actor' => 'https://peer.example/@follower',
                'inbox' => 'https://peer.example/inbox/follower',
                'shared' => 'https://peer.example/inbox',
                'followed_at' => '2026-11-24T00:00:00Z',
                'state' => 'accepted',
            ],
        );
        $server->runtime->database->execute(
            'INSERT INTO social_following'
            . ' (id, local_user_id, actor_id, inbox_url, followed_at, state, follow_activity_id)'
            . ' VALUES (:id, :user, :actor, :inbox, :followed_at, :state, :activity)',
            [
                'id' => 'remote-follow',
                'user' => 'alice',
                'actor' => 'https://remote.example/@followed',
                'inbox' => 'https://remote.example/inbox',
                'followed_at' => '2026-11-24T00:00:00Z',
                'state' => 'accepted',
                'activity' => 'http://localhost:3000/api/v1/following/remote-follow/follow',
            ],
        );
        $server->runtime->database->execute(
            'INSERT INTO social_atproto_follow'
            . ' (local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at)'
            . ' VALUES (:user, :did, :subject, :rkey, :uri, :cid, :state, :followed_at)',
            [
                'user' => 'alice',
                'did' => 'did:plc:alice',
                'subject' => 'did:plc:followed',
                'rkey' => 'follow-alice',
                'uri' => 'at://did:plc:alice/app.bsky.graph.follow/follow-alice',
                'cid' => 'bafy-alice',
                'state' => 'active',
                'followed_at' => '2026-11-24T00:00:00Z',
            ],
        );

        $response = $server->json(
            'DELETE',
            '/api/v1/account/data',
            [],
            $login['cookies'],
            $login['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('erased', $body['deletion']['state']);
        self::assertTrue($body['account_disabled']);
        self::assertSame('ActivityPub and Bluesky data at remote servers may persist.', $body['remote_deletion']);
        self::assertNotEmpty($body['deletion']['deleted']);

        $account = $server->runtime->accounts->find('alice');
        self::assertNotNull($account);
        self::assertTrue($account->isDisabled());
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_post WHERE id = :id',
            ['id' => $post->id],
        ));
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM social_atproto_follow WHERE local_user_id = :user',
            ['user' => 'alice'],
        ));
        $federationJobs = $server->runtime->database->fetchAll(
            'SELECT type, payload, subject_type FROM social_job WHERE type = :type ORDER BY id',
            ['type' => JobType::FEDERATION_DELIVER],
        );
        self::assertCount(2, $federationJobs);
        $jobPayloads = array_map(
            static fn (array $job): array => json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR),
            $federationJobs,
        );
        $deletePayload = array_values(array_filter(
            $jobPayloads,
            static fn (array $payload): bool => ($payload['activity']['type'] ?? null) === 'Delete',
        ));
        $undoPayload = array_values(array_filter(
            $jobPayloads,
            static fn (array $payload): bool => ($payload['activity']['type'] ?? null) === 'Undo',
        ));
        self::assertCount(1, $deletePayload);
        self::assertCount(1, $undoPayload);
        self::assertSame('https://peer.example/inbox', $deletePayload[0]['inbox']);
        self::assertSame('https://remote.example/inbox', $undoPayload[0]['inbox']);
        self::assertSame([null, null], array_column($federationJobs, 'subject_type'));
        self::assertSame(401, $server->send(
            'GET',
            '/api/v1/host/preferences',
            ['cookies' => $login['cookies']],
        )->status);
        self::assertSame(404, $server->send('GET', '/@alice')->status);
        self::assertSame('erased', $server->runtime->app->accountData?->deletionStatus('alice')->state);
    }

    public function testLastAdministratorCannotDeleteTheirOwnAccount(): void
    {
        $server = new TestServer();
        $server->createAccount('admin', 'admin@example.test', TestServer::PASSWORD, true);
        $login = $server->signIn('admin');

        $response = $server->json(
            'DELETE',
            '/api/v1/account/data',
            [],
            $login['cookies'],
            $login['csrf'],
        );

        self::assertSame(409, $response->status);
        self::assertFalse($server->runtime->accounts->find('admin')?->isDisabled());
        self::assertSame('active', $server->runtime->app->accountData?->deletionStatus('admin')->state);
    }

    public function testAccountErasurePublishesNativePdsDeletionStatus(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $login = $server->signIn('alice');
        $did = 'did:plc:alice';
        $server->runtime->database->execute(
            'INSERT INTO ' . PdsSchema::IDENTITY_TABLE
            . ' (local_user_id, did, handle, signing_key_id, rotation_key_id, head_cid, head_rev, seq, created_at, updated_at)'
            . ' VALUES (:user, :did, :handle, :signing, :rotation, NULL, NULL, 1, :created, :updated)',
            [
                'user' => 'alice',
                'did' => $did,
                'handle' => 'alice.social.test',
                'signing' => 'signing-key',
                'rotation' => 'rotation-key',
                'created' => '2026-11-24T00:00:00Z',
                'updated' => '2026-11-24T00:00:00Z',
            ],
        );
        $server->runtime->database->execute(
            'INSERT INTO ' . PdsSchema::EVENT_TABLE
            . ' (seq, did, commit_cid, rev, since_rev, ops_json, blocks_car, blobs_json, created_at)'
            . ' VALUES (1, :did, :commit, :rev, :since, :ops, :blocks, :blobs, :created)',
            [
                'did' => $did,
                'commit' => 'old-commit',
                'rev' => 'old-rev',
                'since' => '',
                'ops' => '[]',
                'blocks' => '',
                'blobs' => '[]',
                'created' => '2026-11-24T00:01:00Z',
            ],
        );
        $server->runtime->database->execute(
            'UPDATE ' . PdsSchema::SEQUENCE_TABLE . ' SET value = 1 WHERE name = :name',
            ['name' => 'firehose'],
        );

        $response = $server->json(
            'DELETE',
            '/api/v1/account/data',
            [],
            $login['cookies'],
            $login['csrf'],
        );

        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, (int) $server->runtime->database->fetchValue(
            'SELECT COUNT(*) FROM ' . PdsSchema::IDENTITY_TABLE . ' WHERE local_user_id = :user',
            ['user' => 'alice'],
        ));
        $events = (new PdoPdsRepository($server->runtime->database))->eventsAfter(1, 10);
        self::assertCount(1, $events);
        self::assertSame([
            '$type' => '#account',
            'seq' => 2,
            'did' => $did,
            'time' => $body['deletion']['requested_at'],
            'active' => false,
            'status' => 'deleted',
        ], $events[0]->toArray());
    }
}
