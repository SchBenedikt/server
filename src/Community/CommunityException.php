<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use RuntimeException;

/**
 * A refused community mutation or an unavailable record.
 *
 * The HTTP layer maps status, code and field errors directly; the service
 * stays free of HTTP types so the same code can be used by a future CLI.
 */
final class CommunityException extends RuntimeException
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function invalid(string $message, array $fields = []): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'forbidden', $message);
    }
}
