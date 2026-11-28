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
use AlohaSocial\Server\Support\DatabaseBackup;
use AlohaSocial\Server\Support\UpgradePreflight;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$dryRun = false;
$quiet = false;
$noBackup = false;
$allowUnbacked = false;
$backupDirectory = null;
$keep = 10;

foreach (array_slice($argv, 1) as $option) {
    if ($option === '--dry-run') {
        $dryRun = true;
        continue;
    }

    if ($option === '--quiet' || $option === '-q') {
        $quiet = true;
        continue;
    }

    if ($option === '--no-backup') {
        $noBackup = true;
        continue;
    }

    if ($option === '--allow-unbacked') {
        $allowUnbacked = true;
        continue;
    }

    if (str_starts_with($option, '--backup-dir=')) {
        $backupDirectory = substr($option, 13);
        continue;
    }

    if (str_starts_with($option, '--keep=')) {
        $keep = max(1, (int) substr($option, 7));
        continue;
    }

    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, <<<TEXT
Usage: php bin/update.php [options]

Applies pending migrations. When accounts exist, a verified database backup
is created first and the account count is compared before and after.

Options:
  --dry-run           Show pending migrations without changing anything
  --quiet             Only print warnings and errors
  --no-backup         Do not create a backup (not recommended)
  --allow-unbacked    Continue when a backup cannot be created
  --backup-dir=DIR    Backup directory (default: var/backups)
  --keep=N            Keep the newest N backups (default: 10)

TEXT);
        exit(0);
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $message . "\n");
    }
};

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

    if ($pending === []) {
        $say('Nothing to migrate.');
        $say('Accounts: ' . ($backups->accountCount() ?? 'unknown'));
        exit(0);
    }

    $before = $backups->accountCount();

    if ($dryRun) {
        fwrite(STDOUT, "Pending migrations:\n");
        foreach ($pending as $version) {
            fwrite(STDOUT, '  - ' . $version . "\n");
        }
        fwrite(STDOUT, 'Accounts before: ' . ($before ?? 'not installed') . "\n");
        exit(0);
    }

    $preflight = UpgradePreflight::check($config, $before);
    foreach ($preflight['warnings'] as $warning) {
        fwrite(STDERR, 'Warning: ' . $warning . "\n");
    }
    if ($preflight['problems'] !== []) {
        fwrite(STDERR, "Migration stopped: preflight checks failed.\n");
        foreach ($preflight['problems'] as $problem) {
            fwrite(STDERR, '  - ' . $problem . "\n");
        }
        exit(1);
    }

    $backup = null;
    $backupError = null;

    if (!$noBackup && ($before ?? 0) > 0) {
        try {
            $backup = $backups->create($backupDirectory ?? $config->varDirectory . '/backups', $keep);
            $say('Backup: ' . $backup['path'] . ' (' . ($backup['accounts'] ?? '?') . ' accounts)');
        } catch (Throwable $exception) {
            $backupError = $exception->getMessage();
        }
    }

    if ($backupError !== null) {
        if (!$allowUnbacked) {
            fwrite(STDERR, "Migration stopped: the safety backup could not be created.\n");
            fwrite(STDERR, 'Reason: ' . $backupError . "\n");
            fwrite(STDERR, "Fix the backup problem, run php bin/backup.php manually, or pass --allow-unbacked.\n");
            exit(1);
        }

        fwrite(STDERR, 'Warning: continuing without a database backup: ' . $backupError . "\n");
    }

    $say('Applying ' . count($pending) . ' migration(s)...');

    try {
        $done = $runner->migrate();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . "\n");
        if ($backup !== null) {
            fwrite(STDERR, 'The database backup is at ' . $backup['path'] . "\n");
        }
        fwrite(STDERR, "Nothing was intentionally deleted, but stop the worker and inspect the database.\n");
        exit(1);
    }

    foreach ($done as $version) {
        $say('  - ' . $version);
    }

    $after = $backups->accountCount();

    if ($before !== null && ($after === null || $after < $before)) {
        fwrite(STDERR, "Account verification failed: before=" . $before . ', after=' . ($after ?? 'missing') . ".\n");
        if ($backup !== null) {
            fwrite(STDERR, 'Restore the verified backup before serving requests: ' . $backup['path'] . "\n");
            if ($backup['driver'] === 'sqlite') {
                fwrite(STDERR, "  1. stop the web server and worker\n");
                fwrite(STDERR, "  2. cp '" . $backup['path'] . "' '" . substr($config->databaseDsn, 7) . "'\n");
                fwrite(STDERR, "  3. remove stale -wal/-shm files next to the database\n");
                fwrite(STDERR, "  4. start the services and run php bin/doctor.php\n");
            } else {
                fwrite(STDERR, "  pg_restore --clean --if-exists --dbname='" . $backups->databaseLabel() . "' '" . $backup['path'] . "'\n");
            }
        }
        exit(2);
    }

    $say('Accounts preserved: ' . ($after ?? 'unknown'));
    $say('Schema version: ' . ($backups->schemaVersion() ?? 'unknown'));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Update failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
