<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Storage\StorageFactory;
use AlohaSocial\Server\Support\DatabaseBackup;
use AlohaSocial\Server\Support\InstanceManifest;
use AlohaSocial\Server\Support\InstanceRestore;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class InstanceRestoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/restore-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/staging/backup', 0o770, true);
        mkdir($this->directory . '/staging/blobs', 0o770, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testPreflightPassesForAnIntactExport(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $storage->blobStore->put('media/one.jpg', 'first-bytes');

        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $snapshot = $backup->create($server->config->varDirectory . '/backups');
        $manifest = new InstanceManifest($backup, $server->config, $storage->blobStore)
            ->create($snapshot['path'], $snapshot['metadata_path']);

        $this->stage($server, $snapshot, $manifest['path']);

        $report = (new InstanceRestore($server->runtime->database, $server->config))
            ->preflight($this->directory . '/staging');

        self::assertTrue($report['ok'], json_encode($report['checks']));
        self::assertSame(['manifest', 'database', 'blobs', 'secret_key', 'origin', 'schema'], array_column($report['checks'], 'name'));
    }

    public function testPreflightDetectsATamperedBlob(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $storage->blobStore->put('media/one.jpg', 'first-bytes');

        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $snapshot = $backup->create($server->config->varDirectory . '/backups');
        $manifest = new InstanceManifest($backup, $server->config, $storage->blobStore)
            ->create($snapshot['path'], $snapshot['metadata_path']);

        $this->stage($server, $snapshot, $manifest['path']);
        file_put_contents($this->directory . '/staging/blobs/media/one.jpg', 'tampered!');

        $report = (new InstanceRestore($server->runtime->database, $server->config))
            ->preflight($this->directory . '/staging');

        self::assertFalse($report['ok']);
        $blobs = array_values(array_filter($report['checks'], static fn ($c) => $c['name'] === 'blobs'))[0];
        self::assertFalse($blobs['ok']);
    }

    public function testPreflightRejectsADifferentSecretKey(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $snapshot = $backup->create($server->config->varDirectory . '/backups');
        $manifest = new InstanceManifest($backup, $server->config, $storage->blobStore)
            ->create($snapshot['path'], $snapshot['metadata_path']);

        $this->stage($server, $snapshot, $manifest['path']);

        // Rewrite the staged manifest with a foreign key fingerprint.
        $staged = glob($this->directory . '/staging/backup/*.manifest.json');
        $data = json_decode((string) file_get_contents($staged[0]), true);
        $data['secret_key_fingerprint'] = hash('sha256', 'a-different-key');
        file_put_contents($staged[0], json_encode($data));

        $report = (new InstanceRestore($server->runtime->database, $server->config))
            ->preflight($this->directory . '/staging');

        self::assertFalse($report['ok']);
        $key = array_values(array_filter($report['checks'], static fn ($c) => $c['name'] === 'secret_key'))[0];
        self::assertFalse($key['ok']);
    }

    public function testPreflightRejectsAForeignOrigin(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $snapshot = $backup->create($server->config->varDirectory . '/backups');
        $manifest = new InstanceManifest($backup, $server->config, $storage->blobStore)
            ->create($snapshot['path'], $snapshot['metadata_path']);

        $this->stage($server, $snapshot, $manifest['path']);

        $staged = glob($this->directory . '/staging/backup/*.manifest.json');
        $data = json_decode((string) file_get_contents($staged[0]), true);
        $data['instance_url'] = 'https://other.example';
        file_put_contents($staged[0], json_encode($data));

        $report = (new InstanceRestore($server->runtime->database, $server->config))
            ->preflight($this->directory . '/staging');

        self::assertFalse($report['ok']);
        $origin = array_values(array_filter($report['checks'], static fn ($c) => $c['name'] === 'origin'))[0];
        self::assertFalse($origin['ok']);
    }

    public function testPreflightFailsWithoutAManifest(): void
    {
        $server = new TestServer();

        $report = (new InstanceRestore($server->runtime->database, $server->config))
            ->preflight($this->directory . '/staging');

        self::assertFalse($report['ok']);
        self::assertSame('manifest', $report['checks'][0]['name']);
    }

    /**
     * Copies the export layout (backup/…, blobs/…) into the staging dir.
     */
    private function stage(TestServer $server, array $snapshot, string $manifestPath): void
    {
        $backupDir = $this->directory . '/staging/backup';

        foreach ([$snapshot['path'], $snapshot['metadata_path'], $manifestPath] as $file) {
            copy($file, $backupDir . '/' . basename($file));
        }

        $storage = StorageFactory::create($server->config);
        foreach ($storage->blobStore->list() as $blobPath) {
            $source = rtrim($server->config->blobDirectory, '/') . '/' . $blobPath;
            $target = $this->directory . '/staging/blobs/' . $blobPath;
            @mkdir(dirname($target), 0o770, true);
            copy($source, $target);
        }
    }
}
