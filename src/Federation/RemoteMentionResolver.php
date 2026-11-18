<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Federation\MentionResolver;
use AlohaSocial\Core\Domain\Federation\MentionTarget;

/**
 * Resolves a handle in post text through the same guarded WebFinger and actor
 * fetcher the profile UI uses.
 */
final class RemoteMentionResolver implements MentionResolver
{
    public function __construct(
        private readonly RemoteDirectory $directory,
    ) {
    }

    public function resolve(string $handle): ?MentionTarget
    {
        return $this->directory->mentionTarget($handle);
    }
}
