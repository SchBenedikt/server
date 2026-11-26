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
 * Preflight for the frozen 202611280008 migration.
 *
 * That migration copies legacy `story_seen` rows by story id alone. If a core
 * story already owns the same id, the legacy story is not copied, but the
 * view marker would still be attached to the unrelated core story. The
 * migration file cannot be edited because its checksum is already recorded on
 * existing installations, so the runner removes only those ambiguous legacy
 * view markers first.
 *
 * A view is safe when the core row is the copied host story and the old row
 * still matches it. Everything else at a colliding id is treated as foreign.
 */
final class LegacyStoryViewCollisionGuard
{
    public const MIGRATION_VERSION = '202611280008';

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function prepare(): void
    {
        if (!SchemaUpgrade::tableExists($this->db, 'story_seen')
            || !SchemaUpgrade::tableExists($this->db, StorySchema::TABLE)
        ) {
            return;
        }

        $hasLegacyStories = SchemaUpgrade::tableExists($this->db, 'story');
        $views = $this->db->fetchAll(
            'SELECT seen.story_id, seen.account_id, core.owner_id AS core_owner_id,'
            . ' core.is_local AS core_is_local, core.object_id, core.caption AS core_caption,'
            . ' core.created_at AS core_created_at, core.deleted_at'
            . ' FROM story_seen seen'
            . ' JOIN ' . StorySchema::TABLE . ' core ON core.id = seen.story_id',
        );

        foreach ($views as $view) {
            $storyId = (string) $view['story_id'];
            $legacy = $hasLegacyStories
                ? $this->db->fetchOne(
                    'SELECT account_id, content, created_at FROM story WHERE id = :id',
                    ['id' => $storyId],
                )
                : null;

            if ($this->isCopiedHostStory($storyId, $view, $legacy)) {
                continue;
            }

            $this->db->execute(
                'DELETE FROM story_seen WHERE story_id = :story_id AND account_id = :account_id',
                [
                    'story_id' => $storyId,
                    'account_id' => (string) $view['account_id'],
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $core
     * @param array<string, mixed>|null $legacy
     */
    private function isCopiedHostStory(string $storyId, array $core, ?array $legacy): bool
    {
        if ($legacy === null
            || ($core['deleted_at'] ?? null) !== null
            || (int) ($core['core_is_local'] ?? 0) !== 1
            || (string) ($core['object_id'] ?? '') !== Stories::OBJECT_PREFIX . $storyId
        ) {
            return false;
        }

        return (string) ($core['core_owner_id'] ?? '') === (string) ($legacy['account_id'] ?? '')
            && (string) ($core['core_caption'] ?? '') === (string) ($legacy['content'] ?? '')
            && (string) ($core['core_created_at'] ?? '') === (string) ($legacy['created_at'] ?? '');
    }
}
