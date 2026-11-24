<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/** Stores the member's explicit AT Protocol identity choice for interactions. */
final class Version202611270001AtprotoInteractionAccount implements Migration
{
	public function version(): string
	{
		return '202611270001';
	}

	public function description(): string
	{
		return 'Store the selected AT Protocol identity for mirrored interactions.';
	}

	public function up(Connection $connection): void
	{
		$connection->execute('ALTER TABLE account_preference ADD COLUMN mirror_atproto_account_did TEXT NULL');
	}

	public function down(Connection $connection): void
	{
		$connection->execute('ALTER TABLE account_preference DROP COLUMN mirror_atproto_account_did');
	}
}
