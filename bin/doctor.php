<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Storage\StorageFactory;
use AlohaSocial\Server\Support\DatabaseBackup;
use AlohaSocial\Server\Support\CoreVersion;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$json = in_array('--json', array_slice($argv, 1), true);

try {
    $config = HostConfig::load($root);
    $database = PdoFactory::connect($config);
    $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
    $backups = new DatabaseBackup($database, $config);

    $installed = $runner->isInstalled();
    $applied = $installed ? $runner->applied() : [];
    $pending = [];
    foreach (Migrations::all() as $migration) {
        if (!isset($applied[$migration->version()])) {
            $pending[] = $migration->version();
        }
    }

    $accounts = $backups->accountCount();
    $keyExists = $config->secretKey !== null || is_file($config->secretKeyFile);
    $keyValid = secretKeyIsValid($config);
    $backupFiles = array_merge(
        glob($config->varDirectory . '/backups/alohasocial-*.sqlite') ?: [],
        glob($config->varDirectory . '/backups/alohasocial-*.dump') ?: [],
    );
    usort($backupFiles, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $databaseFile = str_starts_with($config->databaseDsn, 'sqlite:') ? substr($config->databaseDsn, 7) : null;
    $databaseWritable = $databaseFile === null
        || $databaseFile === ':memory:'
        || (is_file($databaseFile) ? is_writable($databaseFile) : writableDirectory(dirname($databaseFile)));
    $mailWritable = $config->mailTransport !== 'log' || writableDirectory($config->mailLogDirectory);
    $storageAvailable = $config->storageDriver === 'local'
        ? writableDirectory($config->blobDirectory)
        : false;
    if ($config->storageDriver === 's3') {
        try {
            $s3 = StorageFactory::s3Client($config);
            $s3->listObjects((string) $config->s3Bucket, $config->s3Prefix === '' ? '' : $config->s3Prefix . '/', null, 1);
            $storageAvailable = true;
        } catch (Throwable) {
            $storageAvailable = false;
        }
    }

    $report = [
        'root' => $root,
        'instance_url' => $config->instanceUrl,
        'core_version' => CoreVersion::current(),
        'core_reference' => CoreVersion::details()['reference'],
        'driver' => $database->driver(),
        'database' => $backups->databaseLabel(),
        'schema_installed' => $installed,
        'schema_version' => $backups->schemaVersion(),
        'pending_migrations' => $pending,
        'accounts' => $accounts,
        'var_directory' => $config->varDirectory,
        'var_writable' => writableDirectory($config->varDirectory),
        'blob_directory' => $config->blobDirectory,
        'storage_driver' => $config->storageDriver,
        'storage_bucket' => $config->storageDriver === 's3' ? $config->s3Bucket : null,
        'blob_writable' => $storageAvailable,
        'secret_key' => $config->secretKey !== null ? 'environment' : $config->secretKeyFile,
        'secret_key_present' => $keyExists,
        'secret_key_valid' => $keyValid,
        'database_file' => $databaseFile,
        'database_writable' => $databaseWritable,
        'mail_transport' => $config->mailTransport,
        'mail_directory' => $config->mailLogDirectory,
        'mail_writable' => $mailWritable,
        'backup_directory' => $config->varDirectory . '/backups',
        'backup_count' => count($backupFiles),
        'newest_backup' => $backupFiles[0] ?? null,
    ];

    $problems = [];
    if (!$installed) {
        $problems[] = 'The database schema is not installed. Run php bin/update.php.';
    } elseif ($pending !== []) {
        $problems[] = count($pending) . ' migration(s) are pending. Run php bin/update.php.';
    }
    if (($accounts ?? 0) > 0 && !$keyExists) {
        $problems[] = 'Accounts exist but the secret key file is missing; federation identity cannot be decrypted.';
    }
    if ($keyExists && !$keyValid) {
        $problems[] = 'The configured secret key is not 32 bytes, base64 encoded.';
    }
    if (!writableDirectory($config->varDirectory)) {
        $problems[] = 'The var directory is not writable: ' . $config->varDirectory;
    }
    if (!$storageAvailable) {
        $problems[] = $config->storageDriver === 's3'
            ? 'The configured S3-compatible bucket is not reachable.'
            : 'The blob directory is not writable: ' . $config->blobDirectory;
    }
    if (!$databaseWritable) {
        $problems[] = 'The SQLite database is not writable: ' . (string) $databaseFile;
    }
    if (!$mailWritable) {
        $problems[] = 'The mail log directory is not writable: ' . $config->mailLogDirectory;
    }

    $report['problems'] = $problems;
    $report['healthy'] = $problems === [];

    if ($json) {
        fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit($problems === [] ? 0 : 1);
    }

    fwrite(STDOUT, "Aloha Social doctor\n");
    fwrite(STDOUT, "  Instance:       " . $report['instance_url'] . "\n");
    fwrite(STDOUT, "  Core version:   " . $report['core_version'] . "\n");
    fwrite(STDOUT, "  Core commit:    " . ($report['core_reference'] ?? 'unknown') . "\n");
    fwrite(STDOUT, "  Database:       " . $report['database'] . " (" . $report['driver'] . ")\n");
    fwrite(STDOUT, "  Schema:         " . ($installed ? 'installed, ' . ($report['schema_version'] ?? 'unknown') : 'missing') . "\n");
    fwrite(STDOUT, "  Pending:        " . ($pending === [] ? 'none' : implode(', ', $pending)) . "\n");
    fwrite(STDOUT, "  Accounts:       " . ($accounts ?? 'unknown') . "\n");
    fwrite(STDOUT, "  Data directory: " . $config->varDirectory . (writableDirectory($config->varDirectory) ? ' (writable)' : ' (NOT writable)') . "\n");
    $storageLabel = $config->storageDriver === 's3'
        ? 's3://' . $config->s3Bucket
        : $config->blobDirectory;
    fwrite(STDOUT, '  Blobs:          ' . $storageLabel . ($storageAvailable ? ' (available)' : ' (UNAVAILABLE)') . "\n");
    fwrite(STDOUT, "  Secret key:     " . ($config->secretKey !== null ? 'from environment' : $config->secretKeyFile) . ($keyExists ? ($keyValid ? ' (present)' : ' (INVALID)') : ' (MISSING)') . "\n");
    fwrite(STDOUT, "  Database file:  " . ($databaseFile ?? 'server-managed') . ($databaseWritable ? ' (writable)' : ' (NOT writable)') . "\n");
    fwrite(STDOUT, "  Mail:           " . $config->mailTransport . ' -> ' . $config->mailLogDirectory . ($mailWritable ? ' (writable)' : ' (NOT writable)') . "\n");
    fwrite(STDOUT, "  Backups:        " . count($backupFiles) . " in " . $report['backup_directory'] . "\n");

    if ($problems !== []) {
        fwrite(STDERR, "\nProblems:\n");
        foreach ($problems as $problem) {
            fwrite(STDERR, '  - ' . $problem . "\n");
        }
        exit(1);
    }

    fwrite(STDOUT, "\nNo problems detected.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Doctor failed: ' . $exception->getMessage() . "\n");
    exit(2);
}

/**
 * A path that does not exist yet is fine when its parent is writable: the
 * application creates var/ and var/blobs on first boot.
 */
function writableDirectory(string $path): bool
{
    if (is_dir($path)) {
        return is_writable($path);
    }

    $parent = dirname($path);

    return is_dir($parent) && is_writable($parent);
}

/**
 * Reads the environment key or the key file without exposing either value.
 */
function secretKeyIsValid(HostConfig $config): bool
{
    $encoded = $config->secretKey;
    if ($encoded === null) {
        if (!is_file($config->secretKeyFile)) {
            return false;
        }

        $encoded = file_get_contents($config->secretKeyFile);
        if ($encoded === false) {
            return false;
        }
    }

    $decoded = base64_decode(trim($encoded), true);

    return $decoded !== false && strlen($decoded) === 32;
}
