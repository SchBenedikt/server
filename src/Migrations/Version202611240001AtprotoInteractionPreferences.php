<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/** Per-account consent for mirroring public interactions to AT Protocol. */
final class Version202611240001AtprotoInteractionPreferences implements Migration
{
	public function version(): string
	{
		return '202611240001';
	}

	public function description(): string
	{
		return 'Add opt-in preferences for mirroring likes and boosts to AT Protocol.';
	}

	public function up(Connection $connection): void
	{
		$connection->execute(
			'ALTER TABLE account_preference ADD COLUMN mirror_likes_to_atproto INTEGER NOT NULL DEFAULT 0',
		);
		$connection->execute(
			'ALTER TABLE account_preference ADD COLUMN mirror_boosts_to_atproto INTEGER NOT NULL DEFAULT 0',
		);
	}

	public function down(Connection $connection): void
	{
		$connection->execute('ALTER TABLE account_preference DROP COLUMN mirror_boosts_to_atproto');
		$connection->execute('ALTER TABLE account_preference DROP COLUMN mirror_likes_to_atproto');
	}
}
