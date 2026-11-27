<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordPolicy;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = array_slice($argv, 1);
$handle = null;
$email = null;
$password = null;
$displayName = null;
$admin = false;
$ifNotExists = false;
$passwordStdin = false;

foreach ($options as $option) {
    if ($option === '--admin') {
        $admin = true;
    } elseif ($option === '--if-not-exists') {
        // Container entrypoints run on every restart; an existing account is
        // the expected state there, not an error.
        $ifNotExists = true;
    } elseif ($option === '--password-stdin') {
        $passwordStdin = true;
    } elseif (str_starts_with($option, '--password=')) {
        $password = substr($option, 11);
    } elseif (str_starts_with($option, '--display-name=')) {
        $displayName = substr($option, 15);
    } elseif ($handle === null) {
        $handle = $option;
    } elseif ($email === null) {
        $email = $option;
    } else {
        fwrite(STDERR, 'Unexpected argument: ' . $option . "\n");
        exit(2);
    }
}

if ($handle === null || $email === null) {
    fwrite(STDERR, "Usage: php bin/create-account.php <handle> <email> [--admin] [--password=secret] [--password-stdin] [--display-name=Name] [--if-not-exists]\n");
    exit(2);
}

$handle = strtolower(trim($handle));
$email = strtolower(trim($email));

if (preg_match('/^[a-z0-9][a-z0-9_-]{1,29}$/', $handle) !== 1) {
    fwrite(STDERR, "A handle is 2-30 characters: lower-case letters, digits, \"_\" or \"-\".\n");
    exit(2);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "That email address is not valid.\n");
    exit(2);
}

if ($passwordStdin) {
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($password === '') {
        fwrite(STDERR, "A password is required on standard input.\n");
        exit(2);
    }
} elseif ($password === null) {
    fwrite(STDERR, 'Password for ' . $handle . ': ');
    $password = rtrim((string) fgets(STDIN), "\r\n");

    if ($password === '') {
        fwrite(STDERR, "A password is required.\n");
        exit(2);
    }

    fwrite(STDERR, "Note: the password was read from standard input without echo suppression.\n");
}

$passwordError = PasswordPolicy::error($password);
if ($passwordError !== null) {
    fwrite(STDERR, $passwordError . "\n");
    exit(2);
}

try {
    $config = HostConfig::load($root);
    $runtime = (new ApplicationFactory($config, $root))->boot();

    if ($runtime->accounts->exists($handle, $email)) {
        if ($ifNotExists) {
            fwrite(STDOUT, 'Account @' . $handle . " already exists; nothing to do.\n");
            exit(0);
        }

        fwrite(STDERR, "An account with this handle or email already exists.\n");
        exit(1);
    }

    $account = $runtime->accounts->create(
        $handle,
        $email,
        (new PasswordHasher())->hash($password),
        $admin ? Account::ROLE_ADMIN : Account::ROLE_USER,
        $displayName,
    );

    fwrite(STDOUT, 'Created account @' . $account->id . ' (' . $account->role . ").\n");
    fwrite(STDOUT, 'Actor: ' . $config->instanceUrl . '/@' . rawurlencode($account->id) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not create the account: ' . $exception->getMessage() . "\n");
    exit(1);
}
