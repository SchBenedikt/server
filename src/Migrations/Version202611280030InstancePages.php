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
 * Host-owned public pages (rules, terms, privacy, …) that administrators
 * write and readers see only once published.
 */
final class Version202611280030InstancePages implements Migration
{
    public function version(): string
    {
        return '202611280030';
    }

    public function description(): string
    {
        return 'Create the instance_page table for editable public pages.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE instance_page ('
            . ' slug VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' title VARCHAR(200) NOT NULL,'
            . ' body TEXT NOT NULL,'
            . ' published INTEGER NOT NULL DEFAULT 0,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL,'
            . ' updated_by VARCHAR(64) NOT NULL'
            . ')',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS instance_page');
    }
}
