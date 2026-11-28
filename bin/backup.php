<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Storage\StorageFactory;
use AlohaSocial\Server\Support\DatabaseBackup;
use AlohaSocial\Server\Support\InstanceManifest;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$outputDirectory = null;
$keep = 10;
$json = false;
$verify = null;

foreach (array_slice($argv, 1) as $option) {
    if (str_starts_with($option, '--output=')) {
        $outputDirectory = substr($option, 9);
        continue;
    }

    if (str_starts_with($option, '--keep=')) {
        $keep = max(1, (int) substr($option, 7));
        continue;
    }

    if (str_starts_with($option, '--verify=')) {
        $verify = substr($option, 9);
        continue;
    }

    if ($option === '--json') {
        $json = true;
        continue;
    }

    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, "Usage: php bin/backup.php [--output=directory] [--keep=10] [--json] [--verify=backup-file]\n");
        exit(0);
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

try {
    $config = HostConfig::load($root);
    $database = PdoFactory::connect($config);
    $service = new DatabaseBackup($database, $config);

    if ($verify !== null) {
        $result = $service->verify($verify);

        if ($json) {
            fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            exit($result['ok'] ? 0 : 1);
        }

        fwrite($result['ok'] ? STDOUT : STDERR, $result['ok']
            ? 'Backup verified: ' . $verify . "\n"
            : "Backup verification failed for {$verify}:\n  - " . implode("\n  - ", $result['problems']) . "\n");
        exit($result['ok'] ? 0 : 1);
    }

    $backup = $service->create(
        $outputDirectory ?? $config->varDirectory . '/backups',
        $keep,
    );

    $manifest = (new InstanceManifest($service, $config, StorageFactory::create($config)->blobStore))
        ->create($backup['path'], $backup['metadata_path']);

    if ($json) {
        $backup['manifest_path'] = $manifest['path'];
        $backup['manifest_objects'] = $manifest['objects'];
        fwrite(STDOUT, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(0);
    }

    fwrite(STDOUT, 'Backup written to ' . $backup['path'] . "\n");
    fwrite(STDOUT, 'Instance manifest: ' . $manifest['path'] . ' (' . $manifest['objects'] . " blobs)\n");
    fwrite(STDOUT, 'Accounts in backup: ' . ($backup['accounts'] ?? 'unknown') . "\n");
    fwrite(STDOUT, 'Schema version: ' . ($backup['schema_version'] ?? 'not installed') . "\n");
    fwrite(STDOUT, 'Keep the database and ' . $config->secretKeyFile . " together.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Backup failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
