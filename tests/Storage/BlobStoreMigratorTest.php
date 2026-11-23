<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Platform\Storage\S3BlobStore;
use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;
use AlohaSocial\Core\Platform\Storage\S3ObjectPage;
use AlohaSocial\Server\Storage\BlobStoreMigrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlobStoreMigratorTest extends TestCase
{
    public function testCopiesAndVerifiesBlobsWithoutDeletingTheSource(): void
    {
        $root = sys_get_temp_dir() . '/aloha-media-migration-' . bin2hex(random_bytes(8));
        $sourcePath = $root . '/source';
        $destinationPath = $root . '/destination';
        mkdir($sourcePath, 0o700, true);
        mkdir($destinationPath, 0o700, true);

        try {
            $source = new LocalBlobStore($sourcePath);
            $destination = new LocalBlobStore($destinationPath);
            $source->put('media/photo.jpg', "photo\x00bytes");
            $source->put('media/thumbnail.jpg', 'thumbnail');

            $report = (new BlobStoreMigrator())->copy($source, $destination);

            self::assertSame(['objects' => 2, 'bytes' => 20, 'verified' => true], $report);
            self::assertSame("photo\x00bytes", $destination->read('media/photo.jpg'));
            self::assertSame('thumbnail', $destination->read('media/thumbnail.jpg'));
            self::assertSame("photo\x00bytes", $source->read('media/photo.jpg'));
        } finally {
            self::removeDirectory($root);
        }
    }

    public function testFailedDestinationWriteLeavesSourceDataIntact(): void
    {
        $source = $this->createMock(BlobStore::class);
        $source->method('list')->willReturn(['media/photo.jpg']);
        $source->method('read')->with('media/photo.jpg')->willReturn('photo-bytes');

        $destination = $this->createMock(BlobStore::class);
        $destination->expects(self::once())
            ->method('put')
            ->with('media/photo.jpg', 'photo-bytes')
            ->willThrowException(new RuntimeException('storage offline'));

        try {
            (new BlobStoreMigrator())->copy($source, $destination);
            self::fail('The copy must stop on a destination write failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('storage offline', $exception->getMessage());
        }

        self::assertSame('photo-bytes', $source->read('media/photo.jpg'));
    }

    public function testCopiesLocalBlobsIntoTheS3BlobStoreContract(): void
    {
        $root = sys_get_temp_dir() . '/aloha-media-s3-migration-' . bin2hex(random_bytes(8));
        $sourcePath = $root . '/source';
        mkdir($sourcePath, 0o700, true);
        $client = new InMemoryS3CompatibleClient();

        try {
            $source = new LocalBlobStore($sourcePath);
            $source->put('media/photo.jpg', "photo\x00bytes");
            $destination = new S3BlobStore($client, 'aloha-media-test', 'integration');

            $report = (new BlobStoreMigrator())->copy($source, $destination);

            self::assertSame(['objects' => 1, 'bytes' => 11, 'verified' => true], $report);
            self::assertSame("photo\x00bytes", $destination->read('media/photo.jpg'));
            self::assertSame("photo\x00bytes", $source->read('media/photo.jpg'));
            self::assertSame("photo\x00bytes", $client->objects['integration/media/photo.jpg']);
        } finally {
            self::removeDirectory($root);
        }
    }

    public function testS3WriteFailureStopsMigrationAndRetainsLocalSource(): void
    {
        $root = sys_get_temp_dir() . '/aloha-media-s3-failure-' . bin2hex(random_bytes(8));
        $sourcePath = $root . '/source';
        mkdir($sourcePath, 0o700, true);
        $source = new LocalBlobStore($sourcePath);
        $source->put('media/photo.jpg', 'source-survives');
        $client = new InMemoryS3CompatibleClient();
        $client->failWrites = true;

        try {
            (new BlobStoreMigrator())->copy($source, new S3BlobStore($client, 'aloha-media-test', 'integration'));
            self::fail('An S3 write failure must stop the copy.');
        } catch (\AlohaSocial\Core\Platform\Storage\StorageException) {
            self::assertSame('source-survives', $source->read('media/photo.jpg'));
            self::assertSame([], $client->objects);
        } finally {
            self::removeDirectory($root);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}

/** In-memory S3 port for exercising the real Core S3BlobStore adapter. */
final class InMemoryS3CompatibleClient implements S3CompatibleClient
{
    /** @var array<string, string> */
    public array $objects = [];

    public bool $failWrites = false;

    public function putObject(string $bucket, string $key, mixed $body, int $contentLength, ?string $serverSideEncryption, ?string $kmsKeyId): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('S3 write failed.');
        }
        $contents = stream_get_contents($body);
        if (!is_string($contents) || strlen($contents) !== $contentLength) {
            throw new RuntimeException('Unexpected S3 body length.');
        }
        $this->objects[$key] = $contents;
    }

    public function getObject(string $bucket, string $key, int $maxBytes): mixed
    {
        if (!isset($this->objects[$key])) {
            return null;
        }
        $stream = fopen('php://temp', 'w+b');
        if (!is_resource($stream)) {
            throw new RuntimeException('Cannot create response stream.');
        }
        fwrite($stream, $this->objects[$key]);
        rewind($stream);

        return $stream;
    }

    public function headObject(string $bucket, string $key): ?int
    {
        return isset($this->objects[$key]) ? strlen($this->objects[$key]) : null;
    }

    public function deleteObject(string $bucket, string $key): void
    {
        unset($this->objects[$key]);
    }

    public function listObjects(string $bucket, string $prefix, ?string $continuationToken, int $maxKeys): S3ObjectPage
    {
        $keys = array_values(array_filter(array_keys($this->objects), static fn (string $key): bool => str_starts_with($key, $prefix)));

        return new S3ObjectPage(array_slice($keys, 0, $maxKeys), null);
    }
}
