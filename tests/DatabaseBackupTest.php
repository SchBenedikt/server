<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Support\DatabaseBackup;
use AlohaSocial\Server\Tests\Support\TestServer;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseBackupTest extends TestCase
{
    public function testSqliteBackupIsVerifiedAndRecordsTheAccountCount(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $server->createAccount('bob');

        $backup = (new DatabaseBackup($server->runtime->database, $server->config))->create(
            $server->config->varDirectory . '/backups',
        );

        self::assertFileExists($backup['path']);
        self::assertFileExists($backup['metadata_path']);
        self::assertSame('sqlite', $backup['driver']);
        self::assertSame(2, $backup['accounts']);
        self::assertGreaterThan(0, $backup['bytes']);

        $metadata = json_decode((string) file_get_contents($backup['metadata_path']), true);
        self::assertIsArray($metadata);
        self::assertSame('alohasocial.database-backup', $metadata['format']);
        self::assertSame(2, $metadata['accounts']);

        $copy = new PDO('sqlite:' . $backup['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame(2, (int) $copy->query('SELECT COUNT(*) FROM account')->fetchColumn());
        self::assertSame('ok', $copy->query('PRAGMA quick_check')->fetchColumn());
    }

    public function testRetentionKeepsOnlyTheNewestBackups(): void
    {
        $server = new TestServer();
        $service = new DatabaseBackup($server->runtime->database, $server->config);
        $directory = $server->config->varDirectory . '/backups';

        for ($i = 0; $i < 3; $i++) {
            $service->create($directory, 2);
            usleep(10_000);
        }

        $files = glob($directory . '/alohasocial-*.sqlite');
        self::assertIsArray($files);
        self::assertCount(2, $files);
        self::assertCount(2, glob($directory . '/alohasocial-*.json') ?: []);
    }

    public function testAccountCountIsNullBeforeTheSchemaExists(): void
    {
        $server = new TestServer();
        $server->runtime->database->execute('DROP TABLE account');

        self::assertNull((new DatabaseBackup($server->runtime->database, $server->config))->accountCount());
    }
}
