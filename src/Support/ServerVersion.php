<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use function dirname;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

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

        $file = dirname(__DIR__, 2) . '/composer.json';
        if (is_file($file)) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data) && is_string($data['version'] ?? null) && $data['version'] !== '') {
                    return self::$cached = $data['version'];
                }
            } catch (\Throwable) {
                // Fall through to a stable, honest placeholder.
            }
        }

        return self::$cached = '0.0.0';
    }
}
