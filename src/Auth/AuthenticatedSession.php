<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Server\Accounts\Account;

/**
 * A resolved request identity: the session and, when signed in, its account.
 */
final readonly class AuthenticatedSession
{
    public function __construct(
        public SessionRecord $session,
        public ?Account $account,
    ) {
    }

    public function isSignedIn(): bool
    {
        return $this->account !== null;
    }

    public function userId(): ?string
    {
        return $this->account?->id;
    }
}
