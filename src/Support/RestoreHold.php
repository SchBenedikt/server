<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Server\Config\HostConfig;

use function date;
use function file_put_contents;
use function is_file;
use function json_encode;
use function rtrim;
use function unlink;

/**
 * Marker that pauses the worker after a restore until an operator confirms.
 *
 * While `var/restore.pending` exists the worker loop skips every tick without
 * writing its heartbeat, so dashboards show the worker as stale and no
 * outbound federation or mail delivery happens on top of freshly restored
 * state. Inbound web requests are unaffected; jobs simply queue.
 */
final class RestoreHold
{
    public static function path(HostConfig $config): string
    {
        return rtrim($config->varDirectory, '/') . '/restore.pending';
    }

    public static function active(HostConfig $config): bool
    {
        return is_file(self::path($config));
    }

    public static function engage(HostConfig $config, string $detail): void
    {
        $path = self::path($config);
        $payload = json_encode(['created_at' => date('c'), 'detail' => $detail]);
        if ($payload !== false) {
            @file_put_contents($path, $payload . "\n", LOCK_EX);
        }
    }

    public static function release(HostConfig $config): bool
    {
        $path = self::path($config);
        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }
}
