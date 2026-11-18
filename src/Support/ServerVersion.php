<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use function dirname;
use function is_file;
use function preg_match;
use function trim;

/**
 * The standalone host version, not the Core dependency version.
 *
 * NodeInfo advertises software an external service can recognise; reporting
 * the Core version there made the server look like a different release.
 */
final class ServerVersion
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $file = dirname(__DIR__, 2) . '/VERSION';
        if (is_file($file)) {
            $version = trim((string) file_get_contents($file));
            if (preg_match('/^\\d+\\.\\d+\\.\\d+$/', $version) === 1) {
                return self::$cached = $version;
            }
        }

        return self::$cached = '0.0.0';
    }
}
