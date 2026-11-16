<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use RuntimeException;

/**
 * A refused or failed Bluesky connection.
 *
 * The HTTP layer maps `status` and `errorCode` directly; the service keeps no
 * HTTP types so the same code can be driven from a CLI or a worker.
 */
final class BlueskyException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
