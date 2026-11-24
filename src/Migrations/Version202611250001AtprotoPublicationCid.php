<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use Throwable;

/** Store the PDS-assigned CID for each published AT Protocol record. */
final class Version202611250001AtprotoPublicationCid implements Migration
{
	private const TABLE = 'social_atproto_publication';

	public function version(): string
	{
		return '202611250001';
	}

	public function description(): string
	{
		return 'Add CIDs to AT Protocol publication mappings.';
	}

	public function up(Connection $connection): void
	{
		if (!$this->columnExists($connection)) {
			$connection->execute('ALTER TABLE ' . self::TABLE . ' ADD COLUMN cid VARCHAR(256) NULL');
		}

		CoreSchemaFingerprint::store($connection);
	}

	public function down(Connection $connection): void
	{
		if ($this->columnExists($connection)) {
			$connection->execute('ALTER TABLE ' . self::TABLE . ' DROP COLUMN cid');
		}

		CoreSchemaFingerprint::store($connection);
	}

	private function columnExists(Connection $connection): bool
	{
		try {
			if ($connection->driver() === 'sqlite') {
				foreach ($connection->fetchAll('PRAGMA table_info(' . self::TABLE . ')') as $row) {
					if (($row['name'] ?? null) === 'cid') {
						return true;
					}
				}

				return false;
			}

			return $connection->fetchValue(
				'SELECT column_name FROM information_schema.columns'
				. ' WHERE table_schema = ? AND table_name = ? AND column_name = ?',
				['public', self::TABLE, 'cid'],
			) !== null;
		} catch (Throwable) {
			return false;
		}
	}
}
