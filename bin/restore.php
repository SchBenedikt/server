<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Support\InstanceExport;
use AlohaSocial\Server\Support\InstanceRestore;
use AlohaSocial\Server\Support\RestoreHold;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$export = null;
$staging = null;
$passphraseFile = null;
$apply = false;
$confirm = false;

foreach (array_slice($argv, 1) as $option) {
    if (str_starts_with($option, '--export=')) {
        $export = substr($option, 9);
        continue;
    }

    if (str_starts_with($option, '--staging=')) {
        $staging = substr($option, 10);
        continue;
    }

    if (str_starts_with($option, '--passphrase-file=')) {
        $passphraseFile = substr($option, 19);
        continue;
    }

    if ($option === '--apply') {
        $apply = true;
        continue;
    }

    if ($option === '--confirm') {
        $confirm = true;
        continue;
    }

    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, "Usage: php bin/restore.php --export=FILE --staging=DIR [--passphrase-file=PATH] [--apply]\n"
            . "       php bin/restore.php --confirm\n"
            . "\n"
            . "Without --apply, decrypts the export into the staging directory and runs\n"
            . "the read-only restore preflight: manifest, database checksum, blob hashes,\n"
            . "secret key and origin checks against this instance. Nothing is written to\n"
            . "the live database or blob store.\n"
            . "\n"
            . "--apply additionally installs the staged backup onto this instance. The\n"
            . "target must hold no accounts; afterwards the worker stays paused until\n"
            . "--confirm releases it. The passphrase comes from\n"
            . "ALOHASOCIAL_EXPORT_PASSPHRASE or --passphrase-file.\n");
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

    if ($confirm) {
        if (RestoreHold::release($config)) {
            fwrite(STDOUT, "Restore hold released — the worker resumes on its next tick.\n");
            exit(0);
        }
        fwrite(STDERR, "No restore hold is active.\n");
        exit(1);
    }

    if ($export === null || $staging === null) {
        fwrite(STDERR, "restore.php needs --export=FILE and --staging=DIR.\n");
        exit(2);
    }
    if ($passphrase === null) {
        fwrite(STDERR, "Set ALOHASOCIAL_EXPORT_PASSPHRASE or pass --passphrase-file.\n");
        exit(2);
    }

    $written = InstanceExport::open($export, $passphrase, $staging);
    fwrite(STDOUT, 'Decrypted ' . count($written) . " files into {$staging}\n");

    $database = PdoFactory::connect($config);
    $restore = new InstanceRestore($database, $config);
    $report = $apply ? $restore->apply($staging) : $restore->preflight($staging);

    foreach ($report['checks'] as $check) {
        fwrite($check['ok'] ? STDOUT : STDERR, ($check['ok'] ? '[ok]   ' : '[FAIL] ') . $check['name'] . ': ' . $check['detail'] . "\n");
    }
    foreach ($report['steps'] ?? [] as $step) {
        fwrite($step['ok'] ? STDOUT : STDERR, ($step['ok'] ? '[ok]   ' : '[FAIL] ') . $step['name'] . ': ' . $step['detail'] . "\n");
    }

    if ($apply) {
        fwrite($report['ok'] ? STDOUT : STDERR, $report['ok']
            ? "Restore applied. The worker is paused until you verify the instance\n"
            . "and run: php bin/restore.php --confirm\n"
            : "Restore refused — the live instance was not modified.\n");
        exit($report['ok'] ? 0 : 1);
    }

    fwrite($report['ok'] ? STDOUT : STDERR, $report['ok']
        ? "Preflight passed. The staging directory is ready for a restore rehearsal;\n"
        . "the live instance was not modified. Add --apply to install it onto an empty target.\n"
        : "Preflight failed — do not restore this export onto this instance.\n");
    exit($report['ok'] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Restore failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
