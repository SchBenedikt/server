<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Server\Config\HostConfig;

use function base64_decode;
use function disk_free_space;
use function is_dir;
use function is_file;
use function is_string;
use function is_writable;
use function strlen;
use function trim;

/**
 * Preflight report for `bin/update.php`.
 *
 * Problems stop the run before the safety backup is attempted; warnings are
 * printed but do not block, because a migration itself may be exactly what an
 * operator needs to repair the reported condition (for example restoring a
 * missing secret key file is unrelated to additive DDL).
 */
final class UpgradePreflight
{
    private const LOW_DISK_BYTES = 512 * 1024 * 1024;

    /**
     * @return array{problems: list<string>, warnings: list<string>}
     */
    public static function check(HostConfig $config, ?int $accounts): array
    {
        $problems = [];
        $warnings = [];

        if (!self::writableDirectory($config->varDirectory)) {
            $problems[] = 'The data directory is not writable: ' . $config->varDirectory;
        }

        if ($config->storageDriver === 'local' && !self::writableDirectory($config->blobDirectory)) {
            $warnings[] = 'The blob directory is not writable; media uploads will fail after the upgrade: '
                . $config->blobDirectory;
        }

        if (($accounts ?? 0) > 0 && !self::secretKeyValid($config)) {
            $warnings[] = 'Accounts exist but the secret key is missing or invalid; the application will not'
                . ' boot until it is restored. Run php bin/doctor.php for details.';
        }

        $free = @disk_free_space($config->varDirectory);
        if ($free !== false && $free < self::LOW_DISK_BYTES) {
            $warnings[] = 'Low disk space in the data directory (' . $free . ' bytes free);'
                . ' the safety backup may not fit.';
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    /**
     * A path that does not exist yet is fine when its parent is writable:
     * the application creates var/ and var/blobs on first boot.
     */
    private static function writableDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);

        return is_dir($parent) && is_writable($parent);
    }

    /**
     * Mirrors bin/doctor.php: the environment value or the key file must
     * decode to exactly 32 bytes. The value itself is never read out.
     */
    private static function secretKeyValid(HostConfig $config): bool
    {
        $encoded = $config->secretKey;
        if ($encoded === null) {
            if (!is_file($config->secretKeyFile)) {
                return false;
            }

            $encoded = file_get_contents($config->secretKeyFile);
            if ($encoded === false) {
                return false;
            }
        }

        $decoded = base64_decode(trim($encoded), true);

        return $decoded !== false && strlen($decoded) === 32;
    }
}
