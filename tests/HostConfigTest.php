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
        'ALOHASOCIAL_STORAGE_DRIVER',
        'ALOHASOCIAL_S3_ENDPOINT',
        'ALOHASOCIAL_S3_REGION',
        'ALOHASOCIAL_S3_BUCKET',
        'ALOHASOCIAL_S3_PREFIX',
        'ALOHASOCIAL_S3_ACCESS_KEY',
        'ALOHASOCIAL_S3_SECRET_KEY',
        'ALOHASOCIAL_S3_PATH_STYLE',
        'ALOHASOCIAL_S3_SERVER_SIDE_ENCRYPTION',
        'ALOHASOCIAL_S3_KMS_KEY_ID',
        'ALOHASOCIAL_S3_TIMEOUT',
        'ALOHASOCIAL_S3_MAX_OBJECT_BYTES',
        'ALOHASOCIAL_RUNTIME_ERROR_RETENTION_DAYS',
        'ALOHASOCIAL_SECRET_KEY_FILE',
        'ALOHASOCIAL_MAIL_FROM',
        'ALOHASOCIAL_DEBUG',
        'ALOHASOCIAL_SECURE_COOKIES',
        'ALOHASOCIAL_TRUSTED_PROXIES',
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

    public function testTrustedProxiesCanBeConfiguredByEnvironmentOrOverride(): void
    {
        $config = HostConfig::load($this->root, [
            'instance_url' => 'https://social.example',
            'trusted_proxies' => '127.0.0.1, 10.0.0.0/8',
        ]);

        self::assertSame(['127.0.0.1', '10.0.0.0/8'], $config->trustedProxies);
    }

    public function testInvalidTrustedProxyRangeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid IP or CIDR');
        HostConfig::load($this->root, [
            'instance_url' => 'https://social.example',
            'trusted_proxies' => '10.0.0.0/33',
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

    public function testLocalStorageRemainsTheDefault(): void
    {
        $config = HostConfig::load($this->root);

        self::assertSame('local', $config->storageDriver);
        self::assertNull($config->s3Bucket);
        self::assertSame('AES256', $config->s3ServerSideEncryption);
    }

    public function testS3StorageAcceptsValidatedRuntimeConfiguration(): void
    {
        $config = HostConfig::load($this->root, [
            'instance_url' => 'https://social.example',
            'storage_driver' => 's3',
            's3_endpoint' => 'https://objects.example.test',
            's3_region' => 'eu-central-1',
            's3_bucket' => 'aloha-media-prod',
            's3_prefix' => 'aloha/social',
            's3_access_key' => 'access-secret-id',
            's3_secret_key' => 'private-secret-value',
            's3_path_style' => true,
            's3_server_side_encryption' => 'aws:kms',
            's3_kms_key_id' => 'alias/aloha-media',
            's3_timeout' => 15,
            's3_max_object_bytes' => 104857600,
        ]);

        self::assertSame('s3', $config->storageDriver);
        self::assertSame('https://objects.example.test', $config->s3Endpoint);
        self::assertSame('eu-central-1', $config->s3Region);
        self::assertSame('aloha-media-prod', $config->s3Bucket);
        self::assertSame('aloha/social', $config->s3Prefix);
        self::assertSame('access-secret-id', $config->s3AccessKey);
        self::assertSame('private-secret-value', $config->s3SecretKey);
        self::assertTrue($config->s3PathStyle);
        self::assertSame('aws:kms', $config->s3ServerSideEncryption);
        self::assertSame('alias/aloha-media', $config->s3KmsKeyId);
        self::assertSame(15, $config->s3TimeoutSeconds);
        self::assertSame(104857600, $config->s3MaxObjectBytes);
    }

    public function testRuntimeErrorRetentionCanBeConfiguredAndRejectsUnboundedValues(): void
    {
        $config = HostConfig::load($this->root, ['runtime_error_retention_days' => 365]);
        self::assertSame(365, $config->runtimeErrorRetentionDays);

        try {
            HostConfig::load($this->root, ['runtime_error_retention_days' => 0]);
            self::fail('A zero-day retention window must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('between 1 and 3650 days', $exception->getMessage());
        }
    }

    public function testS3StorageRequiresABucket(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALOHASOCIAL_S3_BUCKET');
        HostConfig::load($this->root, [
            'storage_driver' => 's3',
        ]);
    }

    public function testS3StorageRejectsInsecureProductionEndpoint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must use HTTPS');
        HostConfig::load($this->root, [
            'instance_url' => 'https://social.example',
            'storage_driver' => 's3',
            's3_endpoint' => 'http://minio:9000',
            's3_bucket' => 'aloha-media-prod',
        ]);
    }

    public function testS3StorageRejectsCredentialPairAndUnsafePrefixErrors(): void
    {
        try {
            HostConfig::load($this->root, [
                'storage_driver' => 's3',
                's3_bucket' => 'aloha-media-prod',
                's3_access_key' => 'access-only',
            ]);
            self::fail('An incomplete credential pair must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must be set together', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('safe relative key prefix');
        HostConfig::load($this->root, [
            'storage_driver' => 's3',
            's3_bucket' => 'aloha-media-prod',
            's3_prefix' => '../shared',
        ]);
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
