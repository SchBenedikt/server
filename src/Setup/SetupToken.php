<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Setup;

use RuntimeException;

use function bin2hex;
use function chmod;
use function fclose;
use function fopen;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function hash_equals;
use function is_file;
use function rename;
use function random_bytes;
use function strlen;
use function tempnam;
use function trim;
use function unlink;

/**
 * One-time setup token.
 *
 * The web setup must not be reachable just because the database happens to be
 * empty: on a fresh public host the first visitor would otherwise own the
 * instance. `bin/reset.php` writes this token and prints the matching URL.
 */
final class SetupToken
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function exists(): bool
    {
        $token = $this->read();

        return $token !== null && $token !== '';
    }

    public function generate(): string
    {
        $token = bin2hex(random_bytes(32));
        $temporary = tempnam(dirname($this->path), '.setup-token-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary setup token file.');
        }
        @chmod($temporary, 0o600);
        $contents = $token . "\n";
        if (file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write the setup token: ' . $this->path);
        }

        @chmod($this->path, 0o600);

        return $token;
    }

    /** Serialize setup submissions across PHP workers and processes. */
    public function synchronized(callable $callback): mixed
    {
        $handle = @fopen($this->path . '.lock', 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot open the setup lock file.');
        }
        @chmod($this->path . '.lock', 0o600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire the setup lock.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }

        $value = file_get_contents($this->path);
        if ($value === false) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function verify(string $candidate): bool
    {
        $expected = $this->read();
        if ($expected === null || strlen($candidate) !== strlen($expected)) {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
