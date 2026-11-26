<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;
use AlohaSocial\Server\Community\Stories;

/**
 * Repairs story view markers left behind by the collections/stories move.
 *
 * The frozen 202611280008 migration restores legacy `story_seen` rows by
 * story id. A view whose story was already soft-deleted cannot be represented
 * in the old table, so 008's rollback would otherwise create an orphan row.
 * This additive migration removes those markers before the frozen rollback
 * runs, and cleans existing core markers for deleted host stories on upgrade.
 */
final class Version202611280009RepairStoryViews implements Migration
{
    public function version(): string
    {
        return '202611280009';
    }

    public function description(): string
    {
        return 'Remove story view markers without a live host story.';
    }

    public function up(Connection $connection): void
    {
        if (!SchemaUpgrade::tableExists($connection, StorySchema::VIEW_TABLE)
            || !SchemaUpgrade::tableExists($connection, StorySchema::TABLE)
        ) {
            return;
        }

        // A view without any story is unusable, regardless of its origin.
        $connection->execute(
            'DELETE FROM ' . StorySchema::VIEW_TABLE
            . ' WHERE story_id NOT IN (SELECT id FROM ' . StorySchema::TABLE . ')',
        );

        $this->deleteDeletedHostStoryViews($connection);

        $this->deleteOrphanedLegacyStoryViews($connection);
    }

    public function down(Connection $connection): void
    {
        // The migration runner unwinds 009 before the frozen 008 rollback. The
        // frozen copy step would otherwise turn these markers into orphaned
        // `story_seen` rows in the recreated legacy table.
        $this->deleteDeletedHostStoryViews($connection);
        $this->deleteOrphanedLegacyStoryViews($connection);
    }

    private function deleteOrphanedLegacyStoryViews(Connection $connection): void
    {
        // A partial or manual rollback can leave the legacy table behind.
        if (!SchemaUpgrade::tableExists($connection, 'story_seen')
            || !SchemaUpgrade::tableExists($connection, 'story')
        ) {
            return;
        }

        $connection->execute(
            'DELETE FROM story_seen WHERE story_id NOT IN (SELECT id FROM story)',
        );
    }

    private function deleteDeletedHostStoryViews(Connection $connection): void
    {
        if (!SchemaUpgrade::tableExists($connection, StorySchema::VIEW_TABLE)
            || !SchemaUpgrade::tableExists($connection, StorySchema::TABLE)
        ) {
            return;
        }

        $connection->execute(
            'DELETE FROM ' . StorySchema::VIEW_TABLE
            . ' WHERE story_id IN (SELECT id FROM ' . StorySchema::TABLE
            . ' WHERE object_id LIKE :object_prefix AND deleted_at IS NOT NULL)',
            ['object_prefix' => Stories::OBJECT_PREFIX . '%'],
        );
    }
}
