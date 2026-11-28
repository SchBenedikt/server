<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Server\Config\HostConfig;
use RuntimeException;

use function chmod;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function tempnam;
use function unlink;

use const LOCK_EX;
use const LOCK_UN;

/**
 * Creates the host encryption key once, even when several workers boot at once.
 *
 * The lock covers both creation and reading. A neighboring temporary file and
 * atomic rename prevent readers from observing a partial key if setup fails.
 */
final class SecretKeyFile
{
    public static function readOrCreate(string $path): string
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the key directory: ' . $directory);
        }

        $lockPath = $path . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the secret key lock: ' . $lockPath);
        }

        @chmod($lockPath, 0o600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the secret key file: ' . $path);
            }

            if (!is_file($path)) {
                self::create($path, $directory);
            }

            $value = file_get_contents($path);
            if ($value === false || trim($value) === '') {
                throw new RuntimeException('The secret key file is empty: ' . $path);
            }

            return trim($value);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * SHA-256 fingerprint of the configured secret key material. The key is
     * random 32 bytes, so its hash cannot be brute-forced; a backup or
     * manifest carrying the fingerprint lets a restore prove it holds the
     * same identity material without exposing the key itself.
     */
    public static function fingerprint(HostConfig $config): ?string
    {
        $encoded = $config->secretKey;
        if ($encoded === null) {
            return self::fingerprintFile($config->secretKeyFile);
        }

        return self::fingerprintEncoded($encoded);
    }

    /**
     * Fingerprint of a key stored at an arbitrary path, e.g. a staged export.
     */
    public static function fingerprintFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $encoded = file_get_contents($path);

        return self::fingerprintEncoded(is_string($encoded) ? $encoded : null);
    }

    private static function fingerprintEncoded(?string $encoded): ?string
    {
        if (!is_string($encoded) || trim($encoded) === '') {
            return null;
        }

        return hash('sha256', trim($encoded));
    }

    private static function create(string $path, string $directory): void
    {
        $temporary = tempnam($directory, '.secret-key-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary secret key file in ' . $directory);
        }

        try {
            $key = OpenSslSecretBox::generateKey() . "\n";
            if (file_put_contents($temporary, $key, LOCK_EX) !== strlen($key)) {
                throw new RuntimeException('Cannot write the secret key file: ' . $path);
            }

            @chmod($temporary, 0o600);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot replace the secret key file: ' . $path);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
