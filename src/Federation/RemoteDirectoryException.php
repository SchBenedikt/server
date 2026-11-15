<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use RuntimeException;

/**
 * A remote profile could not be resolved.
 *
 * `errorCode` is stable for the UI and the API response; the message is what
 * the reader sees in a toast or an error panel.
 */
final class RemoteDirectoryException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 502,
    ) {
        parent::__construct($message);
    }
}
