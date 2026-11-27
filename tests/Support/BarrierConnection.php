<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Support;

use AlohaSocial\Core\Platform\Db\Connection;
use RuntimeException;

/**
 * Waits for a peer process before opening the first transaction.
 *
 * The reset service validates inside its transaction, so synchronizing at the
 * transaction boundary reproduces the old read-before-write race without
 * depending on timing. Both processes then attempt the claim at the same time.
 */
final class BarrierConnection implements Connection
{
    /** @var resource */
    private $socket;

    private bool $waited = false;

    /**
     * @param resource $socket
     */
    public function __construct(
        private readonly Connection $inner,
        $socket,
    ) {
        $this->socket = $socket;
    }

    public function transaction(callable $work): mixed
    {
        if (!$this->waited) {
            $this->waited = true;

            if (fwrite($this->socket, 'B') !== 1) {
                throw new RuntimeException('The concurrency barrier could not signal its peer.');
            }

            $peer = fread($this->socket, 1);
            if ($peer !== 'B') {
                throw new RuntimeException('The concurrency barrier did not hear from its peer.');
            }
        }

        return $this->inner->transaction($work);
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return $this->inner->execute($sql, $parameters);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        return $this->inner->fetchAll($sql, $parameters);
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        return $this->inner->fetchOne($sql, $parameters);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        return $this->inner->fetchValue($sql, $parameters);
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }
}
