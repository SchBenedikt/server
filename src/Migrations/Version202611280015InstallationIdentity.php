<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * One immutable installation id, shared by the database and `var/`.
 *
 * The origin and signing-key fingerprints are recorded so a restored database
 * cannot silently be attached to another instance's data directory or public
 * domain. The table holds exactly one row (`id = 'instance'`).
 */
final class Version202611280015InstallationIdentity implements Migration
{
    public function version(): string
    {
        return '202611280015';
    }

    public function description(): string
    {
        return 'Record the installation id, public origin and signing-key fingerprint.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE installation_identity ('
            . ' id VARCHAR(16) NOT NULL PRIMARY KEY,'
            . ' installation_id CHAR(32) NOT NULL,'
            . ' instance_url VARCHAR(255) NOT NULL,'
            . ' key_fingerprint CHAR(64) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE UNIQUE INDEX installation_identity_installation_id ON installation_identity (installation_id)',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS installation_identity');
    }
}
