<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real `bin/update.php` in a subprocess so argument handling,
 * exit codes and the backup gate are covered, not just the service classes.
 */
final class UpdateCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/alohasocial-update-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o770, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testUpdateCreatesABackupAndPreservesAccounts(): void
    {
        [$status] = $this->runUpdate();
        self::assertSame(0, $status);

        $this->insertAccount('alice');
        $this->markNewestMigrationPending();

        [$status, $output, $error] = $this->runUpdate('--backup-dir=' . $this->directory . '/backups');
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('Backup:', $output);
        self::assertStringContainsString('Accounts preserved: 1', $output);
        self::assertStringNotContainsString('safety backup', $error);

        self::assertSame(1, $this->accountCount());
        self::assertNotSame([], glob($this->directory . '/backups/alohasocial-*.sqlite') ?: []);
        self::assertNotSame([], glob($this->directory . '/backups/alohasocial-*.json') ?: []);
    }

    public function testUpdateRefusesToMigrateWhenTheSafetyBackupFails(): void
    {
        [$status] = $this->runUpdate();
        self::assertSame(0, $status);

        $this->insertAccount('alice');
        $this->markNewestMigrationPending();

        // A regular file cannot be used as a directory, so the backup fails.
        file_put_contents($this->directory . '/blocked', 'not a directory');

        [$status, , $error] = $this->runUpdate('--backup-dir=' . $this->directory . '/blocked/backups');
        self::assertSame(1, $status);
        self::assertStringContainsString('safety backup could not be created', $error);

        self::assertSame(1, $this->accountCount());
        $pdo = $this->pdo();
        self::assertFalse(
            $pdo->query("SELECT version FROM schema_migration WHERE version = '202611160007'")->fetchColumn(),
        );

        // The explicit opt-out still allows the additive migration.
        [$status, $output] = $this->runUpdate('--allow-unbacked');
        self::assertSame(0, $status);
        self::assertStringContainsString('Accounts preserved: 1', $output);
        self::assertSame(1, $this->accountCount());
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runUpdate(string ...$arguments): array
    {
        $root = dirname(__DIR__);
        $command = [PHP_BINARY, $root . '/bin/update.php', ...$arguments];
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }

        $environment = array_merge($environment, [
            'ALOHASOCIAL_VAR_DIRECTORY' => $this->directory,
            'ALOHASOCIAL_DB_DSN' => 'sqlite:' . $this->directory . '/server.sqlite',
            'ALOHASOCIAL_BLOB_DIRECTORY' => $this->directory . '/blobs',
            'ALOHASOCIAL_SECRET_KEY_FILE' => $this->directory . '/secret.key',
            'ALOHASOCIAL_MAIL_LOG_DIR' => $this->directory . '/mail',
            'ALOHASOCIAL_INSTANCE_URL' => 'http://localhost:3000',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $root, $environment);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [$status, (string) $output, (string) $error];
    }

    private function insertAccount(string $handle): void
    {
        $this->pdo()->prepare(
            'INSERT INTO account (id, handle, email, password_hash, role, display_name, created_at)'
            . ' VALUES (:id, :handle, :email, :password, :role, :name, :created)',
        )->execute([
            'id' => $handle,
            'handle' => $handle,
            'email' => $handle . '@example.test',
            'password' => password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT),
            'role' => 'user',
            'name' => $handle,
            'created' => date('c'),
        ]);
    }

    private function markNewestMigrationPending(): void
    {
        $this->pdo()->exec("DELETE FROM schema_migration WHERE version = '202611160007'");
    }

    private function accountCount(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM account')->fetchColumn();
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite:' . $this->directory . '/server.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
