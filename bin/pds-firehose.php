<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    // The core process owns the WebSocket protocol. Resolve its PDO settings
    // through the host config so setup-managed var/.env values and PostgreSQL
    // credentials match the HTTP and worker processes exactly.
    $config = HostConfig::load($root);
    putenv('PDS_DATABASE_DSN=' . $config->databaseDsn);
    putenv('PDS_DATABASE_USER=' . ($config->databaseUser ?? ''));
    putenv('PDS_DATABASE_PASSWORD=' . ($config->databasePassword ?? ''));
    putenv('PDS_FIREHOSE_HOST=' . (getenv('PDS_FIREHOSE_HOST') ?: '0.0.0.0'));
    putenv('PDS_FIREHOSE_PORT=' . (getenv('PDS_FIREHOSE_PORT') ?: '2584'));
    putenv('PDS_FIREHOSE_MAX_CLIENTS=' . (getenv('PDS_FIREHOSE_MAX_CLIENTS') ?: '256'));

    require $root . '/vendor/alohasocial/core/bin/pds-firehose';
} catch (Throwable $exception) {
    fwrite(STDERR, 'PDS firehose cannot start: ' . $exception->getMessage() . "\n");
    exit(1);
}
