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
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class InstanceManifestTest extends TestCase
{
    public function testManifestRecordsDatabaseAndBlobInventory(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $storage->blobStore->put('media/one.jpg', 'first-bytes');
        $storage->blobStore->put('media/two.png', 'second-bytes-longer');

        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $snapshot = $backup->create($server->config->varDirectory . '/backups');

        $result = (new InstanceManifest($backup, $server->config, $storage->blobStore))
            ->create($snapshot['path'], $snapshot['metadata_path']);

        self::assertFileExists($result['path']);
        self::assertSame(2, $result['objects']);
        self::assertSame(strlen('first-bytes') + strlen('second-bytes-longer'), $result['bytes']);

        $manifest = json_decode((string) file_get_contents($result['path']), true);
        self::assertIsArray($manifest);
        self::assertSame('alohasocial.instance-manifest', $manifest['format']);
        self::assertSame($server->config->instanceUrl, $manifest['instance_url']);
        self::assertIsString($manifest['secret_key_fingerprint']);
        self::assertSame('local', $manifest['blobs']['store']);
        self::assertSame('sha256', $manifest['blobs']['hashes']);
        self::assertSame(2, $manifest['blobs']['object_count']);

        $paths = array_column($manifest['blobs']['objects'], 'path');
        sort($paths);
        self::assertSame(['media/one.jpg', 'media/two.png'], $paths);

        $one = $manifest['blobs']['objects'][0]['path'] === 'media/one.jpg'
            ? $manifest['blobs']['objects'][0]
            : $manifest['blobs']['objects'][1];
        self::assertSame(hash('sha256', 'first-bytes'), $one['sha256']);
        self::assertSame(strlen('first-bytes'), $one['bytes']);

        $backupMeta = json_decode((string) file_get_contents($snapshot['metadata_path']), true);
        self::assertSame($backupMeta['sha256'], $manifest['database']['sha256']);
        self::assertSame(basename($snapshot['path']), $manifest['database']['file']);
    }

    public function testVerifyAcceptsAnIntactManifest(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $storage->blobStore->put('media/one.jpg', 'first-bytes');

        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $snapshot = $backup->create($server->config->varDirectory . '/backups');
        $result = (new InstanceManifest($backup, $server->config, $storage->blobStore))
            ->create($snapshot['path'], $snapshot['metadata_path']);

        $check = (new InstanceManifest($backup, $server->config, $storage->blobStore))
            ->verify($result['path']);

        self::assertTrue($check['ok'], implode('; ', $check['problems']));
        self::assertSame(1, $check['objects']);
        self::assertSame(0, $check['unchecked']);
    }

    public function testVerifyDetectsChangedAndMissingBlobs(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $storage->blobStore->put('media/one.jpg', 'first-bytes');
        $storage->blobStore->put('media/two.jpg', 'second-bytes');

        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $snapshot = $backup->create($server->config->varDirectory . '/backups');
        $manifest = new InstanceManifest($backup, $server->config, $storage->blobStore);
        $result = $manifest->create($snapshot['path'], $snapshot['metadata_path']);

        $storage->blobStore->put('media/one.jpg', 'tampered!');
        $storage->blobStore->delete('media/two.jpg');

        $check = $manifest->verify($result['path']);
        self::assertFalse($check['ok']);
        self::assertCount(2, $check['problems']);
    }

    public function testVerifyRejectsAManifestThatIsNotOne(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $path = $server->config->varDirectory . '/not-a-manifest.json';
        file_put_contents($path, '{"hello":"world"}');

        $check = (new InstanceManifest($backup, $server->config, $storage->blobStore))->verify($path);
        self::assertFalse($check['ok']);
    }

    public function testManifestPruningRemovesTheSidecarWithItsBackup(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');

        $storage = StorageFactory::create($server->config);
        $backup = new DatabaseBackup($server->runtime->database, $server->config);
        $directory = $server->config->varDirectory . '/backups';
        $manifest = new InstanceManifest($backup, $server->config, $storage->blobStore);

        for ($i = 0; $i < 3; $i++) {
            $snapshot = $backup->create($directory, 2);
            $manifest->create($snapshot['path'], $snapshot['metadata_path']);
            usleep(10_000);
        }

        self::assertCount(2, glob($directory . '/alohasocial-*.sqlite') ?: []);
        self::assertCount(2, glob($directory . '/alohasocial-*.manifest.json') ?: []);
        // *.json also matches *.manifest.json: two metadata + two manifests.
        self::assertCount(4, glob($directory . '/alohasocial-*.json') ?: []);
    }
}
