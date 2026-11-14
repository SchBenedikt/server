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
            'account',
            'session',
            'login_attempt',
            'instance_key',
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
