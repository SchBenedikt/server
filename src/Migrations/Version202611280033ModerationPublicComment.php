<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Moderation\ModerationActionSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Migrations\Migration;

/**
 * Adopts the core `public_comment` column on social_moderation_action so a
 * domain-block listing can publish a moderator-chosen reason while the
 * private note stays private.
 */
final class Version202611280033ModerationPublicComment implements Migration
{
    public function version(): string
    {
        return '202611280033';
    }

    public function description(): string
    {
        return 'Add public_comment to social_moderation_action.';
    }

    public function up(Connection $connection): void
    {
        ModerationActionSchema::upgrade($connection);
    }

    public function down(Connection $connection): void
    {
        // Column drops are not portable across SQLite/PostgreSQL and the
        // column is additive; leaving it in place is harmless.
    }
}
