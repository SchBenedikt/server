<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Server\Support\SecretKeyFile;
use PHPUnit\Framework\TestCase;

final class SecretKeyFileTest extends TestCase
{
    public function testCreatesAStablePrivateKeyFileAndLock(): void
    {
        $directory = sys_get_temp_dir() . '/aloha-secret-key-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));
        $path = $directory . '/secret.key';

        try {
            $key = SecretKeyFile::readOrCreate($path);

            OpenSslSecretBox::fromBase64Key($key);
            self::assertSame($key, SecretKeyFile::readOrCreate($path));
            self::assertSame(0o600, fileperms($path) & 0o777);
            self::assertSame(0o600, fileperms($path . '.lock') & 0o777);
        } finally {
            @unlink($path);
            @unlink($path . '.lock');
            @rmdir($directory);
        }
    }
}
