<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Config\HostConfig;

use function basename;
use function chmod;
use function copy;
use function count;
use function dirname;
use function fclose;
use function file_get_contents;
use function glob;
use function hash_file;
use function hash_equals;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rename;
use function rtrim;
use function sort;
use function sprintf;
use function stream_get_contents;
use function substr;
use function tempnam;
use function trim;
use function unlink;

use Throwable;

/**
 * Read-only preflight for a restore: validates a decrypted export staging
 * directory against the target instance's configuration before anything is
 * written into the live database or blob store.
 */
final class InstanceRestore
{
    public function __construct(
        private readonly Connection $database,
        private readonly HostConfig $config,
    ) {
    }

    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>}
     */
    public function preflight(string $stagingDirectory): array
    {
        $checks = [];
        $manifest = $this->loadManifest($stagingDirectory, $checks);

        if ($manifest === null) {
            return ['ok' => false, 'checks' => $checks];
        }

        $this->checkDatabase($stagingDirectory, $manifest, $checks);
        $this->checkBlobs($stagingDirectory, $manifest, $checks);
        $this->checkSecretKey($stagingDirectory, $manifest, $checks);
        $this->checkOrigin($manifest, $checks);
        $this->checkSchema($manifest, $checks);

        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     * @return array<string, mixed>|null
     */
    private function loadManifest(string $stagingDirectory, array &$checks): ?array
    {
        $candidates = glob($stagingDirectory . '/backup/*.manifest.json') ?: [];
        sort($candidates);

        if ($candidates === []) {
            $checks[] = ['name' => 'manifest', 'ok' => false, 'detail' => 'No *.manifest.json found under backup/ in the staging directory.'];

            return null;
        }
        if (count($candidates) > 1) {
            $checks[] = ['name' => 'manifest', 'ok' => false, 'detail' => 'Multiple manifests found; expected exactly one export set.'];

            return null;
        }

        $decoded = json_decode((string) file_get_contents($candidates[0]), true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 'alohasocial.instance-manifest') {
            $checks[] = ['name' => 'manifest', 'ok' => false, 'detail' => 'The staged manifest is not an Aloha Social instance manifest.'];

            return null;
        }

        $checks[] = ['name' => 'manifest', 'ok' => true, 'detail' => 'Found ' . basename($candidates[0]) . ' from ' . ($decoded['created_at'] ?? 'unknown time') . '.'];

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     */
    private function checkDatabase(string $stagingDirectory, array $manifest, array &$checks): void
    {
        $database = $manifest['database'] ?? [];
        if (!is_array($database) || !is_string($database['file'] ?? null)) {
            $checks[] = ['name' => 'database', 'ok' => false, 'detail' => 'The manifest has no database entry.'];

            return;
        }

        $file = $stagingDirectory . '/backup/' . $database['file'];
        if (!is_file($file)) {
            $checks[] = ['name' => 'database', 'ok' => false, 'detail' => 'The staged database file is missing: ' . $database['file']];

            return;
        }

        $expected = $database['sha256'] ?? null;
        if (is_string($expected) && $expected !== '') {
            $actual = @hash_file('sha256', $file);
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                $checks[] = ['name' => 'database', 'ok' => false, 'detail' => 'The staged database fails its recorded checksum.'];

                return;
            }
        }

        $checks[] = ['name' => 'database', 'ok' => true, 'detail' => $database['file'] . ' verified (' . ($database['driver'] ?? 'unknown driver') . ').'];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     */
    private function checkBlobs(string $stagingDirectory, array $manifest, array &$checks): void
    {
        $blobs = $manifest['blobs'] ?? [];
        $objects = is_array($blobs) && is_array($blobs['objects'] ?? null) ? $blobs['objects'] : [];
        $hashed = is_array($blobs) && ($blobs['hashes'] ?? null) === 'sha256';

        $missing = 0;
        $changed = 0;
        $verified = 0;
        $unchecked = 0;

        foreach ($objects as $object) {
            if (!is_array($object) || !is_string($object['path'] ?? null)) {
                continue;
            }
            $expected = $object['sha256'] ?? null;
            if (!$hashed || !is_string($expected)) {
                $unchecked++;
                continue;
            }

            $file = $stagingDirectory . '/blobs/' . $object['path'];
            if (!is_file($file)) {
                $missing++;
                continue;
            }
            $actual = @hash_file('sha256', $file);
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                $changed++;
            } else {
                $verified++;
            }
        }

        $detail = sprintf('%d verified, %d missing, %d changed', $verified, $missing, $changed);
        if ($unchecked > 0) {
            $detail .= sprintf(', %d unhashed', $unchecked);
        }

        $checks[] = [
            'name' => 'blobs',
            'ok' => $missing === 0 && $changed === 0,
            'detail' => $detail . '.',
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     */
    private function checkSecretKey(string $stagingDirectory, array $manifest, array &$checks): void
    {
        $expected = $manifest['secret_key_fingerprint'] ?? null;
        if (!is_string($expected) || $expected === '') {
            $checks[] = ['name' => 'secret_key', 'ok' => true, 'detail' => 'The export predates key fingerprints; identity match cannot be proven.'];

            return;
        }

        $actual = SecretKeyFile::fingerprint($this->config);
        if ($actual === null) {
            // A target without a key yet (fresh install) is restorable: the
            // staged secret.key must carry the same identity material.
            $staged = SecretKeyFile::fingerprintFile($stagingDirectory . '/secret.key');
            if ($staged !== null && hash_equals($expected, $staged)) {
                $checks[] = ['name' => 'secret_key', 'ok' => true, 'detail' => 'No key on this instance; the staged key matches the export and will be installed on apply.'];

                return;
            }
            $checks[] = ['name' => 'secret_key', 'ok' => false, 'detail' => 'No secret key is configured on this instance and the export carries no matching staged key.'];

            return;
        }
        if (!hash_equals($expected, $actual)) {
            $checks[] = ['name' => 'secret_key', 'ok' => false, 'detail' => 'The export belongs to a different secret key — sealed data (signing keys, tokens) would be unreadable. Restore the exported secret.key instead.'];

            return;
        }

        $checks[] = ['name' => 'secret_key', 'ok' => true, 'detail' => 'The instance secret key matches the export.'];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     */
    private function checkOrigin(array $manifest, array &$checks): void
    {
        $origin = $manifest['instance_url'] ?? null;
        if (!is_string($origin) || $origin === '') {
            $checks[] = ['name' => 'origin', 'ok' => true, 'detail' => 'The export does not record an instance URL.'];

            return;
        }
        if ($origin !== $this->config->instanceUrl) {
            $checks[] = ['name' => 'origin', 'ok' => false, 'detail' => "The export belongs to {$origin} but this instance is {$this->config->instanceUrl} — restoring would claim a different fediverse identity."];

            return;
        }

        $checks[] = ['name' => 'origin', 'ok' => true, 'detail' => 'Instance URL matches: ' . $origin . '.'];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     */
    private function checkSchema(array $manifest, array &$checks): void
    {
        $backup = new DatabaseBackup($this->database, $this->config);
        $exported = $manifest['schema_version'] ?? null;
        $current = $backup->schemaVersion();

        $checks[] = [
            'name' => 'schema',
            'ok' => true,
            'detail' => 'Export schema ' . ($exported ?? 'unknown') . ', target ' . ($current ?? 'not installed')
                . ' — migrations run on first boot after restore.',
        ];
    }

    /**
     * Applies a staged, preflight-verified export to this instance.
     *
     * The target must hold no accounts — restoring over a populated instance
     * is refused rather than attempted as a merge. The database, local blobs
     * and (when missing) the secret key are installed, then a restore hold
     * pauses the worker until `confirm()` releases it.
     *
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, steps: list<array{name: string, ok: bool, detail: string}>}
     */
    public function apply(string $stagingDirectory): array
    {
        $preflight = $this->preflight($stagingDirectory);
        $steps = [];
        if (!$preflight['ok']) {
            return ['ok' => false, 'checks' => $preflight['checks'], 'steps' => $steps];
        }

        $candidates = glob($stagingDirectory . '/backup/*.manifest.json') ?: [];
        sort($candidates);
        $manifest = $candidates === []
            ? null
            : json_decode((string) file_get_contents($candidates[0]), true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'checks' => $preflight['checks'], 'steps' => $steps];
        }

        $exportedDriver = $manifest['database']['driver'] ?? null;
        $driverOk = is_string($exportedDriver) && $exportedDriver === $this->database->driver();
        $this->step(
            $steps,
            'driver',
            $driverOk,
            $driverOk
                ? 'Export driver ' . $exportedDriver . ' matches the target.'
                : 'The export is a ' . (is_string($exportedDriver) ? $exportedDriver : 'unknown')
                    . ' backup but this instance uses ' . $this->database->driver() . ' — restores cannot convert between drivers.',
        );
        if ($steps[array_key_last($steps)]['ok'] !== true) {
            return $this->applyResult(false, $preflight['checks'], $steps);
        }

        $accounts = $this->targetAccountCount();
        $emptyOk = $accounts === 0;
        $this->step(
            $steps,
            'empty_target',
            $emptyOk,
            $emptyOk
                ? 'The target holds no accounts.'
                : 'The target already holds ' . $accounts . ' account(s) — restore only onto an empty instance.',
        );
        if ($steps[array_key_last($steps)]['ok'] !== true) {
            return $this->applyResult(false, $preflight['checks'], $steps);
        }

        $this->installSecretKey($stagingDirectory, $steps);
        if ($steps[array_key_last($steps)]['ok'] !== true) {
            return $this->applyResult(false, $preflight['checks'], $steps);
        }

        $this->applyDatabase($stagingDirectory, $manifest, $steps);
        if ($steps[array_key_last($steps)]['ok'] !== true) {
            return $this->applyResult(false, $preflight['checks'], $steps);
        }

        $this->applyBlobs($stagingDirectory, $manifest, $steps);
        if ($steps[array_key_last($steps)]['ok'] !== true) {
            return $this->applyResult(false, $preflight['checks'], $steps);
        }

        RestoreHold::engage($this->config, basename((string) $candidates[0]));
        $steps[] = [
            'name' => 'worker_hold',
            'ok' => true,
            'detail' => 'Worker paused at ' . RestoreHold::path($this->config) . ' — run bin/restore.php --confirm after verifying the restored instance.',
        ];

        return $this->applyResult(true, $preflight['checks'], $steps);
    }

    /**
     * Releases the post-restore worker hold. Returns false when no hold was
     * engaged.
     */
    public function confirm(): bool
    {
        return RestoreHold::release($this->config);
    }

    /**
     * @param list<array{name: string, ok: bool, detail: string}> $steps
     */
    private function step(array &$steps, string $name, bool $ok, string $detail): bool
    {
        $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];

        return $ok;
    }

    private function targetAccountCount(): int
    {
        try {
            return (int) $this->database->fetchValue('SELECT COUNT(*) FROM account');
        } catch (Throwable) {
            // No account table at all: the schema was never installed, which
            // is the emptiest possible target.
            return 0;
        }
    }

    /**
     * @param list<array{name: string, ok: bool, detail: string}> $steps
     */
    private function installSecretKey(string $stagingDirectory, array &$steps): void
    {
        $targetMissing = $this->config->secretKey === null && !is_file($this->config->secretKeyFile);
        if (!$targetMissing) {
            $this->step($steps, 'secret_key', true, 'A matching secret key is already configured.');

            return;
        }

        $staged = $stagingDirectory . '/secret.key';
        $target = $this->config->secretKeyFile;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            $this->step($steps, 'secret_key', false, 'Cannot create the key directory: ' . $directory);

            return;
        }

        if (!is_file($staged) || !@copy($staged, $target)) {
            $this->step($steps, 'secret_key', false, 'The staged secret.key is missing; the export cannot restore identity material.');

            return;
        }
        @chmod($target, 0o600);

        $this->step($steps, 'secret_key', true, 'Installed the exported secret key.');
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array{name: string, ok: bool, detail: string}> $steps
     */
    private function applyDatabase(string $stagingDirectory, array $manifest, array &$steps): void
    {
        $file = $manifest['database']['file'] ?? null;
        if (!is_string($file)) {
            $this->step($steps, 'database', false, 'The manifest has no database file entry.');

            return;
        }

        $source = $stagingDirectory . '/backup/' . $file;
        try {
            if ($this->database->driver() === 'sqlite') {
                $detail = $this->applySqlite($source);
            } else {
                $detail = $this->applyPostgres($source);
            }
            $this->step($steps, 'database', true, $detail);
        } catch (Throwable $exception) {
            $this->step($steps, 'database', false, $exception->getMessage());
        }
    }

    private function applySqlite(string $source): string
    {
        $target = substr($this->config->databaseDsn, strlen('sqlite:'));
        if ($target === '' || $target === ':memory:') {
            throw new \RuntimeException('Cannot restore onto an in-memory or empty SQLite DSN.');
        }

        $directory = dirname($target);
        if (!is_dir($directory)) {
            throw new \RuntimeException('The database directory does not exist: ' . $directory);
        }

        // Stale WAL sidecars would be replayed into the restored file.
        foreach ([$target . '-wal', $target . '-shm', $target . '-journal'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }

        $temporary = tempnam($directory, '.restore-');
        if ($temporary === false || !@copy($source, $temporary)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Cannot stage the database file in ' . $directory);
        }
        @chmod($temporary, 0o600);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot replace the database file: ' . $target);
        }

        return basename($target) . ' replaced from ' . basename($source) . '.';
    }

    private function applyPostgres(string $source): string
    {
        [$host, $port, $database] = DatabaseBackup::postgresTarget($this->config->databaseDsn);

        $command = [
            'pg_restore',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-acl',
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
        $command[] = $source;

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        if ($this->config->databasePassword !== null) {
            $environment['PGPASSWORD'] = $this->config->databasePassword;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('pg_restore is not available. Install the PostgreSQL client tools.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            $detail = trim((string) $stderr);
            if ($detail === '') {
                $detail = trim((string) $stdout);
            }
            throw new \RuntimeException('pg_restore failed: ' . ($detail !== '' ? $detail : 'exit code ' . $status));
        }

        return 'pg_restore completed into ' . $database . '.';
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<array{name: string, ok: bool, detail: string}> $steps
     */
    private function applyBlobs(string $stagingDirectory, array $manifest, array &$steps): void
    {
        $blobs = $manifest['blobs'] ?? [];
        if (!is_array($blobs) || ($blobs['driver'] ?? 'local') !== 'local') {
            $this->step($steps, 'blobs', true, 'Remote object storage — copy the bucket separately per the export runbook.');

            return;
        }

        $objects = is_array($blobs['objects'] ?? null) ? $blobs['objects'] : [];
        $root = rtrim($this->config->blobDirectory, '/');
        $copied = 0;

        foreach ($objects as $object) {
            $path = is_array($object) ? ($object['path'] ?? null) : null;
            if (!is_string($path) || str_contains($path, '..')) {
                continue;
            }
            $source = $stagingDirectory . '/blobs/' . $path;
            $target = $root . '/' . $path;
            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
                $this->step($steps, 'blobs', false, 'Cannot create blob directory: ' . $directory);

                return;
            }
            if (is_file($source) && @copy($source, $target)) {
                $copied++;
            }
        }

        $this->step($steps, 'blobs', true, $copied . ' blob(s) installed into ' . $root . '.');
    }

    /**
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     * @param list<array{name: string, ok: bool, detail: string}> $steps
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, steps: list<array{name: string, ok: bool, detail: string}>}
     */
    private function applyResult(bool $ok, array $checks, array $steps): array
    {
        return ['ok' => $ok, 'checks' => $checks, 'steps' => $steps];
    }
}
