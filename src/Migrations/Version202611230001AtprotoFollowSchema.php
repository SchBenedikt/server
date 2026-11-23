<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Platform\Db\Connection;

/** Durable local projection for outbound Bluesky follows. */
final class Version202611230001AtprotoFollowSchema implements Migration
{
	public function version(): string
	{
		return '202611230001';
	}

	public function description(): string
	{
		return 'Create the AT Protocol follow projection table.';
	}

	public function up(Connection $connection): void
	{
		AtprotoFollowSchema::create($connection);
	}

	public function down(Connection $connection): void
	{
		$connection->execute('DROP TABLE IF EXISTS ' . AtprotoFollowSchema::TABLE);
	}
}
