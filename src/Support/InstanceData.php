<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use AlohaSocial\Core\Platform\Db\Connection;
use Throwable;

/**
 * Content that must survive an account deletion.
 *
 * A setup or identity check that only looks at `account` would treat an
 * instance whose last account was erased as empty, even though its posts,
 * remote cache and federation edges still carry the old actor ids.
 */
final class InstanceData
{
    /**
     * Tables that prove this installation has content. A missing table counts
     * as data: callers fail closed instead of exposing a half-migrated store.
     */
    public const TABLES = [
        'social_post',
        'social_remote_post',
        'social_following',
        'social_follower',
        'social_media_collection',
        'social_story',
        'kokua',
        'draft',
        'announcement',
        'social_attachment',
        'social_reaction',
        'social_bookmark',
        'social_favourite',
        'social_boost',
        'social_notification',
        'social_account_list',
        'featured_tag',
        'follow_request',
        'moderation_report',
        'social_report',
    ];

    public static function exists(Connection $db): bool
    {
        foreach (self::TABLES as $table) {
            try {
                $count = $db->fetchValue('SELECT COUNT(*) FROM ' . $table);
            } catch (Throwable) {
                return true;
            }

            if ($count !== null && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
