<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Support;

use AlohaSocial\Core\Platform\Db\Connection;

final class CountingConnection implements Connection
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private readonly Connection $inner)
    {
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->queries[] = $sql;

        return $this->inner->execute($sql, $parameters);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->queries[] = $sql;

        return $this->inner->fetchAll($sql, $parameters);
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $this->queries[] = $sql;

        return $this->inner->fetchOne($sql, $parameters);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $this->queries[] = $sql;

        return $this->inner->fetchValue($sql, $parameters);
    }

    public function transaction(callable $work): mixed
    {
        return $this->inner->transaction(fn (): mixed => $work($this));
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }
}
