<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

/**
 * A freshly issued session, including the only copy of the raw cookie value.
 */
final readonly class SessionIssue
{
    public function __construct(
        public string $rawId,
        public SessionRecord $session,
        public int $expiresAt,
    ) {
    }
}
