<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\InboundBlockSchema;
use AlohaSocial\Core\Platform\Db\Connection;

/** Persists remote actor blocks independently from local block lists. */
final class Version202611230002InboundBlocks implements Migration
{
	public function version(): string { return '202611230002'; }

	public function description(): string { return 'Store inbound ActivityPub blocks.'; }

	public function up(Connection $connection): void
	{
		InboundBlockSchema::create($connection);
	}

	public function down(Connection $connection): void
	{
		$connection->execute('DROP TABLE IF EXISTS ' . InboundBlockSchema::TABLE);
	}
}
