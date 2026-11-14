<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $runtime = (new ApplicationFactory(HostConfig::load($root), $root))->boot();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cannot boot the server: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, $runtime->app->routes->toJson() . "\n");
