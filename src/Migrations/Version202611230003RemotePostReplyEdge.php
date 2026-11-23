<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/** Stores the remote reply edge so signed-in timelines can hide replies before paging. */
final class Version202611230003RemotePostReplyEdge implements Migration
{
	public function version(): string
	{
		return '202611230003';
	}

	public function description(): string
	{
		return 'Store reply targets on imported remote posts.';
	}

	public function up(Connection $connection): void
	{
		SchemaUpgrade::addColumnIfMissing(
			$connection,
			RemotePostSchema::TABLE,
			'in_reply_to_id',
			'VARCHAR(512) NULL',
		);
		CoreSchemaFingerprint::store($connection);
	}

	public function down(Connection $connection): void
	{
		// The core migration is additive; retaining this column preserves the
		// reply relationships for a later upgrade.
	}
}
