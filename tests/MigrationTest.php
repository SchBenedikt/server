<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Migrations\MigrationException;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    public function testFreshInstallCreatesTheCoreAndHostSchema(): void
    {
        $server = new TestServer();

        $rows = $server->runtime->database->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table'");
        $tables = array_map(static fn (array $row): string => (string) $row['name'], $rows);

        foreach ([
            'schema_migration',
            'social_post',
            'social_post_attachment',
            'social_follower',
            'social_remote_post',
            'social_job',
            'social_activity_log',
            'social_atproto_publication',
            'social_post_pin',
            'social_media_collection',
            'social_media_collection_item',
            'social_account_move',
            'social_report',
            'social_moderation_action',
            'social_held_post',
            'social_story',
            'social_conversation_marker',
            'social_search_index',
            'social_recommendation_preference',
            'social_account_deletion',
            'social_pds_identity',
            'social_remote_reply',
            'social_remote_object_tombstone',
            'atproto_session',
            'mastodon_oauth_app',
            'mastodon_oauth_code',
            'mastodon_oauth_token',
            'account',
            'session',
            'login_attempt',
            'instance_key',
            'installation_identity',
            'kokua',
            'kokua_help',
            'runtime_error',
        ] as $expected) {
            self::assertContains($expected, $tables, 'Missing table: ' . $expected);
        }
        foreach (['collection', 'collection_item', 'story', 'story_seen'] as $legacy) {
            self::assertNotContains($legacy, $tables, 'Legacy table should have been consolidated: ' . $legacy);
        }

        $accountColumns = $server->runtime->database->fetchAll('PRAGMA table_info(account)');
        self::assertContains('credential_version', array_column($accountColumns, 'name'));
        $resetColumns = $server->runtime->database->fetchAll('PRAGMA table_info(password_reset)');
        self::assertContains('credential_version', array_column($resetColumns, 'name'));
        $resetIndexes = $server->runtime->database->fetchAll("PRAGMA index_list('password_reset')");
        self::assertContains('password_reset_account_unique', array_column($resetIndexes, 'name'));

        $preferenceColumns = $server->runtime->database->fetchAll('PRAGMA table_info(account_preference)');
        $columnNames = array_map(static fn (array $row): string => (string) $row['name'], $preferenceColumns);
        self::assertContains('mirror_likes_to_atproto', $columnNames);
        self::assertContains('mirror_boosts_to_atproto', $columnNames);
        self::assertContains('mirror_atproto_account_did', $columnNames);

        $publicationColumns = $server->runtime->database->fetchAll('PRAGMA table_info(social_atproto_publication)');
        $publicationColumnNames = array_map(static fn (array $row): string => (string) $row['name'], $publicationColumns);
        self::assertContains('pds_url', $publicationColumnNames);
        self::assertContains('cid', $publicationColumnNames);

        foreach ([
            'social_post',
            'social_post_revision',
            'social_scheduled_post',
        ] as $table) {
            $columns = $server->runtime->database->fetchAll('PRAGMA table_info(' . $table . ')');
            self::assertContains('language', array_column($columns, 'name'), 'Missing language on ' . $table);
        }
    }

    public function testSecondRunIsANoOp(): void
    {
        $server = new TestServer();

        $database = PdoFactory::connect($server->config);
        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());

        self::assertSame([], $runner->migrate());
        self::assertCount(count(Migrations::all()), $runner->applied());
    }

    public function testCoreUpdateMigrationIsAdditiveAndPreservesAccounts(): void
    {
        $server = new TestServer();
        $server->createAccount('keeper');
        $database = $server->runtime->database;
        $before = (int) $database->fetchValue('SELECT COUNT(*) FROM account');

        // Simulate an installation that has not run the newest migration yet.
        $database->execute("DELETE FROM schema_migration WHERE version = '202611160007'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611160007'], $runner->migrate());

        self::assertSame($before, (int) $database->fetchValue('SELECT COUNT(*) FROM account'));
        self::assertSame('keeper', (string) $database->fetchValue('SELECT id FROM account'));
        self::assertSame(
            1,
            (int) $database->fetchValue(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'social_post_pin'",
            ),
        );
    }

    public function testRemoteReplyEdgeMigrationAddsTheCoreColumnToExistingInstalls(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $database->execute('ALTER TABLE social_remote_post DROP COLUMN in_reply_to_id');
        $database->execute("DELETE FROM schema_migration WHERE version = '202611230003'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611230003'], $runner->migrate());

        $columns = $database->fetchAll('PRAGMA table_info(social_remote_post)');
        self::assertContains('in_reply_to_id', array_column($columns, 'name'));
        self::assertSame(
            \AlohaSocial\Server\Migrations\CoreSchemaFingerprint::current(),
            (string) $database->fetchValue(
                "SELECT value FROM runtime_state WHERE name = 'core_schema_fingerprint'",
            ),
        );
        self::assertSame([], $runner->migrate());
    }

    public function testAtprotoPublicationCidMigrationAddsItsColumnWithoutDroppingRows(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $database->execute(
            'INSERT INTO social_atproto_publication '
            . '(local_user_id, account_did, post_id, rkey, uri, published_at, pds_url) '
            . 'VALUES (:user, :did, :post, :rkey, :uri, :at, :pds)',
            [
                'user' => 'alice',
                'did' => 'did:plc:alice',
                'post' => 'post-1',
                'rkey' => '234567abcdefg',
                'uri' => 'at://did:plc:alice/app.bsky.feed.post/234567abcdefg',
                'at' => '2026-11-24T00:00:00Z',
                'pds' => 'https://pds.example',
            ],
        );
        $database->execute('ALTER TABLE social_atproto_publication DROP COLUMN cid');
        $database->execute("DELETE FROM schema_migration WHERE version = '202611250001'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611250001'], $runner->migrate());
        self::assertSame('post-1', (string) $database->fetchValue(
            'SELECT post_id FROM social_atproto_publication WHERE local_user_id = :user',
            ['user' => 'alice'],
        ));
        $columns = $database->fetchAll('PRAGMA table_info(social_atproto_publication)');
        self::assertContains('cid', array_column($columns, 'name'));
        self::assertSame(
            \AlohaSocial\Server\Migrations\CoreSchemaFingerprint::current(),
            (string) $database->fetchValue(
                "SELECT value FROM runtime_state WHERE name = 'core_schema_fingerprint'",
            ),
        );
    }

    public function testAtprotoPublicationIdentityMigrationPreservesAndRekeysExistingRows(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $database->execute(
            'INSERT INTO social_atproto_publication '
            . '(local_user_id, account_did, post_id, rkey, uri, published_at, pds_url, cid) '
            . 'VALUES (:user, :did, :post, :rkey, :uri, :at, :pds, :cid)',
            [
                'user' => 'alice', 'did' => 'did:plc:alice', 'post' => 'post-1',
                'rkey' => '234567abcdefg', 'uri' => 'at://did:plc:alice/app.bsky.feed.post/234567abcdefg',
                'at' => '2026-11-24T00:00:00Z', 'pds' => 'https://pds.example', 'cid' => 'bafy-old',
            ],
        );
        $database->execute('CREATE TABLE social_atproto_publication_legacy ('
            . ' local_user_id VARCHAR(255) NOT NULL, post_id VARCHAR(64) NOT NULL, rkey VARCHAR(13) NOT NULL,'
            . ' uri VARCHAR(512) NOT NULL, cid VARCHAR(256) NULL, pds_url VARCHAR(512) NULL,'
            . ' published_at VARCHAR(20) NOT NULL, PRIMARY KEY (local_user_id, post_id))');
        $database->execute(
            'INSERT INTO social_atproto_publication_legacy '
            . 'SELECT local_user_id, post_id, rkey, uri, cid, pds_url, published_at FROM social_atproto_publication',
        );
        $database->execute('DROP TABLE social_atproto_publication');
        $database->execute('ALTER TABLE social_atproto_publication_legacy RENAME TO social_atproto_publication');
        $database->execute("DELETE FROM schema_migration WHERE version = '202611260001'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611260001'], $runner->migrate());
        $row = $database->fetchAll('SELECT account_did, cid FROM social_atproto_publication WHERE post_id = :post', ['post' => 'post-1'])[0];
        self::assertSame('did:plc:alice', $row['account_did']);
        self::assertSame('bafy-old', $row['cid']);
        $database->execute(
            'INSERT INTO social_atproto_publication '
            . '(local_user_id, account_did, post_id, rkey, uri, published_at) '
            . 'VALUES (:user, :did, :post, :rkey, :uri, :at)',
            [
                'user' => 'alice', 'did' => 'did:plc:alice2', 'post' => 'post-1',
                'rkey' => '234567abcdefh', 'uri' => 'at://did:plc:alice2/app.bsky.feed.post/234567abcdefh',
                'at' => '2026-11-24T00:00:00Z',
            ],
        );
        self::assertSame(2, (int) $database->fetchValue(
            'SELECT COUNT(*) FROM social_atproto_publication WHERE local_user_id = :user AND post_id = :post',
            ['user' => 'alice', 'post' => 'post-1'],
        ));
    }

    public function testPostLanguageMigrationPreservesExistingRows(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $database->execute(
            "INSERT INTO social_post (id, author_id, content, visibility, published_at)"
            . " VALUES ('post-1', 'alice', 'hello', 'public', '2026-11-24T00:00:00Z')",
        );
        $database->execute(
            "INSERT INTO social_post_revision (post_id, revision, content, visibility, attachment_ids, edited_at)"
            . " VALUES ('post-1', 1, 'hello', 'public', '[]', '2026-11-24T00:00:00Z')",
        );
        $database->execute(
            "INSERT INTO social_scheduled_post "
            . '(id, author_id, post_id, content, visibility, attachment_ids, recipient_ids, scheduled_at, created_at, state)'
            . " VALUES ('schedule-1', 'alice', 'post-2', 'later', 'public', '[]', '[]',"
            . " '2026-11-25T00:00:00Z', '2026-11-24T00:00:00Z', 'scheduled')",
        );

        foreach (['social_post', 'social_post_revision', 'social_scheduled_post'] as $table) {
            $database->execute('ALTER TABLE ' . $table . ' DROP COLUMN language');
        }
        $database->execute("DELETE FROM schema_migration WHERE version = '202611250002'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611250002'], $runner->migrate());
        self::assertSame('hello', (string) $database->fetchValue(
            "SELECT content FROM social_post WHERE id = 'post-1'",
        ));
        self::assertSame('hello', (string) $database->fetchValue(
            "SELECT content FROM social_post_revision WHERE post_id = 'post-1'",
        ));
        self::assertSame('later', (string) $database->fetchValue(
            "SELECT content FROM social_scheduled_post WHERE id = 'schedule-1'",
        ));
    }

    public function testFingerprintRefreshMigrationHealsUpgradedInstalls(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;

        // Upgraded installations kept the hash stored before the Core schema
        // statements changed; the refresh migration must rewrite it.
        $database->execute(
            'UPDATE runtime_state SET value = :value WHERE name = :name',
            ['value' => 'stale', 'name' => 'core_schema_fingerprint'],
        );
        $database->execute("DELETE FROM schema_migration WHERE version = '202611280037'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611280037'], $runner->migrate());
        self::assertSame(
            \AlohaSocial\Server\Migrations\CoreSchemaFingerprint::current(),
            (string) $database->fetchValue(
                "SELECT value FROM runtime_state WHERE name = 'core_schema_fingerprint'",
            ),
        );
        self::assertSame([], $runner->migrate());
    }

    public function testCoreSchemaDriftIsReportedBeforeQueriesRun(): void
    {
        $server = new TestServer();
        $server->runtime->database->execute(
            'UPDATE runtime_state SET value = :value WHERE name = :name',
            ['value' => 'stale', 'name' => 'core_schema_fingerprint'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Add a host migration');
        \AlohaSocial\Server\Migrations\CoreSchemaFingerprint::assertCurrent($server->runtime->database);
    }

    public function testConsolidationCopiesLegacyPinsAndArchives(): void
    {
        $server = new TestServer();
        $server->createAccount('alice', 'alice@example.test');
        $post = $server->runtime->app->posts->create(
            new CreatePost('alice', 'Legacy post', Visibility::Public),
        )->id;
        $database = $server->runtime->database;

        $database->execute(
            'CREATE TABLE pinned_post ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' position INTEGER NOT NULL DEFAULT 0,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        $database->execute(
            'CREATE TABLE archived_post ('
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' post_id VARCHAR(64) NOT NULL,'
            . ' archived_at VARCHAR(20) NOT NULL,'
            . ' PRIMARY KEY (account_id, post_id)'
            . ')',
        );
        $database->execute(
            "INSERT INTO pinned_post (account_id, post_id, position, created_at)
             VALUES ('alice', :post, 0, '2026-11-24T12:00:00Z')",
            ['post' => $post],
        );
        $database->execute(
            "INSERT INTO archived_post (account_id, post_id, archived_at)
             VALUES ('alice', :post, '2026-11-24T12:00:00Z')",
            ['post' => $post],
        );
        $database->execute("DELETE FROM schema_migration WHERE version = '202611280007'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611280007'], $runner->migrate());

        self::assertSame(1, (int) $database->fetchValue(
            'SELECT COUNT(*) FROM social_post_pin WHERE owner_id = :owner AND post_id = :post',
            ['owner' => 'alice', 'post' => $post],
        ));
        self::assertSame('2026-11-24T12:00:00Z', (string) $database->fetchValue(
            'SELECT archived_at FROM social_post WHERE id = :post',
            ['post' => $post],
        ));
        self::assertSame(0, (int) $database->fetchValue(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('pinned_post', 'archived_post')",
        ));
    }

    public function testRepairMigrationCleansExistingDeletedStoryViews(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $database->execute(
            'INSERT INTO social_story'
            . ' (id, owner_id, is_local, object_id, caption, attachment_id, remote_media_url,'
            . ' created_at, expires_at, deleted_at)'
            . ' VALUES (:id, :owner, 1, :object_id, :caption, NULL, :media, :created, :expires, :deleted)',
            [
                'id' => 'deleted-host-story',
                'owner' => 'alice',
                'object_id' => 'host-story:deleted-host-story',
                'caption' => 'Already deleted',
                'media' => '/api/v1/host/stories/deleted-host-story/background',
                'created' => '2026-11-26T00:00:00Z',
                'expires' => '2026-11-27T00:00:00Z',
                'deleted' => '2026-11-26T01:00:00Z',
            ],
        );
        $database->execute(
            'INSERT INTO social_story_view (story_id, viewer_id, viewed_at)'
            . ' VALUES (:story, :viewer, :viewed)',
            ['story' => 'deleted-host-story', 'viewer' => 'bob', 'viewed' => '2026-11-26T00:30:00Z'],
        );
        $database->execute("DELETE FROM schema_migration WHERE version = '202611280009'");

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611280009'], $runner->migrate());
        self::assertSame(0, (int) $database->fetchValue(
            'SELECT COUNT(*) FROM social_story_view WHERE story_id = :story',
            ['story' => 'deleted-host-story'],
        ));
    }

    public function testCredentialVersioningMigrationReducesDuplicateResetRows(): void
    {
        $server = new TestServer();
        $server->createAccount();
        $database = $server->runtime->database;

        // Recreate the schema as it existed before migration 014.
        $database->execute('DROP INDEX password_reset_account_unique');
        $database->execute('ALTER TABLE password_reset DROP COLUMN credential_version');
        $database->execute('ALTER TABLE account DROP COLUMN credential_version');
        $database->execute("DELETE FROM schema_migration WHERE version = '202611280014'");

        foreach ([1, 2, 3] as $age) {
            $database->execute(
                'INSERT INTO password_reset (token_hash, account_id, created_at, expires_at, used_at)'
                . ' VALUES (:hash, :account, :created, :expires, NULL)',
                [
                    'hash' => hash('sha256', 'old-token-' . $age),
                    'account' => 'alice',
                    'created' => '2026-11-0' . $age . 'T00:00:00Z',
                    'expires' => '2026-11-0' . ($age + 1) . 'T00:00:00Z',
                ],
            );
        }

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
        self::assertSame(['202611280014'], $runner->migrate());

        self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM password_reset'));
        self::assertSame(
            hash('sha256', 'old-token-3'),
            (string) $database->fetchValue('SELECT token_hash FROM password_reset'),
        );
        self::assertSame(1, (int) $database->fetchValue('SELECT credential_version FROM account'));
    }

    public function testChangedChecksumIsRejected(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;

        $database->execute(
            "UPDATE schema_migration SET checksum = 'not-the-real-checksum' WHERE version = '202611140001'",
        );

        $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());

        $this->expectException(MigrationException::class);
        $runner->migrate();
    }

    public function testAppliedMigrationsRecordCoreProvenance(): void
    {
        $server = new TestServer();

        $row = $server->runtime->database->fetchOne(
            'SELECT core_version, core_reference FROM schema_migration WHERE version = :version',
            ['version' => '202611280023'],
        );
        self::assertNotNull($row);
        self::assertNotSame('', (string) $row['core_version']);
    }

    public function testASecondMigratorFailsWhileAnotherHoldsTheWriteLock(): void
    {
        $server = new TestServer();
        $other = PdoFactory::connect($server->config);
        $migrations = Migrations::all();
        $migrations[] = new ProbeMigration();

        $failure = null;
        // The outer transaction holds SQLite's single-writer lock; the
        // migration run must refuse instead of interleaving DDL.
        $other->transaction(function () use ($server, $migrations, $other, &$failure): void {
            $other->execute('CREATE TABLE migration_lock_probe_holder (id INTEGER)');

            try {
                (new MigrationRunner($server->runtime->database, new SystemClock(), $migrations))->migrate();
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
        });

        self::assertNotNull($failure, 'The second migrator ran while the database was write-locked.');
        self::assertStringContainsString('locked', strtolower($failure->getMessage()));
        self::assertSame(
            0,
            (int) $server->runtime->database->fetchValue(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'migration_lock_probe'",
            ),
        );
    }
}

/** Pending migration used to force a write while another connection holds the lock. */
final class ProbeMigration implements \AlohaSocial\Server\Migrations\Migration
{
    public function version(): string
    {
        return '999999999999';
    }

    public function description(): string
    {
        return 'Test probe.';
    }

    public function up(\AlohaSocial\Core\Platform\Db\Connection $connection): void
    {
        $connection->execute('CREATE TABLE migration_lock_probe (id INTEGER)');
    }

    public function down(\AlohaSocial\Core\Platform\Db\Connection $connection): void
    {
        $connection->execute('DROP TABLE migration_lock_probe');
    }
}
