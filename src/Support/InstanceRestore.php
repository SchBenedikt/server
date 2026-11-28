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
use function count;
use function dirname;
use function glob;
use function hash_file;
use function hash_equals;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function sort;
use function sprintf;

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
        $this->checkSecretKey($manifest, $checks);
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
    private function checkSecretKey(array $manifest, array &$checks): void
    {
        $expected = $manifest['secret_key_fingerprint'] ?? null;
        if (!is_string($expected) || $expected === '') {
            $checks[] = ['name' => 'secret_key', 'ok' => true, 'detail' => 'The export predates key fingerprints; identity match cannot be proven.'];

            return;
        }

        $actual = SecretKeyFile::fingerprint($this->config);
        if ($actual === null) {
            $checks[] = ['name' => 'secret_key', 'ok' => false, 'detail' => 'No secret key is configured on this instance.'];

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
}
