<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Setup;

use JsonException;
use RuntimeException;

use function array_key_exists;
use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function strlen;
use function tempnam;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Recovery record for an in-flight web setup.
 *
 * The database transaction covers the account and audit row, but the `.env`
 * write cannot join it. This journal stores the previous file contents before
 * the first mutation and is removed only after the transaction commits. After
 * a crash, {@see SetupService} can either finish the committed setup or put
 * the previous environment back. It deliberately contains no password, hash
 * or setup token.
 */
final class SetupJournal
{
    public const VERSION = 1;

    public function __construct(
        private readonly string $path,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            throw new RuntimeException('Cannot read the setup journal.');
        }

        try {
            $journal = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The setup journal is not valid JSON.', 0, $exception);
        }

        if (!is_array($journal)
            || ($journal['version'] ?? null) !== self::VERSION
            || !is_string($journal['env_file'] ?? null)
            || !array_key_exists('env_before', $journal)
            || (!is_string($journal['env_before']) && $journal['env_before'] !== null)
            || !is_array($journal['settings'] ?? null)
        ) {
            throw new RuntimeException('The setup journal is incomplete.');
        }

        return $journal;
    }

    /**
     * @param array<string, mixed> $journal
     */
    public function write(array $journal): void
    {
        $journal['version'] = self::VERSION;
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the setup journal directory: ' . $directory);
        }

        try {
            $body = json_encode(
                $journal,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode the setup journal.', 0, $exception);
        }

        $temporary = tempnam($directory, '.setup-journal-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary setup journal.');
        }

        @chmod($temporary, 0o600);
        if (file_put_contents($temporary, $body) !== strlen($body) || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write the setup journal: ' . $this->path);
        }

        @chmod($this->path, 0o600);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
