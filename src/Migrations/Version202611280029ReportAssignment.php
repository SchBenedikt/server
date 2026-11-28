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
 * Lets administrators assign an open report to a specific admin account so a
 * review queue has an owner before it is resolved or dismissed.
 */
final class Version202611280029ReportAssignment implements Migration
{
    public function version(): string
    {
        return '202611280029';
    }

    public function description(): string
    {
        return 'Add report assignment columns to moderation_report.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute('ALTER TABLE moderation_report ADD COLUMN assigned_to VARCHAR(64) NULL');
        $connection->execute('ALTER TABLE moderation_report ADD COLUMN assigned_at VARCHAR(20) NULL');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('ALTER TABLE moderation_report DROP COLUMN assigned_at');
        $connection->execute('ALTER TABLE moderation_report DROP COLUMN assigned_to');
    }
}
