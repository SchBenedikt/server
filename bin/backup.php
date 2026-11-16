<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Support\DatabaseBackup;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$outputDirectory = null;
$keep = 10;
$json = false;

foreach (array_slice($argv, 1) as $option) {
    if (str_starts_with($option, '--output=')) {
        $outputDirectory = substr($option, 9);
        continue;
    }

    if (str_starts_with($option, '--keep=')) {
        $keep = max(1, (int) substr($option, 7));
        continue;
    }

    if ($option === '--json') {
        $json = true;
        continue;
    }

    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, "Usage: php bin/backup.php [--output=directory] [--keep=10] [--json]\n");
        exit(0);
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

try {
    $config = HostConfig::load($root);
    $database = PdoFactory::connect($config);
    $backup = (new DatabaseBackup($database, $config))->create(
        $outputDirectory ?? $config->varDirectory . '/backups',
        $keep,
    );

    if ($json) {
        fwrite(STDOUT, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(0);
    }

    fwrite(STDOUT, 'Backup written to ' . $backup['path'] . "\n");
    fwrite(STDOUT, 'Accounts in backup: ' . ($backup['accounts'] ?? 'unknown') . "\n");
    fwrite(STDOUT, 'Schema version: ' . ($backup['schema_version'] ?? 'not installed') . "\n");
    fwrite(STDOUT, 'Keep the database and ' . $config->secretKeyFile . " together.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Backup failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
