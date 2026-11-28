<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Migrations\Migration;

/**
 * Per-account announcement dismissal: a JSON list of announcement ids the
 * signed-in user chose to hide, so dismissals follow the account instead of
 * one browser's localStorage.
 */
final class Version202611280036DismissedAnnouncements implements Migration
{
    public function version(): string
    {
        return '202611280036';
    }

    public function description(): string
    {
        return 'Store per-account dismissed announcement ids in account_preference.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'ALTER TABLE account_preference ADD COLUMN dismissed_announcements TEXT NULL',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('ALTER TABLE account_preference DROP COLUMN dismissed_announcements');
    }
}
