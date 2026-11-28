<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Server\Config\HostConfig;
use RuntimeException;
use Throwable;

use function basename;
use function chmod;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash_file;
use function is_file;
use function json_encode;
use function rtrim;
use function str_ends_with;
use function substr;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * Full-instance backup manifest: the database snapshot plus every media blob,
 * the identity fingerprint and the versions needed to prove a restore.
 *
 * Written next to the database backup as `<name>.manifest.json`. Blob hashes
 * are computed for the local store; remote (S3) objects record size only —
 * the object store's own integrity (ETag/checksums) covers those, and
 * downloading every object just to hash it would make backups impractical.
 */
final class InstanceManifest
{
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly HostConfig $config,
        private readonly BlobStore $blobStore,
    ) {
    }

    /**
     * @return array{path: string, objects: int, bytes: int}
     */
    public function create(string $backupPath, string $backupMetadataPath): array
    {
        $objects = [];
        $totalBytes = 0;

        try {
            $paths = $this->blobStore->list();
        } catch (Throwable $exception) {
            throw new RuntimeException('Cannot list the blob store for the manifest: ' . $exception->getMessage(), 0, $exception);
        }

        $localRoot = $this->config->storageDriver === 'local' ? rtrim($this->config->blobDirectory, '/') : null;

        foreach ($paths as $path) {
            try {
                $size = $this->blobStore->size($path);
            } catch (Throwable) {
                $size = null;
            }

            $sha256 = null;
            if ($localRoot !== null) {
                $full = $localRoot . '/' . $path;
                if (is_file($full)) {
                    $hash = @hash_file('sha256', $full);
                    $sha256 = is_string($hash) ? $hash : null;
                }
            }

            $objects[] = [
                'path' => $path,
                'bytes' => $size,
                'sha256' => $sha256,
            ];
            $totalBytes += $size ?? 0;
        }

        $core = CoreVersion::details();
        $backupMeta = $this->readMetadata($backupMetadataPath);

        $manifest = [
            'format' => 'alohasocial.instance-manifest',
            'version' => 1,
            'created_at' => date('c'),
            'instance_url' => $this->config->instanceUrl,
            'core_version' => $core['version'],
            'core_reference' => $core['reference'],
            'schema_version' => $this->backup->schemaVersion(),
            'secret_key_fingerprint' => SecretKeyFile::fingerprint($this->config),
            'database' => [
                'file' => basename($backupPath),
                'driver' => $backupMeta['driver'] ?? null,
                'sha256' => $backupMeta['sha256'] ?? null,
                'bytes' => $backupMeta['bytes'] ?? null,
                'metadata' => basename($backupMetadataPath),
            ],
            'blobs' => [
                'store' => $this->config->storageDriver,
                'location' => $this->config->storageDriver === 'local'
                    ? $this->config->blobDirectory
                    : ($this->config->s3Bucket ?? '') . ($this->config->s3Prefix !== '' ? '/' . $this->config->s3Prefix : ''),
                'object_count' => count($objects),
                'total_bytes' => $totalBytes,
                'hashes' => $localRoot !== null ? 'sha256' : 'none',
                'objects' => $objects,
            ],
        ];

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Cannot encode the instance manifest.');
        }

        $path = $this->manifestPathFor($backupMetadataPath);
        if (file_put_contents($path, $encoded . "\n") === false) {
            throw new RuntimeException('Cannot write the instance manifest: ' . $path);
        }
        @chmod($path, 0o600);

        return ['path' => $path, 'objects' => count($objects), 'bytes' => $totalBytes];
    }

    /**
     * Read-only check of a manifest against the live instance: the database
     * checksum must match the recorded backup, and every local blob must
     * still hash to its recorded value. Remote stores cannot be re-hashed
     * cheaply and are reported as unchecked.
     *
     * @return array{ok: bool, problems: list<string>, objects: int, unchecked: int}
     */
    public function verify(string $manifestPath): array
    {
        $problems = [];
        $unchecked = 0;

        if (!is_file($manifestPath)) {
            return ['ok' => false, 'problems' => ['The manifest file is missing: ' . $manifestPath], 'objects' => 0, 'unchecked' => 0];
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 'alohasocial.instance-manifest') {
            return ['ok' => false, 'problems' => ['The file is not an Aloha Social instance manifest: ' . $manifestPath], 'objects' => 0, 'unchecked' => 0];
        }

        $objects = $decoded['blobs']['objects'] ?? [];
        if (!is_array($objects)) {
            $objects = [];
        }

        $localRoot = $this->config->storageDriver === 'local' ? rtrim($this->config->blobDirectory, '/') : null;
        $hashed = ($decoded['blobs']['hashes'] ?? null) === 'sha256';

        foreach ($objects as $object) {
            if (!is_array($object) || !is_string($object['path'] ?? null)) {
                continue;
            }
            $path = $object['path'];
            $expected = $object['sha256'] ?? null;

            if ($localRoot === null || !$hashed || !is_string($expected)) {
                $unchecked++;
                continue;
            }

            $full = $localRoot . '/' . $path;
            if (!is_file($full)) {
                $problems[] = 'Missing blob: ' . $path;
                continue;
            }
            $actual = @hash_file('sha256', $full);
            if (!is_string($actual)) {
                $problems[] = 'Unreadable blob: ' . $path;
            } elseif (!hash_equals($expected, $actual)) {
                $problems[] = 'Blob content changed since the manifest: ' . $path;
            }
        }

        $database = $decoded['database'] ?? [];
        $dbFile = is_array($database) && is_string($database['file'] ?? null)
            ? dirname($manifestPath) . '/' . $database['file']
            : null;
        $dbSha = is_array($database) ? ($database['sha256'] ?? null) : null;
        if (is_string($dbSha) && $dbSha !== '') {
            if ($dbFile === null || !is_file($dbFile)) {
                $problems[] = 'The manifest database file is missing.';
            } else {
                $actual = @hash_file('sha256', $dbFile);
                if (!is_string($actual) || !hash_equals($dbSha, $actual)) {
                    $problems[] = 'The manifest database file does not match its recorded checksum.';
                }
            }
        }

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'objects' => count($objects),
            'unchecked' => $unchecked,
        ];
    }

    private function manifestPathFor(string $metadataPath): string
    {
        if (str_ends_with($metadataPath, '.json')) {
            return substr($metadataPath, 0, -5) . '.manifest.json';
        }

        return $metadataPath . '.manifest.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetadata(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
