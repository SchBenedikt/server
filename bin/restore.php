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

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$export = null;
$staging = null;
$passphraseFile = null;

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

    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, "Usage: php bin/restore.php --export=FILE --staging=DIR [--passphrase-file=PATH]\n"
            . "\n"
            . "Decrypts the export into the staging directory and runs the read-only\n"
            . "restore preflight: manifest, database checksum, blob hashes, secret key\n"
            . "and origin checks against this instance. Nothing is written to the\n"
            . "live database or blob store.\n"
            . "The passphrase comes from ALOHASOCIAL_EXPORT_PASSPHRASE or --passphrase-file.\n");
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

    $config = HostConfig::load($root);
    $database = PdoFactory::connect($config);
    $report = (new InstanceRestore($database, $config))->preflight($staging);

    foreach ($report['checks'] as $check) {
        fwrite($check['ok'] ? STDOUT : STDERR, ($check['ok'] ? '[ok]   ' : '[FAIL] ') . $check['name'] . ': ' . $check['detail'] . "\n");
    }

    fwrite($report['ok'] ? STDOUT : STDERR, $report['ok']
        ? "Preflight passed. The staging directory is ready for a restore rehearsal;\n"
        . "the live instance was not modified.\n"
        : "Preflight failed — do not restore this export onto this instance.\n");
    exit($report['ok'] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Restore preflight failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
