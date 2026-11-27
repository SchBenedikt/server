<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Bootstrap;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Support\InstanceData;
use JsonException;
use RuntimeException;
use Throwable;

use function bin2hex;
use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function hash_equals;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function parse_url;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function strlen;
use function strtolower;
use function tempnam;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;

/**
 * Binds a database to the persistent data directory and public origin.
 *
 * Docker can accidentally attach the same `var/` volume to a second Compose
 * project, or restore a database under another instance's key. Those mistakes
 * surface as missing accounts, changed actor ids or undecryptable federation
 * keys long after the fact. The installer therefore records one random
 * installation id in both the database and `var/installation.json`, and boot
 * refuses a mismatched pair, wrong signing key or changed public origin on a
 * populated instance.
 *
 * The identity is not secret. It is intentionally small and sanitized so logs
 * can show it without exposing keys or credentials.
 */
final class InstallationIdentity
{
    public const ROW_ID = 'instance';

    public const FILE_NAME = 'installation.json';

    private const ORIGIN_MISMATCH = 'This installation was created for %s, but ALOHASOCIAL_INSTANCE_URL is %s. '
        . 'Restore the original origin. Changing the public origin on a populated instance changes every '
        . 'actor id and WebFinger record; use a new instance with an empty data directory instead.';

    private const PAIR_MISMATCH = 'This data directory and database belong to different installations. '
        . 'Restore var/' . self::FILE_NAME . ' and the database from the same backup; do not attach an existing '
        . 'var volume to a new instance.';

    private const KEY_MISMATCH = 'The instance signing key does not match this installation identity. '
        . 'Restore the matching var/secret.key and database backup; key rotation is not supported on a populated '
        . 'instance.';

    private const FILE_MISSING = 'This populated installation is missing var/' . self::FILE_NAME . '. '
        . 'Restore it with the database from the same backup; do not start a worker with an empty data volume.';

    public function __construct(
        private readonly Connection $db,
        private readonly string $varDirectory,
        private readonly string $instanceUrl,
        private readonly string $publicKeyPem,
        private readonly Clock $clock,
    ) {
    }

    public function path(): string
    {
        return $this->varDirectory . '/' . self::FILE_NAME;
    }

    /**
     * @throws RuntimeException when this data directory, database, key or
     *                          public origin do not belong together
     */
    public function assertCurrent(): void
    {
        $path = $this->path();
        $file = $this->readFile($path);
        $row = $this->row();
        $hasData = $this->hasData();

        if ($row === null) {
            if ($file !== null && $hasData) {
                throw new RuntimeException(self::PAIR_MISMATCH);
            }

            $installationId = $file['installation_id'] ?? bin2hex(random_bytes(16));
            $this->db->execute(
                'INSERT INTO installation_identity (id, installation_id, instance_url, key_fingerprint, created_at)'
                . ' VALUES (:id, :installation_id, :instance_url, :key_fingerprint, :created_at)'
                . ' ON CONFLICT (id) DO NOTHING',
                [
                    'id' => self::ROW_ID,
                    'installation_id' => $installationId,
                    'instance_url' => self::normalizeOrigin($this->instanceUrl),
                    'key_fingerprint' => $this->keyFingerprint(),
                    'created_at' => Time::format($this->clock->now()),
                ],
            );

            // A concurrent worker may have won the insert. Read the committed
            // row, never the value this process tried to write.
            $row = $this->row();
            if ($row === null) {
                throw new RuntimeException('The installation identity could not be initialized.');
            }
        }

        $storedId = (string) $row['installation_id'];
        if ($file !== null && !hash_equals($storedId, (string) $file['installation_id'])) {
            throw new RuntimeException(self::PAIR_MISMATCH);
        }
        if ($file === null) {
            if ($hasData) {
                throw new RuntimeException(self::FILE_MISSING);
            }

            $this->writeFile($path, $storedId);
        }

        if (!hash_equals((string) $row['key_fingerprint'], $this->keyFingerprint())) {
            if ($hasData) {
                throw new RuntimeException(self::KEY_MISMATCH);
            }

            // Before the first account, losing a key only regenerates an
            // unused local key. Do not make an empty instance unbootable.
            $this->db->execute(
                'UPDATE installation_identity SET key_fingerprint = :key_fingerprint WHERE id = :id',
                ['key_fingerprint' => $this->keyFingerprint(), 'id' => self::ROW_ID],
            );
        }

        $storedOrigin = self::normalizeOrigin((string) $row['instance_url']);
        $currentOrigin = self::normalizeOrigin($this->instanceUrl);
        if ($storedOrigin !== $currentOrigin) {
            if ($hasData) {
                throw new RuntimeException(sprintf(
                    self::ORIGIN_MISMATCH,
                    $storedOrigin,
                    $currentOrigin,
                ));
            }

            // An empty instance has no actor ids to break. Setup still writes
            // its chosen origin in the same transaction as the first account.
            $this->db->execute(
                'UPDATE installation_identity SET instance_url = :instance_url WHERE id = :id',
                ['instance_url' => $currentOrigin, 'id' => self::ROW_ID],
            );
        }
    }

    /**
     * The normalised origin is the only mutable identity field, and only setup
     * or an empty instance may move it.
     */
    public static function normalizeOrigin(string $url): string
    {
        $parts = parse_url(rtrim($url, '/'));
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            throw new RuntimeException('The installation origin is not a valid http(s) origin.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ($port !== null && $port !== $defaultPort ? ':' . $port : '');
    }

    private function keyFingerprint(): string
    {
        return hash('sha256', $this->publicKeyPem);
    }

    /**
     * @return array{installation_id: string}|null
     */
    private function readFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read the installation identity file: ' . $path);
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The installation identity file is invalid: ' . $path
                . '. Restore it from the same backup as the database.',
                0,
                $exception,
            );
        }

        $id = is_array($data) ? ($data['installation_id'] ?? null) : null;
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new RuntimeException(
                'The installation identity file is invalid: ' . $path
                . '. Restore it from the same backup as the database.',
            );
        }

        return ['installation_id' => $id];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(): ?array
    {
        return $this->db->fetchOne(
            'SELECT installation_id, instance_url, key_fingerprint FROM installation_identity WHERE id = :id',
            ['id' => self::ROW_ID],
        );
    }

    private function hasData(): bool
    {
        try {
            $accounts = $this->db->fetchValue('SELECT COUNT(*) FROM account');
            if ($accounts !== null && (int) $accounts > 0) {
                return true;
            }
        } catch (Throwable) {
            return true;
        }

        return InstanceData::exists($this->db);
    }

    private function writeFile(string $path, string $installationId): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the installation identity directory: ' . $directory);
        }

        $temporary = tempnam($directory, '.installation-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary installation identity file.');
        }

        try {
            $json = json_encode(
                ['installation_id' => $installationId],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Cannot write the installation identity file: ' . $path);
            }

            @chmod($temporary, 0o600);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot replace the installation identity file: ' . $path);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
