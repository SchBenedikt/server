<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/** Short-lived ranked-feed snapshots keep later pages on the same ranking. */
final class Version202611260002RecommendationSnapshots implements Migration
{
	public function version(): string
	{
		return '202611260002';
	}

	public function description(): string
	{
		return 'Create short-lived ranked timeline snapshots.';
	}

	public function up(Connection $connection): void
	{
		$connection->execute(
			'CREATE TABLE recommendation_snapshot ('
			. ' id_hash VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' account_id VARCHAR(64) NULL,'
			. ' feed_kind VARCHAR(16) NOT NULL,'
			. ' payload TEXT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' expires_at VARCHAR(20) NOT NULL'
			. ')',
		);
		$connection->execute(
			'CREATE INDEX recommendation_snapshot_scope ON recommendation_snapshot (account_id, feed_kind, created_at)',
		);
		$connection->execute(
			'CREATE INDEX recommendation_snapshot_expiry ON recommendation_snapshot (expires_at)',
		);
	}

	public function down(Connection $connection): void
	{
		$connection->execute('DROP TABLE IF EXISTS recommendation_snapshot');
	}
}
