<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use RuntimeException;

/**
 * A reply to a remote post that cannot be stored or delivered.
 *
 * Unlike the core's ValidationException this also carries the HTTP status,
 * because a missing parent is a 404 while a failed actor lookup is a 502.
 */
final class RemoteReplyException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
