<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Conversation\ConversationMarkerSchema;
use AlohaSocial\Core\Platform\Db\Connection;

/** Persist per-account read and dismissal watermarks for direct-message threads. */
final class Version202611280002ConversationMarkers implements Migration
{
	public function version(): string
	{
		return '202611280002';
	}

	public function description(): string
	{
		return 'Add per-account direct-message conversation markers.';
	}

	public function up(Connection $connection): void
	{
		ConversationMarkerSchema::create($connection);
		CoreSchemaFingerprint::store($connection);
	}

	public function down(Connection $connection): void
	{
		$connection->execute('DROP TABLE ' . ConversationMarkerSchema::TABLE);
		CoreSchemaFingerprint::store($connection);
	}
}
