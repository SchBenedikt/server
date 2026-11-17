<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The Kokua board: neighbourly requests, offers and who has offered a hand.
 *
 * A Kokua is not a post. It never enters a timeline and is not federated; it
 * is a first-party community surface where people can ask for practical help
 * or offer it, and the author can mark the exchange as done.
 */
final class Version202611170001KokuaSchema implements Migration
{
    public function version(): string
    {
        return '202611170001';
    }

    public function description(): string
    {
        return 'Create the Kokua help board and its offers.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE kokua ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL,'
            . ' title VARCHAR(100) NOT NULL,'
            . ' details VARCHAR(1000) NOT NULL DEFAULT \'\','
            . ' category VARCHAR(32) NOT NULL,'
            . ' place VARCHAR(80) NOT NULL DEFAULT \'\','
            . ' needed_by VARCHAR(20) NULL,'
            . ' status VARCHAR(16) NOT NULL DEFAULT \'open\','
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL,'
            . ' done_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX kokua_board ON kokua (status, created_at)');
        $connection->execute('CREATE INDEX kokua_account ON kokua (account_id, created_at)');

        $connection->execute(
            'CREATE TABLE kokua_help ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' kokua_id VARCHAR(64) NOT NULL,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' message VARCHAR(300) NOT NULL DEFAULT \'\','
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' UNIQUE (kokua_id, account_id)'
            . ')',
        );
        $connection->execute('CREATE INDEX kokua_help_board ON kokua_help (kokua_id, created_at)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS kokua_help');
        $connection->execute('DROP TABLE IF EXISTS kokua');
    }
}
