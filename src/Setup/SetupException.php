<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Setup;

use RuntimeException;

/**
 * A refused setup submission with field-level messages for the form.
 */
final class SetupException extends RuntimeException
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
