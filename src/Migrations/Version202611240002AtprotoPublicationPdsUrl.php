<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use Throwable;

/** Store the publisher's PDS for cross-account AT Protocol references. */
final class Version202611240002AtprotoPublicationPdsUrl implements Migration
{
	private const TABLE = 'social_atproto_publication';

	public function version(): string
	{
		return '202611240002';
	}

	public function description(): string
	{
		return 'Add the publisher PDS URL to AT Protocol publication records.';
	}

	public function up(Connection $connection): void
	{
		if (!$this->columnExists($connection)) {
			$connection->execute('ALTER TABLE ' . self::TABLE . ' ADD COLUMN pds_url VARCHAR(512) NULL');
		}
	}

	public function down(Connection $connection): void
	{
		if ($this->columnExists($connection)) {
			$connection->execute('ALTER TABLE ' . self::TABLE . ' DROP COLUMN pds_url');
		}
	}

	private function columnExists(Connection $connection): bool
	{
		try {
			if ($connection->driver() === 'sqlite') {
				foreach ($connection->fetchAll('PRAGMA table_info(' . self::TABLE . ')') as $row) {
					if (($row['name'] ?? null) === 'pds_url') {
						return true;
					}
				}

				return false;
			}

			return $connection->fetchValue(
				'SELECT column_name FROM information_schema.columns'
				. ' WHERE table_schema = ? AND table_name = ? AND column_name = ?',
				['public', self::TABLE, 'pds_url'],
			) !== null;
		} catch (Throwable) {
			return false;
		}
	}
}
