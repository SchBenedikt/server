<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * One host migration.
 *
 * The core owns the table definitions (`*Schema`); the host owns the runner,
 * the version table and the order in which schema classes are applied.
 */
interface Migration
{
    public function version(): string;

    public function description(): string;

    public function up(Connection $connection): void;

    public function down(Connection $connection): void;
}
