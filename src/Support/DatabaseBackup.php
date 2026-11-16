<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Config\HostConfig;
use PDO;
use RuntimeException;
use Throwable;

use function array_slice;
use function basename;
use function date;
use function explode;
use function fclose;
use function file_put_contents;
use function filemtime;
use function filesize;
use function glob;
use function is_array;
use function is_dir;
use function is_resource;
use function json_encode;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function rtrim;
use function str_contains;
use function str_replace;
use function stream_get_contents;
use function strlen;
use function substr;
use function trim;
use function usort;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * Creates and verifies a backup of the instance database.
 *
 * SQLite is copied with `VACUUM INTO`, which produces a consistent snapshot
 * even while WAL is active. PostgreSQL uses `pg_dump` when a matching client
 * is installed on the host. A backup is only returned after it exists, is
 * non-empty and (for SQLite) can be opened and queried.
 *
 * The account count is part of the metadata. The update command compares it
 * with the live database after a migration, so a release that accidentally
 * loses accounts is detected immediately instead of after a user notices.
 */
final class DatabaseBackup
{
    public function __construct(
        private readonly Connection $database,
        private readonly HostConfig $config,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     metadata_path: string,
     *     driver: string,
     *     accounts: ?int,
     *     schema_version: ?string,
     *     bytes: int
     * }
     */
    public function create(string $directory, int $keep = 10): array
    {
        $directory = rtrim($directory, '/');
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the backup directory: ' . $directory);
        }

        $driver = $this->database->driver();
        $stamp = date('Ymd-His');
        $suffix = bin2hex(random_bytes(3));
        $base = $directory . '/alohasocial-' . $stamp . '-' . $suffix;
        $path = $base . ($driver === 'sqlite' ? '.sqlite' : '.dump');

        if ($driver === 'sqlite') {
            $this->backupSqlite($path);
        } elseif ($driver === 'pgsql') {
            $this->backupPostgres($path);
        } else {
            throw new RuntimeException('Automatic backups support SQLite and PostgreSQL, not ' . $driver . '.');
        }

        @chmod($path, 0o600);
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1) {
            throw new RuntimeException('The database backup is empty: ' . $path);
        }

        $accounts = $this->accountCount();
        $schemaVersion = $this->schemaVersion();

        if ($driver === 'sqlite') {
            $this->verifySqlite($path, $accounts);
        }

        $metadata = [
            'format' => 'alohasocial.database-backup',
            'version' => 1,
            'created_at' => date('c'),
            'driver' => $driver,
            'database' => $this->databaseLabel(),
            'accounts' => $accounts,
            'schema_version' => $schemaVersion,
            'bytes' => $bytes,
        ];

        $metadataPath = $base . '.json';
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($metadataPath, $encoded . "\n") === false) {
            throw new RuntimeException('Cannot write the backup metadata: ' . $metadataPath);
        }
        @chmod($metadataPath, 0o600);

        $this->prune($directory, $keep);

        return [
            'path' => $path,
            'metadata_path' => $metadataPath,
            'driver' => $driver,
            'accounts' => $accounts,
            'schema_version' => $schemaVersion,
            'bytes' => $bytes,
        ];
    }

    /**
     * Returns null when the account table does not exist yet, so a fresh
     * install is distinguishable from an install with zero accounts.
     */
    public function accountCount(): ?int
    {
        try {
            return (int) $this->database->fetchValue('SELECT COUNT(*) FROM account');
        } catch (Throwable) {
            return null;
        }
    }

    public function schemaVersion(): ?string
    {
        try {
            $value = $this->database->fetchValue('SELECT MAX(version) FROM schema_migration');

            return $value === null ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A short, non-secret description used in metadata and diagnostics.
     */
    public function databaseLabel(): string
    {
        $dsn = $this->config->databaseDsn;
        if (str_starts_with($dsn, 'sqlite:')) {
            return 'sqlite:' . basename(substr($dsn, 7));
        }

        if (preg_match('/dbname=([^;]+)/', $dsn, $match) === 1) {
            return 'pgsql:' . $match[1];
        }

        return $this->database->driver();
    }

    private function backupSqlite(string $path): void
    {
        $quoted = str_replace("'", "''", $path);
        $this->database->execute("VACUUM INTO '" . $quoted . "'");
    }

    private function backupPostgres(string $path): void
    {
        [$host, $port, $database] = $this->postgresTarget();
        $command = [
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-acl',
            '--file=' . $path,
            '--dbname=' . $database,
        ];

        if ($host !== '') {
            $command[] = '--host=' . $host;
        }
        if ($port !== '') {
            $command[] = '--port=' . $port;
        }
        if ($this->config->databaseUser !== null) {
            $command[] = '--username=' . $this->config->databaseUser;
        }

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        if ($this->config->databasePassword !== null) {
            $environment['PGPASSWORD'] = $this->config->databasePassword;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException(
                'pg_dump is not available. Install the PostgreSQL client or use bin/backup.php on a host that has it.',
            );
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            @unlink($path);
            $detail = trim((string) $stderr);
            if ($detail === '') {
                $detail = trim((string) $stdout);
            }

            throw new RuntimeException('pg_dump failed: ' . ($detail !== '' ? $detail : 'exit code ' . $status));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function postgresTarget(): array
    {
        $dsn = $this->config->databaseDsn;
        $values = [];
        foreach (explode(';', substr($dsn, strlen('pgsql:'))) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $values[strtolower(trim($key))] = trim($value);
        }

        $database = $values['dbname'] ?? '';
        if ($database === '') {
            throw new RuntimeException('The PostgreSQL DSN has no dbname; cannot create a backup.');
        }

        return [$values['host'] ?? '', $values['port'] ?? '', $database];
    }

    private function verifySqlite(string $path, ?int $expectedAccounts): void
    {
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $check = $pdo->query('PRAGMA quick_check')->fetchColumn();
            if ($check !== 'ok') {
                throw new RuntimeException('SQLite integrity check failed: ' . (string) $check);
            }

            if ($expectedAccounts !== null) {
                $actual = (int) $pdo->query('SELECT COUNT(*) FROM account')->fetchColumn();
                if ($actual !== $expectedAccounts) {
                    throw new RuntimeException(
                        'The backup contains ' . $actual . ' accounts, expected ' . $expectedAccounts . '.',
                    );
                }
            }
        } catch (Throwable $exception) {
            @unlink($path);

            throw new RuntimeException('The SQLite backup could not be verified: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $files = glob($directory . '/alohasocial-*');
        if ($files === false) {
            return;
        }

        $backups = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^alohasocial-\d{8}-\d{6}-[a-f0-9]{6}\.(sqlite|dump)$/', $name) === 1) {
                $backups[] = $file;
            }
        }

        usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($backups, $keep) as $file) {
            @unlink($file);
            @unlink(substr($file, 0, (int) strrpos($file, '.')) . '.json');
        }
    }
}
