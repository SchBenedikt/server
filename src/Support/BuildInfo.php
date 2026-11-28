<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

/**
 * Build provenance for the admin instance view.
 *
 * The server image carries no VCS metadata, so deployments inject
 * `ALOHASOCIAL_BUILD_ID` (the Docker workflow passes the commit SHA). The UI
 * bundle is committed and served as-is, so its content hash identifies the
 * exact frontend build without a separate artifact pipeline.
 */
final class BuildInfo
{
    public static function server(): ?string
    {
        $value = getenv('ALOHASOCIAL_BUILD_ID');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function uiBundle(string $root): ?string
    {
        $file = $root . '/public/ui/app.js';
        if (!is_file($file)) {
            return null;
        }

        $hash = hash_file('sha256', $file);

        return is_string($hash) ? substr($hash, 0, 12) : null;
    }
}
