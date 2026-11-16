<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Version;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Support\DatabaseBackup;

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
    $backupFiles = glob($config->varDirectory . '/backups/alohasocial-*.sqlite')
        ?: glob($config->varDirectory . '/backups/alohasocial-*.dump')
        ?: [];

    $report = [
        'root' => $root,
        'instance_url' => $config->instanceUrl,
        'core_version' => Version::CURRENT,
        'driver' => $database->driver(),
        'database' => $backups->databaseLabel(),
        'schema_installed' => $installed,
        'schema_version' => $backups->schemaVersion(),
        'pending_migrations' => $pending,
        'accounts' => $accounts,
        'var_directory' => $config->varDirectory,
        'var_writable' => writableDirectory($config->varDirectory),
        'blob_directory' => $config->blobDirectory,
        'blob_writable' => writableDirectory($config->blobDirectory),
        'secret_key' => $config->secretKey !== null ? 'environment' : $config->secretKeyFile,
        'secret_key_present' => $keyExists,
        'backup_directory' => $config->varDirectory . '/backups',
        'backup_count' => count($backupFiles),
        'newest_backup' => $backupFiles === [] ? null : max($backupFiles),
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
    if (!writableDirectory($config->varDirectory)) {
        $problems[] = 'The var directory is not writable: ' . $config->varDirectory;
    }
    if (!writableDirectory($config->blobDirectory)) {
        $problems[] = 'The blob directory is not writable: ' . $config->blobDirectory;
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
    fwrite(STDOUT, "  Database:       " . $report['database'] . " (" . $report['driver'] . ")\n");
    fwrite(STDOUT, "  Schema:         " . ($installed ? 'installed, ' . ($report['schema_version'] ?? 'unknown') : 'missing') . "\n");
    fwrite(STDOUT, "  Pending:        " . ($pending === [] ? 'none' : implode(', ', $pending)) . "\n");
    fwrite(STDOUT, "  Accounts:       " . ($accounts ?? 'unknown') . "\n");
    fwrite(STDOUT, "  Data directory: " . $config->varDirectory . (writableDirectory($config->varDirectory) ? ' (writable)' : ' (NOT writable)') . "\n");
    fwrite(STDOUT, "  Blobs:          " . $config->blobDirectory . (writableDirectory($config->blobDirectory) ? ' (writable)' : ' (NOT writable)') . "\n");
    fwrite(STDOUT, "  Secret key:     " . ($config->secretKey !== null ? 'from environment' : $config->secretKeyFile) . ($keyExists ? ' (present)' : ' (MISSING)') . "\n");
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
