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
        self::assertIsString($metadata['sha256']);
        self::assertSame(hash_file('sha256', $backup['path']), $metadata['sha256']);
        self::assertIsString($metadata['core_version']);
        self::assertIsString($metadata['key_fingerprint']);

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

    public function testVerifyAcceptsAnIntactBackupAndRejectsCorruption(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $service = new DatabaseBackup($server->runtime->database, $server->config);
        $backup = $service->create($server->config->varDirectory . '/backups');

        $result = $service->verify($backup['path']);
        self::assertTrue($result['ok'], implode('; ', $result['problems']));
        self::assertSame('sqlite', $result['metadata']['driver']);

        $corrupt = $backup['path'] . '.copy';
        copy($backup['path'], $corrupt);
        copy($backup['metadata_path'], $corrupt . 'x');
        rename($corrupt . 'x', substr($corrupt, 0, (int) strrpos($corrupt, '.')) . '.json');
        file_put_contents($corrupt, 'x', FILE_APPEND);

        $failed = $service->verify($corrupt);
        self::assertFalse($failed['ok']);
        self::assertNotEmpty($failed['problems']);

        @unlink($corrupt);
        @unlink(substr($corrupt, 0, (int) strrpos($corrupt, '.')) . '.json');
    }

    public function testVerifyReportsABackupThatPredatesIntegrityTracking(): void
    {
        $server = new TestServer();
        $service = new DatabaseBackup($server->runtime->database, $server->config);
        $backup = $service->create($server->config->varDirectory . '/backups');

        $metadataPath = $backup['metadata_path'];
        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        unset($metadata['sha256']);
        file_put_contents($metadataPath, json_encode($metadata));

        $result = $service->verify($backup['path']);
        self::assertFalse($result['ok']);
    }

    public function testVerifyFailsWhenTheMetadataIsMissing(): void
    {
        $server = new TestServer();
        $service = new DatabaseBackup($server->runtime->database, $server->config);
        $backup = $service->create($server->config->varDirectory . '/backups');

        unlink($backup['metadata_path']);

        $result = $service->verify($backup['path']);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('metadata', implode(' ', $result['problems']));
    }

    public function testAccountCountIsNullBeforeTheSchemaExists(): void
    {
        $server = new TestServer();
        $server->runtime->database->execute('DROP TABLE account');

        self::assertNull((new DatabaseBackup($server->runtime->database, $server->config))->accountCount());
    }
}
