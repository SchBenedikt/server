<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Support\UpgradePreflight;
use PHPUnit\Framework\TestCase;

final class UpgradePreflightTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alohasocial-preflight-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o770, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (scandir($this->directory) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $path = $this->directory . '/' . $item;
                    is_dir($path) ? $this->removeDirectory($path) : unlink($path);
                }
            }
            rmdir($this->directory);
        }
    }

    public function testWritablePathsAndNoAccountsProduceNoProblems(): void
    {
        $report = UpgradePreflight::check($this->config(), null);

        self::assertSame([], $report['problems']);
        self::assertSame([], $report['warnings']);
    }

    public function testAccountsWithoutAValidSecretKeyWarnButDoNotBlock(): void
    {
        $report = UpgradePreflight::check($this->config(), 3);

        self::assertSame([], $report['problems']);
        self::assertCount(1, $report['warnings']);
        self::assertStringContainsString('secret key', $report['warnings'][0]);
    }

    public function testAnInvalidSecretKeyAlsoWarns(): void
    {
        file_put_contents($this->directory . '/secret.key', 'not-base64!!');
        $report = UpgradePreflight::check($this->config(), 1);

        self::assertCount(1, $report['warnings']);
        self::assertStringContainsString('secret key', $report['warnings'][0]);
    }

    public function testAnUnwritableDataDirectoryIsAProblem(): void
    {
        // A regular file cannot be traversed as a directory.
        file_put_contents($this->directory . '/blocked', 'x');
        $config = HostConfig::load(dirname(__DIR__), $this->overrides($this->directory . '/blocked/var'));

        $report = UpgradePreflight::check($config, null);

        self::assertCount(1, $report['problems']);
        self::assertStringContainsString('not writable', $report['problems'][0]);
    }

    public function testAnUnwritableBlobDirectoryWarns(): void
    {
        file_put_contents($this->directory . '/blocked', 'x');
        $overrides = $this->overrides();
        $overrides['blob_directory'] = $this->directory . '/blocked/blobs';
        $config = HostConfig::load(dirname(__DIR__), $overrides);

        $report = UpgradePreflight::check($config, null);

        self::assertSame([], $report['problems']);
        self::assertCount(1, $report['warnings']);
        self::assertStringContainsString('blob directory', $report['warnings'][0]);
    }

    private function config(): HostConfig
    {
        return HostConfig::load(dirname(__DIR__), $this->overrides());
    }

    /** @return array<string, string|null> */
    private function overrides(?string $varDirectory = null): array
    {
        $var = $varDirectory ?? $this->directory;

        return [
            'instance_url' => 'http://localhost:3000',
            'instance_name' => 'Aloha Test',
            'instance_description' => 'A test instance.',
            'contact_email' => 'admin@example.test',
            'database_dsn' => 'sqlite:' . $var . '/server.sqlite',
            'database_user' => null,
            'database_password' => null,
            'var_directory' => $var,
            'blob_directory' => $var . '/blobs',
            'secret_key_file' => $var . '/secret.key',
            'secret_key' => null,
            'debug' => true,
            'secure_cookies' => false,
            'session_cookie_name' => 'aloha_session',
            'session_lifetime' => 604800,
            'remote_media' => false,
            'mastodon_api' => false,
            'mastodon_prefix' => '/api/mastodon/v1',
            'mail_transport' => 'log',
            'mail_from' => 'no-reply@example.test',
            'mail_log_directory' => $var . '/mail',
            'env_file' => $var . '/.env',
        ];
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
