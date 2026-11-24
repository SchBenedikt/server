<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Support\CoreVersion;
use AlohaSocial\Core\Version;
use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

final class CoreVersionTest extends TestCase
{
    public function testHostCompatibilityContractTargetsCore08(): void
    {
        self::assertSame(
            '0.8.0',
            Version::CURRENT,
            'Update the host compatibility and upgrade matrix when changing the supported Core line.',
        );
    }

    public function testDiagnosticsReportComposerVersionAndLockedReference(): void
    {
        self::assertTrue(InstalledVersions::isInstalled('alohasocial/core'));
        $details = CoreVersion::details();

        self::assertSame(InstalledVersions::getPrettyVersion('alohasocial/core'), $details['version']);
        self::assertSame(InstalledVersions::getReference('alohasocial/core'), $details['reference']);
        self::assertIsString($details['reference']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $details['reference']);
    }
}
