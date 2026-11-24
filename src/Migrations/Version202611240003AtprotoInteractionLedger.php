<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use Throwable;

/** Persist PDS record IDs for outbound likes and reposts. */
final class Version202611240003AtprotoInteractionLedger implements Migration
{
	public function version(): string
	{
		return '202611240003';
	}

	public function description(): string
	{
		return 'Add the AT Protocol interaction ledger.';
	}

	public function up(Connection $connection): void
	{
		if (!$this->tableExists($connection)) {
			AtprotoInteractionSchema::create($connection);
		}
		CoreSchemaFingerprint::store($connection);
	}

	public function down(Connection $connection): void
	{
		$connection->execute('DROP TABLE IF EXISTS ' . AtprotoInteractionSchema::TABLE);
		CoreSchemaFingerprint::store($connection);
	}

	private function tableExists(Connection $connection): bool
	{
		try {
			if ($connection->driver() === 'sqlite') {
				return $connection->fetchValue(
					"SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
					[AtprotoInteractionSchema::TABLE],
				) !== null;
			}

			return $connection->fetchValue(
				"SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
				[AtprotoInteractionSchema::TABLE],
			) !== null;
		} catch (Throwable) {
			return false;
		}
	}
}
