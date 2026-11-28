<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Storage\StorageFactory;
use AlohaSocial\Server\Support\DatabaseBackup;
use AlohaSocial\Server\Support\InstanceExport;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$output = null;
$decrypt = null;
$passphraseFile = null;

foreach (array_slice($argv, 1) as $option) {
    if (str_starts_with($option, '--output=')) {
        $output = substr($option, 9);
        continue;
    }

    if (str_starts_with($option, '--decrypt=')) {
        $decrypt = substr($option, 10);
        continue;
    }

    if (str_starts_with($option, '--passphrase-file=')) {
        $passphraseFile = substr($option, 19);
        continue;
    }

    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, "Usage:\n"
            . "  php bin/export.php --output=FILE   create an encrypted instance export\n"
            . "  php bin/export.php --decrypt=FILE --output=DIR   restore an export\n"
            . "\n"
            . "The passphrase comes from ALOHASOCIAL_EXPORT_PASSPHRASE or --passphrase-file.\n"
            . "Never pass it on the command line.\n");
        exit(0);
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

$passphrase = getenv('ALOHASOCIAL_EXPORT_PASSPHRASE');
if ($passphrase === false || $passphrase === '') {
    $passphrase = null;
}
if ($passphraseFile !== null) {
    $passphrase = is_file($passphraseFile) ? trim((string) file_get_contents($passphraseFile)) : null;
}

try {
    $config = HostConfig::load($root);

    if ($decrypt !== null) {
        if ($output === null) {
            fwrite(STDERR, "--decrypt needs --output=DIR for the restore target.\n");
            exit(2);
        }
        if ($passphrase === null) {
            fwrite(STDERR, "Set ALOHASOCIAL_EXPORT_PASSPHRASE or pass --passphrase-file.\n");
            exit(2);
        }

        $written = InstanceExport::open($decrypt, $passphrase, $output);
        fwrite(STDOUT, 'Restored ' . count($written) . " files to {$output}\n");
        foreach ($written as $path) {
            fwrite(STDOUT, '  ' . $path . "\n");
        }
        exit(0);
    }

    if ($output === null) {
        fwrite(STDERR, "Create needs --output=FILE; decrypt needs --decrypt=FILE --output=DIR.\n");
        exit(2);
    }
    if ($passphrase === null) {
        fwrite(STDERR, "Set ALOHASOCIAL_EXPORT_PASSPHRASE or pass --passphrase-file.\n");
        exit(2);
    }

    $entries = exportEntries($config);
    if ($entries === []) {
        fwrite(STDERR, "Nothing to export: no database backup found in {$config->varDirectory}/backups.\n"
            . "Run php bin/backup.php first.\n");
        exit(1);
    }

    InstanceExport::create($output, $passphrase, $entries);
    fwrite(STDOUT, 'Encrypted export written to ' . $output . ' (' . count($entries) . " files)\n");
    fwrite(STDOUT, "Keep the passphrase separately from the export.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Export failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

/**
 * The newest verified backup set plus the secret key and every local media
 * blob. Remote (S3) media is not materialized into the bundle; copy the
 * bucket separately and record it in the runbook.
 *
 * @return list<array{path: string, source: string}>
 */
function exportEntries(HostConfig $config): array
{
    $entries = [];

    $backups = glob($config->varDirectory . '/backups/alohasocial-*.sqlite')
        ?: glob($config->varDirectory . '/backups/alohasocial-*.dump')
        ?: [];
    if ($backups === []) {
        return [];
    }
    usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $snapshot = $backups[0];
    $base = substr($snapshot, 0, (int) strrpos($snapshot, '.'));

    foreach ([$snapshot, $base . '.json', $base . '.manifest.json'] as $file) {
        if (is_file($file)) {
            $entries[] = ['path' => 'backup/' . basename($file), 'source' => $file];
        }
    }

    if (is_file($config->secretKeyFile)) {
        $entries[] = ['path' => 'secret.key', 'source' => $config->secretKeyFile];
    }

    if ($config->storageDriver === 'local') {
        $store = StorageFactory::create($config)->blobStore;
        foreach ($store->list() as $blobPath) {
            $source = rtrim($config->blobDirectory, '/') . '/' . $blobPath;
            if (is_file($source)) {
                $entries[] = ['path' => 'blobs/' . $blobPath, 'source' => $source];
            }
        }
    }

    return $entries;
}
