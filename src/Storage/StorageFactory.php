<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Platform\Storage\S3BlobStore;
use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;
use AlohaSocial\Server\Config\HostConfig;
use Aws\S3\S3Client;
use RuntimeException;

use function is_dir;
use function mkdir;
use function min;

/** Selects and configures the host's media storage backend. */
final class StorageFactory
{
    public static function create(HostConfig $config, ?S3CompatibleClient $clientOverride = null): HostStorage
    {
        if ($config->storageDriver === 'local') {
            return new HostStorage(self::local($config->blobDirectory), null);
        }

        return self::s3($config, $clientOverride);
    }

    public static function s3(HostConfig $config, ?S3CompatibleClient $clientOverride = null): HostStorage
    {
        $client = $clientOverride ?? self::s3Client($config);
        $store = new S3BlobStore(
            $client,
            (string) $config->s3Bucket,
            $config->s3Prefix,
            $config->s3ServerSideEncryption,
            $config->s3KmsKeyId,
            $config->s3MaxObjectBytes,
        );

        return new HostStorage($store, $client);
    }

    public static function local(string $directory): BlobStore
    {
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the blob directory: ' . $directory);
        }

        return new LocalBlobStore($directory);
    }

    public static function s3Client(HostConfig $config): S3CompatibleClient
    {
        $options = [
            'version' => 'latest',
            'region' => $config->s3Region,
            'use_path_style_endpoint' => $config->s3PathStyle,
            'retries' => 2,
            'http' => [
                'connect_timeout' => min(5, $config->s3TimeoutSeconds),
                'timeout' => $config->s3TimeoutSeconds,
            ],
        ];
        if ($config->s3Endpoint !== null) {
            $options['endpoint'] = $config->s3Endpoint;
        }
        if ($config->s3AccessKey !== null && $config->s3SecretKey !== null) {
            $options['credentials'] = [
                'key' => $config->s3AccessKey,
                'secret' => $config->s3SecretKey,
            ];
        }

        return new AwsS3CompatibleClient(new S3Client($options));
    }
}
