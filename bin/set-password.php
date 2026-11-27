<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordPolicy;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = array_slice($argv, 1);
$handle = null;
$password = null;

foreach ($options as $option) {
    if (str_starts_with($option, '--password=')) {
        $password = substr($option, 11);
    } elseif ($handle === null) {
        $handle = $option;
    } else {
        fwrite(STDERR, 'Unexpected argument: ' . $option . "\n");
        exit(2);
    }
}

if ($handle === null) {
    fwrite(STDERR, "Usage: php bin/set-password.php <handle> [--password=secret]\n");
    exit(2);
}

$handle = strtolower(trim($handle));

if ($password === null) {
    fwrite(STDERR, 'New password for ' . $handle . ': ');
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
    $runtime = (new ApplicationFactory(HostConfig::load($root), $root))->boot();

    $account = $runtime->accounts->findByHandle($handle);
    if ($account === null) {
        fwrite(STDERR, "No account with the handle @" . $handle . ".\n");
        exit(1);
    }

    $runtime->accounts->updatePasswordHash($account->id, (new PasswordHasher())->hash($password));

    // A password change ends every existing session of that account.
    $runtime->database->execute('DELETE FROM session WHERE account_id = :account_id', ['account_id' => $account->id]);

    fwrite(STDOUT, 'Password updated for @' . $account->id . "; sessions revoked.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not update the password: ' . $exception->getMessage() . "\n");
    exit(1);
}
