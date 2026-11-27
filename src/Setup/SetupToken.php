<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Setup;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

use function bin2hex;
use function chmod;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function hash_equals;
use function is_file;
use function preg_split;
use function rename;
use function random_bytes;
use function strlen;
use function tempnam;
use function trim;
use function unlink;

/**
 * One-time setup token with a short lifetime.
 *
 * The web setup must not be reachable just because the database happens to be
 * empty: on a fresh public host the first visitor would otherwise own the
 * instance. `bin/setup-token.php` writes this token and prints the matching
 * URL. The file keeps the token and its expiry together; older one-line token
 * files remain readable and never expire until they are replaced.
 */
final class SetupToken
{
    public const DEFAULT_TTL_SECONDS = 3600;

    public const MAX_TTL_SECONDS = 86400;

    private readonly Clock $clock;

    public function __construct(
        private readonly string $path,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function exists(): bool
    {
        $record = $this->record();

        return $record !== null && !$this->expired($record['expires_at']);
    }

    public function generate(int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        if ($ttlSeconds < 1 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new InvalidArgumentException(
                'The setup token lifetime must be between 1 and ' . self::MAX_TTL_SECONDS . ' seconds.',
            );
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = $this->clock->now()->modify('+' . $ttlSeconds . ' seconds');
        $temporary = tempnam(dirname($this->path), '.setup-token-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary setup token file.');
        }
        @chmod($temporary, 0o600);
        $contents = $token . "\n" . Time::format($expiresAt) . "\n";
        if (file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write the setup token: ' . $this->path);
        }

        @chmod($this->path, 0o600);

        return $token;
    }

    /** Serialize setup submissions across PHP workers and processes. */
    public function synchronized(callable $callback): mixed
    {
        $handle = @fopen($this->path . '.lock', 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot open the setup lock file.');
        }
        @chmod($this->path . '.lock', 0o600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire the setup lock.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(): ?string
    {
        return $this->record()['token'] ?? null;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->record()['expires_at'] ?? null;
    }

    public function verify(string $candidate): bool
    {
        $record = $this->record();
        if ($record === null || $this->expired($record['expires_at'])) {
            return false;
        }

        $expected = $record['token'];
        if (strlen($candidate) !== strlen($expected)) {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @return array{token: string, expires_at: DateTimeImmutable|null}|null
     */
    private function record(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $value = file_get_contents($this->path);
        if ($value === false) {
            return null;
        }

        $lines = preg_split('/\R/', trim($value));
        $token = is_array($lines) ? trim((string) ($lines[0] ?? '')) : '';
        if ($token === '') {
            return null;
        }

        $expiresAt = null;
        $expiry = is_array($lines) ? trim((string) ($lines[1] ?? '')) : '';
        if ($expiry !== '') {
            try {
                $expiresAt = Time::parse($expiry);
            } catch (InvalidArgumentException) {
                // A malformed expiry must not turn into a non-expiring token.
                return null;
            }
        }

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    private function expired(?DateTimeImmutable $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt <= $this->clock->now();
    }
}
