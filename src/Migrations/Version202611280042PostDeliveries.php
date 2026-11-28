<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Federation\PostDeliverySchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Creates the per-delivery store behind `GET /api/v1/posts/{id}/delivery`.
 *
 * Core #251 records one row per queued post delivery; without the table the
 * worker's delivery tracker and the author-facing endpoint both fail.
 * The step is idempotent and refreshes the schema fingerprint because the
 * schema class joins the fingerprinted set.
 */
final class Version202611280042PostDeliveries implements Migration
{
    public function version(): string
    {
        return '202611280042';
    }

    public function description(): string
    {
        return 'Create social_post_delivery for per-post federation delivery state.';
    }

    public function up(Connection $connection): void
    {
        if (!SchemaUpgrade::tableExists($connection, PostDeliverySchema::TABLE)) {
            PostDeliverySchema::create($connection);
        }

        CoreSchemaFingerprint::store($connection);
    }

    public function down(Connection $connection): void
    {
        // Delivery rows are the only durable record of what reached which
        // inbox; dropping them would erase delivery history, so the table
        // stays. The fingerprint already covers it.
    }
}
