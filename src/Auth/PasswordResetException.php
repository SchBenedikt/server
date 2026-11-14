<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use RuntimeException;

/**
 * A reset request that must not change the password.
 *
 * The message is safe to show on the reset page; the reason is for tests and
 * logs. Nothing here reveals whether an account exists.
 */
final class PasswordResetException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
