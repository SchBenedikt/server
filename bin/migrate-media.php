<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Storage\BlobStoreMigrator;
use AlohaSocial\Server\Storage\StorageFactory;
use AlohaSocial\Core\Platform\Storage\BlobStore;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$from = null;
$to = null;
$verify = true;

foreach (array_slice($argv, 1) as $option) {
    if (str_starts_with($option, '--from=')) {
        $from = substr($option, 7);
        continue;
    }
    if (str_starts_with($option, '--to=')) {
        $to = substr($option, 5);
        continue;
    }
    if ($option === '--no-verify') {
        $verify = false;
        continue;
    }
    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, "Usage: php bin/migrate-media.php --from=s3|local:PATH --to=s3|local:PATH [--no-verify]\n\n");
        fwrite(STDOUT, "Copies blobs without deleting the source. Stop web and worker processes before copying.\n");
        fwrite(STDOUT, "For s3, the configured ALOHASOCIAL_S3_* credentials, bucket, prefix and endpoint are used.\n");
        exit(0);
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

if ($from === null || $to === null || $from === $to) {
    fwrite(STDERR, "Provide distinct --from and --to stores. See --help.\n");
    exit(2);
}

try {
    $config = HostConfig::load($root);
    $source = store($from, $config, $root, true);
    $destination = store($to, $config, $root, false);
    $report = (new BlobStoreMigrator())->copy($source, $destination, $verify);

    fwrite(STDOUT, 'Copied ' . $report['objects'] . ' media object(s), ' . $report['bytes'] . " bytes.\n");
    fwrite(STDOUT, 'Source objects were retained. Verification: ' . ($report['verified'] ? 'SHA-256 passed' : 'disabled') . ".\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Media migration stopped: ' . $exception->getMessage() . "\n");
    exit(1);
}

function store(string $specification, HostConfig $config, string $root, bool $source): BlobStore
{
    if ($specification === 's3') {
        return StorageFactory::s3($config)->blobStore;
    }

    if (!str_starts_with($specification, 'local:')) {
        throw new InvalidArgumentException('A store must be s3 or local:PATH.');
    }
    $path = substr($specification, 6);
    if ($path === '') {
        throw new InvalidArgumentException('A local store needs a directory path.');
    }
    if (!str_starts_with($path, '/')) {
        $path = rtrim($root, '/') . '/' . $path;
    }
    if ($source && !is_dir($path)) {
        throw new InvalidArgumentException('The source directory does not exist: ' . $path);
    }

    return StorageFactory::local($path);
}
