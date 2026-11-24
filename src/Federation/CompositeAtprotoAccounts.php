<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountCollectionResolver;

/**
 * One resolver for an instance that offers both identity models.
 *
 * A member with a native identity publishes into the repository this instance
 * hosts. Only a member without one falls back to a connected Bluesky account,
 * so a post is never written to both repositories by accident.
 */
final class CompositeAtprotoAccounts implements AtprotoAccountCollectionResolver
{
    public function __construct(
        private readonly AtprotoAccountResolver $native,
        private readonly AtprotoAccountResolver $external,
    ) {
    }

    public function resolve(string $localUserId): ?AtprotoAccount
    {
        $account = $this->native->resolve($localUserId);

        return $account ?? $this->external->resolve($localUserId);
    }

    /** @return list<AtprotoAccount> */
    public function resolveAll(string $localUserId): array
    {
        $accounts = [];
        foreach ([$this->native->resolve($localUserId), $this->external->resolve($localUserId)] as $account) {
            if ($account !== null) {
                $accounts[$account->did] = $account;
            }
        }

        return array_values($accounts);
    }
}
