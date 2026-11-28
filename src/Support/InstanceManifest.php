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
