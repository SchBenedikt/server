<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\SystemClock;
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
            'social_search_index',
            'social_recommendation_preference',
            'social_account_deletion',
            'social_pds_identity',
            'social_remote_reply',
            'atproto_session',
            'account',
            'session',
            'login_attempt',
            'instance_key',
            'kokua',
            'kokua_help',
            'runtime_error',
        ] as $expected) {
            self::assertContains($expected, $tables, 'Missing table: ' . $expected);
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
}
