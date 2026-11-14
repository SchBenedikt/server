<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Db;

use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Server\Config\HostConfig;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Opens the one PDO connection the process uses.
 *
 * The blueprint prefers PostgreSQL and allows SQLite for small installs, so
 * both DSNs are supported. The connection is wrapped in the core's
 * `PdoConnection`, which implements `Connection` and `TransactionRunner`:
 * that is what makes post writes atomic in `Application::forHost()`.
 */
final class PdoFactory
{
    public static function connect(HostConfig $config): PdoConnection
    {
        $dsn = trim($config->databaseDsn);

        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, 7);
            if ($path !== ':memory:' && $path !== '') {
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
                    throw new RuntimeException('Cannot create the database directory: ' . $directory);
                }
            }
        }

        try {
            $pdo = new PDO(
                $dsn,
                $config->databaseUser,
                $config->databasePassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'The database connection failed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }

        if ($driver === 'pgsql') {
            $pdo->exec("SET TIME ZONE 'UTC'");
        }

        return new PdoConnection($pdo);
    }
}
