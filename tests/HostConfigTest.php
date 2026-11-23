<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Config\HostConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostConfigTest extends TestCase
{
    private const KEYS = [
        'ALOHASOCIAL_INSTANCE_URL',
        'ALOHASOCIAL_INSTANCE_NAME',
        'ALOHASOCIAL_ENV_FILE',
        'ALOHASOCIAL_DB_DSN',
        'ALOHASOCIAL_VAR_DIRECTORY',
        'ALOHASOCIAL_BLOB_DIRECTORY',
        'ALOHASOCIAL_SECRET_KEY_FILE',
        'ALOHASOCIAL_MAIL_FROM',
        'ALOHASOCIAL_DEBUG',
        'ALOHASOCIAL_SECURE_COOKIES',
    ];

    /** @var array<string, string|false> */
    private array $previous = [];

    private string $root = '';

    protected function setUp(): void
    {
        $this->previous = [];
        foreach (self::KEYS as $key) {
            $this->previous[$key] = getenv($key);
            putenv($key);
        }

        $this->root = sys_get_temp_dir() . '/alohasocial-config-' . bin2hex(random_bytes(8));
        if (!mkdir($this->root . '/var', 0o770, true) && !is_dir($this->root . '/var')) {
            throw new RuntimeException('Cannot create the configuration test directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }

        self::removeDirectory($this->root);
    }

    public function testSetupEnvFileOverridesTheCheckoutDotEnv(): void
    {
        file_put_contents(
            $this->root . '/.env',
            "ALOHASOCIAL_INSTANCE_URL=http://localhost:3000\nALOHASOCIAL_INSTANCE_NAME=Checkout default\n",
        );
        file_put_contents(
            $this->root . '/var/.env',
            "ALOHASOCIAL_INSTANCE_URL=https://social.example\nALOHASOCIAL_INSTANCE_NAME=Configured instance\n",
        );

        $config = HostConfig::load($this->root, [
            'env_file' => $this->root . '/var/.env',
        ]);

        self::assertSame('https://social.example', $config->instanceUrl);
        self::assertSame('Configured instance', $config->instanceName);
    }

    public function testSetupFileWinsOverComposeProvidedEnvironment(): void
    {
        // Compose forwards the host `.env` as real process variables. The
        // browser setup still has to win for the values it wrote.
        putenv('ALOHASOCIAL_INSTANCE_URL=http://localhost:3000');

        file_put_contents(
            $this->root . '/.env',
            "ALOHASOCIAL_INSTANCE_URL=http://localhost:3000\n",
        );
        file_put_contents(
            $this->root . '/var/.env',
            "ALOHASOCIAL_INSTANCE_URL=https://setup.example\n",
        );

        $config = HostConfig::load($this->root, [
            'env_file' => $this->root . '/var/.env',
        ]);

        self::assertSame('https://setup.example', $config->instanceUrl);
    }

    public function testRealEnvironmentWinsOverTheCheckoutDotEnv(): void
    {
        putenv('ALOHASOCIAL_INSTANCE_URL=https://real.example');

        file_put_contents(
            $this->root . '/.env',
            "ALOHASOCIAL_INSTANCE_URL=http://localhost:3000\n",
        );

        $config = HostConfig::load($this->root);

        self::assertSame('https://real.example', $config->instanceUrl);
    }

    public function testRepeatedLoadSeesAChangedSetupFile(): void
    {
        file_put_contents(
            $this->root . '/var/.env',
            "ALOHASOCIAL_INSTANCE_NAME=Before\n",
        );

        $before = HostConfig::load($this->root, [
            'env_file' => $this->root . '/var/.env',
        ]);
        self::assertSame('Before', $before->instanceName);

        file_put_contents(
            $this->root . '/var/.env',
            "ALOHASOCIAL_INSTANCE_NAME=After\n",
        );

        $after = HostConfig::load($this->root, [
            'env_file' => $this->root . '/var/.env',
        ]);
        self::assertSame('After', $after->instanceName);
    }

    public function testMissingSetupFileFallsBackToTheCheckoutDotEnv(): void
    {
        file_put_contents(
            $this->root . '/.env',
            "ALOHASOCIAL_INSTANCE_URL=https://from-checkout.example\n",
        );

        $config = HostConfig::load($this->root, [
            'env_file' => $this->root . '/var/.env',
        ]);

        self::assertSame('https://from-checkout.example', $config->instanceUrl);
    }

    public function testPublicInstanceRejectsHttpOutsideDevelopmentMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A public instance URL must use HTTPS.');
        HostConfig::load($this->root, ['instance_url' => 'http://social.example']);
    }

    public function testPublicInstanceRequiresSecureCookies(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Secure session cookies are required');
        HostConfig::load($this->root, [
            'instance_url' => 'https://social.example',
            'secure_cookies' => false,
        ]);
    }

    public function testExplicitDevelopmentModeAllowsPublicHttpForLocalTesting(): void
    {
        $config = HostConfig::load($this->root, [
            'instance_url' => 'http://social.example',
            'debug' => true,
        ]);

        self::assertSame('http://social.example', $config->instanceUrl);
        self::assertFalse($config->secureCookies);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
