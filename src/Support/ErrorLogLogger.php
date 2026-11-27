<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logging to the SAPI error log.
 *
 * The core logs job failures and unhandled errors through this port. Nothing
 * here interpolates the context blindly: only the placeholder values are
 * substituted, and callers never pass request bodies, private keys or sealed
 * payloads.
 */
final class ErrorLogLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        error_log(
            '[' . (is_string($level) ? $level : 'info') . '] '
            . LogRedactor::message((string) $message, $context),
        );
    }
}
