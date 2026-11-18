<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The host release is announced in three places. A release bump that forgets
 * one of them is caught here instead of drifting silently.
 */
final class VersionTest extends TestCase
{
    public function testReleaseVersionIsConsistent(): void
    {
        $root = dirname(__DIR__);

        $version = trim((string) file_get_contents($root . '/VERSION'));
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);

        $ui = json_decode((string) file_get_contents($root . '/ui/package.json'), true);
        self::assertIsArray($ui);
        self::assertSame($version, (string) ($ui['version'] ?? ''));

        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        self::assertSame(1, preg_match('/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/m', $changelog, $match));
        self::assertSame($version, $match[1]);
    }
}
