<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;
use AlohaSocial\Server\Migrations\Migration;

/**
 * Lets a draft carry the post language the composer picked, matching the
 * `language` field posts and scheduled posts already accept.
 */
final class Version202611280034DraftLanguage implements Migration
{
    public function version(): string
    {
        return '202611280034';
    }

    public function description(): string
    {
        return 'Add language to drafts.';
    }

    public function up(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing($connection, 'draft', 'language', 'VARCHAR(35) NULL');
    }

    public function down(Connection $connection): void
    {
        // Column drops are not portable across SQLite/PostgreSQL and the
        // column is additive; leaving it in place is harmless.
    }
}
