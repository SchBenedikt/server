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

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $config = HostConfig::load($root);
    $database = PdoFactory::connect($config);

    $runner = new MigrationRunner($database, new SystemClock(), Migrations::all());
    $applied = $runner->migrate();

    if ($applied === []) {
        fwrite(STDOUT, "Nothing to migrate.\n");
    } else {
        fwrite(STDOUT, "Applied migrations:\n");
        foreach ($applied as $version) {
            fwrite(STDOUT, '  - ' . $version . "\n");
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
