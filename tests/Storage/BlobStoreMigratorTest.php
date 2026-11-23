<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
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
