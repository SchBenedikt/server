<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use RuntimeException;

/**
 * Boot-time schema refusal. Carries only a coarse kind so the HTTP layer can
 * answer "upgrade in progress" without leaking migration names or versions.
 */
final class SchemaNotReady extends RuntimeException
{
    public const KIND_INSTALL = 'install';
    public const KIND_PENDING = 'pending';
    public const KIND_FINGERPRINT = 'fingerprint';

    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
