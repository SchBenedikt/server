<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

use AlohaSocial\Core\Domain\Federation\LocalActorCount;
use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\LocalActorSearch;

/**
 * The core's view of the local account table.
 *
 * `has()` is the only required port: it lets the actor document answer 404 for
 * an unknown handle instead of publishing a dead actor. Count and search are
 * the optional read-side capabilities the instance endpoint and the local
 * search use.
 */
final class AccountDirectory implements LocalActorDirectory, LocalActorCount, LocalActorSearch
{
    public function __construct(
        private readonly AccountRepository $accounts,
    ) {
    }

    public function has(string $userId): bool
    {
        $account = $this->accounts->find($userId);

        return $account !== null && !$account->isDisabled();
    }

    public function localActorCount(): int
    {
        return $this->accounts->count();
    }

    /**
     * @param list<string>|null $allowedIds
     *
     * @return list<string>
     */
    public function search(string $query, int $limit, int $offset, ?array $allowedIds = null): array
    {
        return $this->accounts->search($query, $limit, $offset, $allowedIds);
    }
}
