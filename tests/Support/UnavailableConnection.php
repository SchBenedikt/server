<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests\Support;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;

/**
 * Simulates a database that cannot serve any statement.
 *
 * Security-sensitive callers must fail closed, never treat "could not read the
 * counter" as permission to proceed.
 */
final class UnavailableConnection implements Connection
{
    public function execute(string $sql, array $parameters = []): int
    {
        $this->fail();
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->fail();
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $this->fail();
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $this->fail();
    }

    public function transaction(callable $work): mixed
    {
        $this->fail();
    }

    public function driver(): string
    {
        return 'unavailable';
    }

    private function fail(): never
    {
        throw new DatabaseException('The test database is unavailable.');
    }
}
