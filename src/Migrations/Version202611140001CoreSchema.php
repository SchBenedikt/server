<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\FollowerSchema;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\MuteSchema;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Federation\RemoteThreadSchema;
use AlohaSocial\Core\Domain\Media\AttachmentSchema;
use AlohaSocial\Core\Domain\Notification\NotificationSchema;
use AlohaSocial\Core\Domain\Post\BookmarkSchema;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Post\FavouriteSchema;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Timeline\AccountListSchema;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagSchema;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Adopts the core schema.
 *
 * The statements are the core's `*Schema` classes, not a copy: a fresh install
 * and an upgrade of an existing core host converge on the same columns and
 * indexes, and a database dump can move between hosts.
 */
final class Version202611140001CoreSchema implements Migration
{
    /**
     * @return list<class-string>
     */
    public static function schemas(): array
    {
        return [
            PostSchema::class,
            PollSchema::class,
            FavouriteSchema::class,
            BookmarkSchema::class,
            BoostSchema::class,
            NotificationSchema::class,
            AttachmentSchema::class,
            FollowerSchema::class,
            FollowingSchema::class,
            RemotePostSchema::class,
            RemoteThreadSchema::class,
            BlockSchema::class,
            MuteSchema::class,
            AccountListSchema::class,
            FollowedHashtagSchema::class,
            AtprotoPublicationSchema::class,
            JobSchema::class,
            ActivityLogSchema::class,
        ];
    }

    public function version(): string
    {
        return '202611140001';
    }

    public function description(): string
    {
        return 'Adopt the Aloha Social core schema (18 schema classes).';
    }

    public function up(Connection $connection): void
    {
        foreach (self::schemas() as $schema) {
            $schema::create($connection);
        }
    }

    public function down(Connection $connection): void
    {
        foreach (array_reverse(self::schemas()) as $schema) {
            foreach (array_reverse($schema::statements()) as $statement) {
                if (preg_match('/^CREATE TABLE (\w+)/', $statement, $match) === 1) {
                    $connection->execute('DROP TABLE IF EXISTS ' . $match[1]);
                }
            }
        }
    }
}
