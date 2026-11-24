<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Platform\Db\Connection;

/** Re-key publication mappings by linked DID while preserving existing URIs. */
final class Version202611260001AtprotoPublicationIdentity implements Migration
{
	public function version(): string { return '202611260001'; }

	public function description(): string { return 'Key AT Protocol publication mappings by linked identity.'; }

	public function up(Connection $connection): void
	{
		AtprotoPublicationSchema::upgrade($connection);
		CoreSchemaFingerprint::store($connection);
	}

	public function down(Connection $connection): void
	{
		// Multiple identities cannot be collapsed without deleting valid remote
		// records, so this data migration is intentionally forward-only.
	}
}
