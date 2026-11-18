<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

use RuntimeException;

final class RegistrationException extends RuntimeException
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        string $message,
        public readonly int $status = 409,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
