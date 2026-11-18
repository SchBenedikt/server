#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Apply the Core dependency policy chosen in the admin console.
 *
 * Bare-metal installs run this after saving the channel/version. A Docker
 * deployment should rebuild the image instead: the image contains the tested
 * vendor tree, and replacing it inside a running container is not durable.
 */

use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = HostConfig::load($root);

if ($config->coreChannel === 'release' && $config->coreVersion === '') {
    fwrite(STDERR, "ALOHASOCIAL_CORE_VERSION is empty; choose a tagged Core release first.\n");
    exit(2);
}

$constraint = $config->coreChannel === 'main' ? 'dev-main' : $config->coreVersion;
if ($config->coreChannel === 'release'
    && preg_match('/^v?\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $constraint) !== 1
) {
    fwrite(STDERR, "Refusing an invalid Core release: {$constraint}\n");
    exit(2);
}

$package = 'alohasocial/core:' . $constraint;
$command = 'composer require ' . escapeshellarg($package)
    . ' --with-all-dependencies --no-interaction --no-progress --no-scripts';

fwrite(STDOUT, "Installing {$package} ...\n");
passthru($command, $status);
if ($status !== 0) {
    fwrite(STDERR, "Composer failed with exit code {$status}.\n");
    exit($status);
}

fwrite(STDOUT, "Applying database migrations ...\n");
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/update.php'), $status);
if ($status !== 0) {
    fwrite(STDERR, "The database update failed with exit code {$status}.\n");
    exit($status);
}

fwrite(STDOUT, "Core is now {$package}. Restart the web and worker processes.\n");
