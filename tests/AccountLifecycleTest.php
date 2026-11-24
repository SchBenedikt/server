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
        self::assertSame(403, $server->send(
            'GET',
            '/api/v1/host/preferences',
            ['cookies' => $login['cookies']],
        )->status, 'A frozen deletion blocks private reads as well as writes.');

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

    public function testAccountDeletionPurgesHostOwnedContentAndPseudonymizesTheTombstone(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $server->createAccount('bob', 'bob@example.test');
        $login = $server->signIn('alice');
        $db = $server->runtime->database;
        $stamp = '2026-11-24T12:00:00Z';

        $db->execute(
            'INSERT INTO draft (id, account_id, content, created_at, updated_at)'
            . ' VALUES (:id, :account, :content, :created, :updated)',
            ['id' => 'draft-1', 'account' => 'alice', 'content' => 'private draft', 'created' => $stamp, 'updated' => $stamp],
        );
        $db->execute(
            'INSERT INTO draft (id, account_id, content, created_at, updated_at)'
            . ' VALUES (:id, :account, :content, :created, :updated)',
            ['id' => 'draft-bob', 'account' => 'bob', 'content' => 'Bob draft', 'created' => $stamp, 'updated' => $stamp],
        );
        $db->execute(
            'INSERT INTO archived_post (account_id, post_id, archived_at) VALUES (:account, :post, :at)',
            ['account' => 'alice', 'post' => 'post-archived', 'at' => $stamp],
        );
        $db->execute(
            'INSERT INTO account_preference (account_id, updated_at) VALUES (:account, :updated)',
            ['account' => 'alice', 'updated' => $stamp],
        );
        $db->execute(
            'INSERT INTO recommendation_feedback (account_id, post_id, kind, created_at)'
            . ' VALUES (:account, :post, :kind, :created)',
            ['account' => 'alice', 'post' => 'post-feedback', 'kind' => 'hide', 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO featured_tag (account_id, tag, position, created_at)'
            . ' VALUES (:account, :tag, 0, :created)',
            ['account' => 'alice', 'tag' => 'private-topic', 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO collection (id, account_id, title, created_at, updated_at)'
            . ' VALUES (:id, :account, :title, :created, :updated)',
            ['id' => 'collection-1', 'account' => 'alice', 'title' => 'Private list', 'created' => $stamp, 'updated' => $stamp],
        );
        $db->execute(
            'INSERT INTO collection_item (collection_id, post_id, position, created_at)'
            . ' VALUES (:collection, :post, 0, :created)',
            ['collection' => 'collection-1', 'post' => 'post-1', 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO story (id, account_id, content, created_at, expires_at)'
            . ' VALUES (:id, :account, :content, :created, :expires)',
            ['id' => 'story-1', 'account' => 'alice', 'content' => 'temporary story', 'created' => $stamp, 'expires' => '2026-11-25T12:00:00Z'],
        );
        $db->execute(
            'INSERT INTO story (id, account_id, content, created_at, expires_at)'
            . ' VALUES (:id, :account, :content, :created, :expires)',
            ['id' => 'story-bob', 'account' => 'bob', 'content' => 'Bob story', 'created' => $stamp, 'expires' => '2026-11-25T12:00:00Z'],
        );
        $db->execute(
            'INSERT INTO story_seen (story_id, account_id, seen_at) VALUES (:story, :account, :seen)',
            ['story' => 'story-1', 'account' => 'alice', 'seen' => $stamp],
        );
        $db->execute(
            'INSERT INTO kokua (id, account_id, kind, title, category, created_at, updated_at)'
            . ' VALUES (:id, :account, :kind, :title, :category, :created, :updated)',
            ['id' => 'kokua-1', 'account' => 'alice', 'kind' => 'offer', 'title' => 'Private offer', 'category' => 'other', 'created' => $stamp, 'updated' => $stamp],
        );
        $db->execute(
            'INSERT INTO kokua_help (id, kokua_id, account_id, message, created_at)'
            . ' VALUES (:id, :kokua, :account, :message, :created)',
            ['id' => 'kokua-help-1', 'kokua' => 'kokua-1', 'account' => 'bob', 'message' => 'Private response', 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO follow_request (id, target_account_id, actor_id, actor_handle, created_at)'
            . ' VALUES (:id, :target, :actor, :handle, :created)',
            ['id' => 'follow-request-1', 'target' => 'alice', 'actor' => 'https://remote.example/@person', 'handle' => 'person@remote.example', 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO follow_request (id, target_account_id, actor_id, actor_handle, created_at)'
            . ' VALUES (:id, :target, :actor, :handle, :created)',
            ['id' => 'follow-request-outgoing', 'target' => 'bob', 'actor' => 'http://localhost:3000/@alice', 'handle' => 'alice', 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO follow_request (id, target_account_id, actor_id, actor_handle, created_at)'
            . ' VALUES (:id, :target, :actor, :handle, :created)',
            ['id' => 'follow-request-bob', 'target' => 'bob', 'actor' => 'https://other.example/@person', 'handle' => 'person@other.example', 'created' => $stamp],
        );
        $db->execute(
            'INSERT INTO announcement (id, author_id, content, created_at, updated_at)'
            . ' VALUES (:id, :author, :content, :created, :updated)',
            ['id' => 'announcement-1', 'author' => 'alice', 'content' => 'Temporary announcement', 'created' => $stamp, 'updated' => $stamp],
        );
        $db->execute(
            'INSERT INTO password_reset (token_hash, account_id, created_at, expires_at)'
            . ' VALUES (:token, :account, :created, :expires)',
            ['token' => str_repeat('a', 64), 'account' => 'alice', 'created' => $stamp, 'expires' => '2026-11-25T12:00:00Z'],
        );
        $db->execute(
            'INSERT INTO atproto_session (access_hash, refresh_hash, account_id, created_at, access_expires_at, refresh_expires_at)'
            . ' VALUES (:access, :refresh, :account, :created, :access_expires, :refresh_expires)',
            ['access' => str_repeat('d', 64), 'refresh' => str_repeat('e', 64), 'account' => 'alice', 'created' => $stamp, 'access_expires' => '2026-11-25T12:00:00Z', 'refresh_expires' => '2026-12-24T12:00:00Z'],
        );
        $db->execute(
            'INSERT INTO atproto_account'
            . ' (account_id, did, handle, pds_url, access_token, refresh_token, expires_at, active, created_at, updated_at)'
            . ' VALUES (:account, :did, :handle, :pds, :access, :refresh, :expires, 1, :created, :updated)',
            ['account' => 'alice', 'did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'pds' => 'https://pds.example', 'access' => 'sealed-access', 'refresh' => 'sealed-refresh', 'expires' => '2026-11-25T12:00:00Z', 'created' => $stamp, 'updated' => $stamp],
        );
        $db->execute(
            'INSERT INTO atproto_oauth_account (account_id, issuer, token_endpoint, client_id, scope, dpop_private_key)'
            . ' VALUES (:account, :issuer, :endpoint, :client, :scope, :key)',
            ['account' => 'alice', 'issuer' => 'https://issuer.example', 'endpoint' => 'https://issuer.example/token', 'client' => 'client', 'scope' => 'atproto', 'key' => 'private-key-material'],
        );
        $db->execute(
            'INSERT INTO mastodon_oauth_code'
            . ' (code_hash, client_id, account_id, redirect_uri, scopes, code_challenge, created_at, expires_at)'
            . ' VALUES (:code, :client, :account, :redirect, :scopes, :challenge, :created, :expires)',
            ['code' => str_repeat('f', 64), 'client' => str_repeat('1', 64), 'account' => 'alice', 'redirect' => 'https://client.example/callback', 'scopes' => 'read', 'challenge' => str_repeat('2', 43), 'created' => $stamp, 'expires' => '2026-11-25T12:00:00Z'],
        );
        $db->execute(
            'INSERT INTO mastodon_oauth_token'
            . ' (access_hash, refresh_hash, client_id, account_id, scopes, created_at, expires_at, refresh_expires_at)'
            . ' VALUES (:access, :refresh, :client, :account, :scopes, :created, :expires, :refresh_expires)',
            ['access' => str_repeat('3', 64), 'refresh' => str_repeat('4', 64), 'client' => str_repeat('1', 64), 'account' => 'alice', 'scopes' => 'read', 'created' => $stamp, 'expires' => '2026-11-25T12:00:00Z', 'refresh_expires' => '2026-12-24T12:00:00Z'],
        );
        $db->execute(
            'INSERT INTO atproto_oauth_flow (state_hash, account_id, browser_session_hash, payload, created_at, expires_at)'
            . ' VALUES (:state, :account, :session, :payload, :created, :expires)',
            ['state' => str_repeat('b', 64), 'account' => 'alice', 'session' => str_repeat('c', 64), 'payload' => '{}', 'created' => $stamp, 'expires' => '2026-11-25T12:00:00Z'],
        );

        $response = $server->json('DELETE', '/api/v1/account/data', [], $login['cookies'], $login['csrf']);

        self::assertSame(200, $response->status, $response->body);
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['host_data_deleted']['drafts']);
        self::assertSame(1, $payload['host_data_deleted']['stories']);
        self::assertSame(1, $payload['host_data_deleted']['kokua_posts']);
        self::assertSame(1, $payload['host_data_deleted']['kokua_replies']);
        self::assertSame(1, $payload['host_data_deleted']['announcements']);
        self::assertSame(1, $payload['host_data_deleted']['password_resets']);
        self::assertSame(1, $payload['host_data_deleted']['atproto_oauth_flows']);
        self::assertSame(1, $payload['host_data_deleted']['sessions']);
        self::assertSame(1, $payload['host_data_deleted']['atproto_sessions']);
        self::assertSame(1, $payload['host_data_deleted']['linked_bluesky_credentials']);
        self::assertSame(1, $payload['host_data_deleted']['linked_atproto_oauth_account']);
        self::assertSame(1, $payload['host_data_deleted']['mastodon_oauth_codes']);
        self::assertSame(1, $payload['host_data_deleted']['mastodon_oauth_tokens']);
        foreach ([
            'draft', 'archived_post', 'account_preference', 'recommendation_feedback',
            'featured_tag', 'collection', 'story', 'kokua',
        ] as $table) {
            self::assertSame(0, (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE account_id = :account_id',
                ['account_id' => 'alice'],
            ), $table . ' should be purged');
        }
        foreach (['collection_item', 'story_seen', 'kokua_help'] as $table) {
            self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $table), $table . ' should be purged');
        }
        self::assertSame(1, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM story WHERE account_id = :account_id',
            ['account_id' => 'bob'],
        ));
        self::assertSame(1, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM follow_request WHERE target_account_id = :account_id',
            ['account_id' => 'bob'],
        ));
        self::assertSame('https://other.example/@person', (string) $db->fetchValue(
            'SELECT actor_id FROM follow_request WHERE target_account_id = :account_id',
            ['account_id' => 'bob'],
        ));
        self::assertSame(1, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM draft WHERE account_id = :account_id',
            ['account_id' => 'bob'],
        ));
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM password_reset'));
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM atproto_oauth_flow'));
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM atproto_oauth_account'));
        self::assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM mastodon_oauth_token'));

        $tombstone = $server->runtime->accounts->find('alice');
        self::assertNotNull($tombstone);
        self::assertTrue($tombstone->isDisabled());
        self::assertMatchesRegularExpression('/^deleted-[a-f0-9]{64}$/', $tombstone->handle);
        self::assertSame(
            'deleted+' . substr($tombstone->handle, strlen('deleted-')) . '@example.invalid',
            $tombstone->email,
        );
        self::assertNull($tombstone->displayName);
        self::assertFalse($server->runtime->passwordHasher->verify(TestServer::PASSWORD, $tombstone->passwordHash));
        self::assertSame('erased', $server->runtime->app->accountData?->deletionStatus('alice')->state);

        $repeat = $server->runtime->accountErasureCleanup->erase('alice');
        self::assertSame(0, array_sum($repeat), 'Cleanup retries are idempotent.');
        self::assertSame($tombstone->handle, $server->runtime->accounts->find('alice')?->handle);
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
