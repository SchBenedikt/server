<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

/**
 * The ordered migration list.
 *
 * Adding a migration means adding a class and appending it here; never edit an
 * applied migration, the runner rejects a changed checksum.
 */
final class Migrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [
            new Version202611140001CoreSchema(),
            new Version202611140002HostSchema(),
            new Version202611140003RateLimitSchema(),
            new Version202611140004PasswordResetSchema(),
            new Version202611140005CoreMainSchema(),
            new Version202611150001AdminSchema(),
            new Version202611160001CommunitySchema(),
            new Version202611160002EngagementSchema(),
            new Version202611160003PreferencesSchema(),
            new Version202611160004FollowRequestSchema(),
            new Version202611160005AtprotoAccountSchema(),
            new Version202611160006PinnedPostSchema(),
            new Version202611160007CoreUpdateSchema(),
            new Version202611170001KokuaSchema(),
            new Version202611170002RuntimeErrorSchema(),
        ];
    }
}
