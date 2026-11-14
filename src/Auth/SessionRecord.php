<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

/**
 * One stored session.
 *
 * `idHash` is the SHA-256 of the cookie value; the raw value exists only in
 * the browser and in the response that set it. An anonymous session (no
 * account) exists so the login form has a CSRF token before anyone is signed
 * in; it is rotated on login.
 */
final readonly class SessionRecord
{
    public function __construct(
        public string $idHash,
        public ?string $accountId,
        public string $csrfToken,
        public string $createdAt,
        public string $expiresAt,
    ) {
    }

    public function isExpired(string $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
