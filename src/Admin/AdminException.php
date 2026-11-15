<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Admin;

use RuntimeException;

use function is_array;

/**
 * A refused administration or moderation request.
 *
 * The HTTP layer maps status, code and field errors directly; keeping the
 * exception free of HTTP types lets the service stay usable from the CLI too.
 */
final class AdminException extends RuntimeException
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

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
