<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Structured operational errors for the administration.
 *
 * PHP's error log is useful to an operator with shell access but invisible in
 * the app. The runtime logger mirrors warnings and failures here so delivery,
 * federation and background problems can be reviewed from the admin UI.
 */
final class Version202611170002RuntimeErrorSchema implements Migration
{
    public function version(): string
    {
        return '202611170002';
    }

    public function description(): string
    {
        return 'Create the runtime error log used by the administration.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE runtime_error ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' level VARCHAR(16) NOT NULL,'
            . ' source VARCHAR(120) NULL,'
            . ' message VARCHAR(1000) NOT NULL,'
            . ' context TEXT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX runtime_error_created ON runtime_error (created_at)');
        $connection->execute('CREATE INDEX runtime_error_level ON runtime_error (level, created_at)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS runtime_error');
    }
}
