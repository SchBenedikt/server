<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use RuntimeException;

use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match;
use function rename;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function tempnam;
use function trim;
use function unlink;

/**
 * Small, atomic `.env` writer.
 *
 * It replaces only the keys it is given and keeps comments, order and all
 * unrelated settings. The file is written next to the original and renamed,
 * so a failed write cannot leave a half-written environment behind.
 */
final class EnvFile
{
    /**
     * @param array<string, string> $values
     */
    public static function set(string $path, array $values): void
    {
        $lines = [];
        if (is_file($path)) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Cannot read the configuration file: ' . $path);
            }

            $lines = explode("\n", rtrim($contents, "\n"));
        }

        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException('Invalid environment key: ' . $key);
            }

            $encoded = self::encode($value);
            $found = false;
            foreach ($lines as $index => $line) {
                if (str_starts_with(trim($line), $key . '=')) {
                    $lines[$index] = $key . '=' . $encoded;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $lines[] = $key . '=' . $encoded;
            }
        }

        self::write($path, implode("\n", $lines) . "\n");
    }

    /**
     * Restores a complete snapshot, or removes the file when null.
     *
     * Setup takes this snapshot before it writes anything. If the database
     * transaction rolls back, the exact previous file returns; that is safer
     * than trying to reverse individual keys whose original values or order
     * may have been unknown.
     */
    public static function replace(string $path, ?string $contents): void
    {
        if ($contents === null) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Cannot remove the configuration file: ' . $path);
            }

            return;
        }

        self::write($path, $contents);
    }

    private static function write(string $path, string $body): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the configuration directory: ' . $directory);
        }

        $temporary = tempnam($directory, '.env-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary configuration file in ' . $directory);
        }

        if (file_put_contents($temporary, $body) === false) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write the configuration file: ' . $path);
        }

        @chmod($temporary, 0o600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot replace the configuration file: ' . $path);
        }
    }

    private static function encode(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('An environment value must be a single line.');
        }

        if ($value === '' || preg_match('/^[A-Za-z0-9_@.\/:+,?!() -]+$/u', $value) === 1) {
            return $value;
        }

        if (str_contains($value, "'")) {
            if (str_contains($value, '"')) {
                throw new RuntimeException('An environment value cannot contain both quote characters.');
            }

            return '"' . $value . '"';
        }

        return "'" . $value . "'";
    }
}
