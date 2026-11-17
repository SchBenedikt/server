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
use AlohaSocial\Server\Setup\SetupToken;
use AlohaSocial\Server\Support\DatabaseBackup;
use AlohaSocial\Server\Support\InstallationReset;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$yes = false;
$keepMedia = false;
$noBackup = false;
$allowUnbacked = false;

foreach (array_slice($argv, 1) as $option) {
    if ($option === '--yes' || $option === '-y') {
        $yes = true;
        continue;
    }

    if ($option === '--keep-media') {
        $keepMedia = true;
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

    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, <<<TEXT
Usage: php bin/reset.php [options]

Deletes every account, post, session and other application row, keeps the
database connection and secret key, rebuilds the empty schema, and prints a
one-time URL for the web setup. Media and mail files are removed unless
--keep-media is used.

Options:
  -y, --yes           Do not ask for confirmation (for controlled scripts)
  --keep-media        Keep uploaded files in var/blobs and mail in var/mail
  --no-backup         Do not create the safety backup before dropping tables
  --allow-unbacked    Continue when the safety backup cannot be created
  -h, --help          Show this help

The reset keeps .env so the database and storage paths still work. The web
setup then changes the public URL, instance identity and first admin.
TEXT);
        exit(0);
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

try {
    $config = HostConfig::load($root);
    $database = PdoFactory::connect($config);
    $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
    $backups = new DatabaseBackup($database, $config);

    $installed = $runner->isInstalled();
    $accounts = $installed ? $backups->accountCount() : null;
    $driver = $database->driver();

    fwrite(STDOUT, "Aloha Social reset\n");
    fwrite(STDOUT, '  Instance: ' . $config->instanceUrl . "\n");
    fwrite(STDOUT, '  Database: ' . $backups->databaseLabel() . ' (' . $driver . ")\n");
    fwrite(STDOUT, '  Accounts: ' . ($accounts ?? 'not installed') . "\n");
    fwrite(STDOUT, '  Data:     ' . $config->varDirectory . "\n");
    fwrite(STDOUT, '  Media:    ' . ($keepMedia ? 'kept' : 'will be removed') . "\n\n");

    if (!$yes) {
        if (!stream_isatty(STDIN)) {
            fwrite(STDERR, "Refusing to reset without confirmation. Re-run with --yes in a script.\n");
            exit(2);
        }

        fwrite(STDOUT, "This deletes all accounts, posts, reports and sessions. Type RESET to continue: ");
        $answer = trim((string) fgets(STDIN));
        if ($answer !== 'RESET') {
            fwrite(STDOUT, "Reset cancelled.\n");
            exit(0);
        }
    }

    $backup = null;
    if (!$noBackup && ($accounts ?? 0) > 0) {
        try {
            $backup = $backups->create($config->varDirectory . '/backups');
            fwrite(STDOUT, 'Backup: ' . $backup['path'] . ' (' . ($backup['accounts'] ?? '?') . " accounts)\n");
        } catch (Throwable $exception) {
            if (!$allowUnbacked) {
                fwrite(STDERR, "Reset stopped: the safety backup could not be created.\n");
                fwrite(STDERR, 'Reason: ' . $exception->getMessage() . "\n");
                fwrite(STDERR, "Fix the backup problem, run php bin/backup.php manually, or pass --allow-unbacked.\n");
                exit(1);
            }

            fwrite(STDERR, 'Warning: continuing without a backup: ' . $exception->getMessage() . "\n");
        }
    }

    $reset = new InstallationReset($database);
    $dropped = $reset->dropAllTables();
    fwrite(STDOUT, 'Dropped ' . count($dropped) . " table(s).\n");

    $done = $runner->migrate();
    fwrite(STDOUT, 'Created ' . count($done) . " migration(s); schema is empty.\n");

    if (!$keepMedia) {
        $blobs = InstallationReset::clearDirectory($config->blobDirectory);
        $mail = InstallationReset::clearDirectory($config->mailLogDirectory);
        fwrite(STDOUT, 'Removed ' . $blobs . ' media file(s) and ' . $mail . " mail file(s).\n");
    }

    $token = (new SetupToken($config->varDirectory . '/setup.token'))->generate();
    $url = rtrim($config->instanceUrl, '/') . '/setup?token=' . rawurlencode($token);

    fwrite(STDOUT, "\nReset complete. The instance is empty and ready for web setup.\n");
    fwrite(STDOUT, '  ' . $url . "\n\n");
    fwrite(STDOUT, "The token is also in " . $config->varDirectory . "/setup.token.\n");
    fwrite(STDOUT, "Open the URL, set the final HTTPS domain and create the first admin.\n");
    if ($backup !== null) {
        fwrite(STDOUT, 'Database backup: ' . $backup['path'] . "\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Reset failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
