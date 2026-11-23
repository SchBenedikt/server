<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Version;
use Composer\InstalledVersions;
use Throwable;

/**
 * The installed core version.
 *
 * Prefer the Composer lock's package version and commit reference. The source
 * constant is only a fallback for unusual bootstrap/test assemblies.
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

        return Version::CURRENT;
    }

    /**
     * @return array{version: string, reference: ?string}
     */
    public static function details(): array
    {
        $reference = null;

        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('alohasocial/core')) {
                $candidate = InstalledVersions::getReference('alohasocial/core');
                $reference = is_string($candidate) && $candidate !== '' ? $candidate : null;
            }
        } catch (Throwable) {
            // The source version remains useful when Composer metadata is absent.
        }

        return ['version' => self::current(), 'reference' => $reference];
    }
}
