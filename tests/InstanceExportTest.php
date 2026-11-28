<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Support\InstanceExport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstanceExportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/export-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o770, true);
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

    public function testRoundTripRestoresEveryEntry(): void
    {
        $source = $this->directory . '/src';
        mkdir($source . '/nested', 0o770, true);
        file_put_contents($source . '/dump.sqlite', 'database-bytes');
        file_put_contents($source . '/nested/blob.bin', random_bytes(2_500_000));
        file_put_contents($source . '/empty.txt', '');

        $export = $this->directory . '/instance.alohasocial-export';
        InstanceExport::create($export, 'correct horse battery staple', [
            ['path' => 'backup/dump.sqlite', 'source' => $source . '/dump.sqlite'],
            ['path' => 'blobs/nested/blob.bin', 'source' => $source . '/nested/blob.bin'],
            ['path' => 'empty.txt', 'source' => $source . '/empty.txt'],
        ]);

        self::assertFileExists($export);
        self::assertStringStartsWith('ALOEXPORT1', substr((string) file_get_contents($export), 0, 10));
        self::assertStringNotContainsString('database-bytes', (string) file_get_contents($export));

        $target = $this->directory . '/restored';
        $written = InstanceExport::open($export, 'correct horse battery staple', $target);

        sort($written);
        self::assertSame(['backup/dump.sqlite', 'blobs/nested/blob.bin', 'empty.txt'], $written);
        self::assertSame('database-bytes', file_get_contents($target . '/backup/dump.sqlite'));
        self::assertSame(file_get_contents($source . '/nested/blob.bin'), file_get_contents($target . '/blobs/nested/blob.bin'));
        self::assertSame('', file_get_contents($target . '/empty.txt'));
    }

    public function testWrongPassphraseFailsAuthentication(): void
    {
        $source = $this->directory . '/src';
        mkdir($source, 0o770, true);
        file_put_contents($source . '/a.txt', 'secret');

        $export = $this->directory . '/instance.export';
        InstanceExport::create($export, 'right', [['path' => 'a.txt', 'source' => $source . '/a.txt']]);

        $this->expectException(RuntimeException::class);
        InstanceExport::open($export, 'wrong', $this->directory . '/out');
    }

    public function testCorruptedExportFailsAuthentication(): void
    {
        $source = $this->directory . '/src';
        mkdir($source, 0o770, true);
        file_put_contents($source . '/a.txt', 'secret');

        $export = $this->directory . '/instance.export';
        InstanceExport::create($export, 'pw', [['path' => 'a.txt', 'source' => $source . '/a.txt']]);

        $bytes = (string) file_get_contents($export);
        $bytes[strlen($bytes) - 30] = $bytes[strlen($bytes) - 30] === 'A' ? 'B' : 'A';
        file_put_contents($export, $bytes);

        $this->expectException(RuntimeException::class);
        InstanceExport::open($export, 'pw', $this->directory . '/out');
    }

    public function testNonExportFileIsRejected(): void
    {
        $file = $this->directory . '/random.bin';
        file_put_contents($file, 'not an export');

        $this->expectException(RuntimeException::class);
        InstanceExport::open($file, 'pw', $this->directory . '/out');
    }
}
