<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation\Atproto;

use AlohaSocial\Core\Platform\Clock\Clock;
use Throwable;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function mkdir;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Exponential backoff for the AT Protocol relay connection.
 *
 * The core already marks a failed pull in the durable checkpoint. This host
 * state adds the part a checkpoint cannot express: how long to wait before
 * the worker tries again. State lives in a small file next to the other
 * runtime files so a relay outage survives a worker restart without hammering
 * the relay on every tick.
 */
final class AtprotoBackoff
{
    public const BASE_SECONDS = 5;

    public const MAX_SECONDS = 300;

    public function __construct(
        private readonly string $file,
        private readonly Clock $clock,
        private readonly int $baseSeconds = self::BASE_SECONDS,
        private readonly int $maxSeconds = self::MAX_SECONDS,
    ) {
    }

    public function ready(): bool
    {
        $state = $this->read();

        return ($state['next_at'] ?? 0) <= $this->clock->now()->getTimestamp();
    }

    /**
     * Records one failure and returns the delay that now applies.
     */
    public function recordFailure(): int
    {
        $attempts = (int) ($this->read()['attempts'] ?? 0) + 1;
        $delay = min(
            max(1, $this->maxSeconds),
            max(1, $this->baseSeconds) * (2 ** min(6, $attempts - 1)),
        );

        $this->write([
            'attempts' => $attempts,
            'next_at' => $this->clock->now()->getTimestamp() + $delay,
        ]);

        return $delay;
    }

    public function recordSuccess(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $contents = file_get_contents($this->file);
        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            $state = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(array $state): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            return;
        }

        try {
            file_put_contents(
                $this->file,
                json_encode($state, JSON_THROW_ON_ERROR) . "\n",
                LOCK_EX,
            );
        } catch (Throwable) {
            // Backoff is an optimisation; a failed write must not stop the
            // worker. The next attempt simply retries immediately.
        }
    }
}
