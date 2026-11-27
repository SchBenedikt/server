<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Db\PdoFactory;
use AlohaSocial\Server\Migrations\MigrationRunner;
use AlohaSocial\Server\Migrations\Migrations;
use AlohaSocial\Server\Setup\SetupException;
use AlohaSocial\Server\Setup\SetupService;
use AlohaSocial\Server\Setup\SetupToken;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$ttl = SetupToken::DEFAULT_TTL_SECONDS;

foreach (array_slice($argv, 1) as $option) {
    if ($option === '--help' || $option === '-h') {
        fwrite(STDOUT, <<<TEXT
Usage: php bin/setup-token.php [--ttl=SECONDS]

Prints a short-lived URL for the browser setup of an empty instance. Existing
accounts or content are never deleted and make this command refuse to run.

Options:
  --ttl=SECONDS  Lifetime of the token (default 3600, maximum 86400)
  -h, --help     Show this help

The token is stored with mode 0600 in var/setup.token. Do not paste it into
logs, tickets or chat; the setup URL is a one-time credential.
TEXT);
        exit(0);
    }

    if (str_starts_with($option, '--ttl=')) {
        $value = substr($option, 6);
        if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
            fwrite(STDERR, "The --ttl value must be a whole number of seconds.\n");
            exit(2);
        }

        $ttl = (int) $value;
        continue;
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

try {
    $config = HostConfig::load($root);
    $clock = new SystemClock();
    $database = PdoFactory::connect($config);
    $runner = new MigrationRunner($database, $clock, Migrations::all());
    if (!$runner->isInstalled()) {
        throw new RuntimeException('The database is not initialised. Run php bin/update.php first.');
    }

    $accounts = new AccountRepository($database, $clock);
    $setup = new SetupService($config, $database, $accounts, new PasswordHasher(), $clock);
    $token = $setup->issueToken($ttl);
    $expiresAt = $setup->tokenExpiresAt();
    $url = rtrim($config->instanceUrl, '/') . '/setup?token=' . rawurlencode($token);

    fwrite(STDOUT, "Aloha Social setup\n");
    fwrite(STDOUT, '  Instance: ' . $config->instanceUrl . "\n");
    fwrite(STDOUT, '  Expires:  ' . ($expiresAt ?? 'unknown') . " UTC\n\n");
    fwrite(STDOUT, $url . "\n\n");
    fwrite(STDOUT, 'The token is also in ' . $config->varDirectory . "/setup.token (mode 0600).\n");
    fwrite(STDOUT, "Open the URL, set the final HTTPS domain and create the first admin.\n");
} catch (SetupException $exception) {
    fwrite(STDERR, 'Setup is closed: ' . $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cannot create a setup token: ' . $exception->getMessage() . "\n");
    exit(1);
}
