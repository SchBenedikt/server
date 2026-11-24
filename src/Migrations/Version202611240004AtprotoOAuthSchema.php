<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/** Short-lived OAuth handshakes and account-bound DPoP metadata. */
final class Version202611240004AtprotoOAuthSchema implements Migration
{
	public function version(): string
	{
		return '202611240004';
	}

	public function description(): string
	{
		return 'Store encrypted AT Protocol OAuth flow and DPoP key metadata.';
	}

	public function up(Connection $connection): void
	{
		$connection->execute(
			'CREATE TABLE atproto_oauth_account ('
			. ' account_id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' issuer VARCHAR(255) NOT NULL,'
			. ' token_endpoint VARCHAR(2048) NOT NULL,'
			. ' client_id VARCHAR(512) NOT NULL,'
			. ' scope VARCHAR(1024) NOT NULL,'
			. ' dpop_private_key TEXT NOT NULL'
			. ')',
		);
		$connection->execute(
			'CREATE TABLE atproto_oauth_flow ('
			. ' state_hash CHAR(64) NOT NULL PRIMARY KEY,'
			. ' account_id VARCHAR(64) NOT NULL,'
			. ' browser_session_hash CHAR(64) NOT NULL,'
			. ' payload TEXT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' expires_at VARCHAR(20) NOT NULL'
			. ')',
		);
		$connection->execute('CREATE INDEX atproto_oauth_flow_expiry ON atproto_oauth_flow (expires_at)');
	}

	public function down(Connection $connection): void
	{
		$connection->execute('DROP TABLE IF EXISTS atproto_oauth_flow');
		$connection->execute('DROP TABLE IF EXISTS atproto_oauth_account');
	}
}
