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
 * Executes a statement and then throws, so callers can prove that a
 * transaction rolls the already-applied write back.
 */
final class FailingConnection implements Connection
{
    public bool $failed = false;

    public function __construct(
        private readonly Connection $inner,
        private readonly string $needle,
    ) {
    }

    public function transaction(callable $work): mixed
    {
        return $this->inner->transaction($work);
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $affected = $this->inner->execute($sql, $parameters);

        if (!$this->failed && str_contains($sql, $this->needle)) {
            $this->failed = true;

            throw new RuntimeException('Injected database failure after the statement.');
        }

        return $affected;
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
