<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/** Persistent Mastodon OAuth clients, single-use authorization codes and tokens. */
final class Version202611250003MastodonOAuthSchema implements Migration
{
    public function version(): string
    {
        return '202611250003';
    }

    public function description(): string
    {
        return 'Create scoped Mastodon OAuth client, grant and token tables.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE mastodon_oauth_app ('
            . ' client_id CHAR(64) NOT NULL PRIMARY KEY,'
            . ' client_secret_hash CHAR(64) NOT NULL,'
            . ' name VARCHAR(200) NOT NULL,'
            . ' redirect_uris TEXT NOT NULL,'
            . ' scopes TEXT NOT NULL,'
            . ' website TEXT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute(
            'CREATE TABLE mastodon_oauth_code ('
            . ' code_hash CHAR(64) NOT NULL PRIMARY KEY,'
            . ' client_id CHAR(64) NOT NULL,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' redirect_uri TEXT NOT NULL,'
            . ' scopes TEXT NOT NULL,'
            . ' code_challenge CHAR(43) NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL,'
            . ' consumed_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX mastodon_oauth_code_client ON mastodon_oauth_code (client_id, expires_at)');
        $connection->execute('CREATE INDEX mastodon_oauth_code_account ON mastodon_oauth_code (account_id)');
        $connection->execute(
            'CREATE TABLE mastodon_oauth_token ('
            . ' access_hash CHAR(64) NOT NULL PRIMARY KEY,'
            . ' refresh_hash CHAR(64) NOT NULL UNIQUE,'
            . ' client_id CHAR(64) NOT NULL,'
            . ' account_id VARCHAR(64) NOT NULL,'
            . ' scopes TEXT NOT NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' expires_at VARCHAR(20) NOT NULL,'
            . ' refresh_expires_at VARCHAR(20) NOT NULL,'
            . ' revoked_at VARCHAR(20) NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX mastodon_oauth_token_account ON mastodon_oauth_token (account_id)');
        $connection->execute('CREATE INDEX mastodon_oauth_token_client ON mastodon_oauth_token (client_id)');
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS mastodon_oauth_token');
        $connection->execute('DROP TABLE IF EXISTS mastodon_oauth_code');
        $connection->execute('DROP TABLE IF EXISTS mastodon_oauth_app');
    }
}
