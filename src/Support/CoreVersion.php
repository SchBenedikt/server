<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * The installed core version.
 *
 * The core's `Version::CURRENT` constant is newer than the pinned `0.7.x`
 * line, so the host reads the Composer-installed version instead of guessing.
 */
final class CoreVersion
{
    public static function current(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('alohasocial/core')) {
                $version = InstalledVersions::getPrettyVersion('alohasocial/core');

                if (is_string($version) && $version !== '') {
                    return $version;
                }
            }
        } catch (Throwable) {
            // Fall through to the neutral answer.
        }

        return '0.7.x';
    }
}
