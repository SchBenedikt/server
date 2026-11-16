<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Domain\Atproto\AtprotoInboundSchema;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsSchema;
use AlohaSocial\Core\Domain\Collection\MediaCollectionSchema;
use AlohaSocial\Core\Domain\Federation\AccountMoveSchema;
use AlohaSocial\Core\Domain\Moderation\HeldPostSchema;
use AlohaSocial\Core\Domain\Moderation\ModerationActionSchema;
use AlohaSocial\Core\Domain\Moderation\ReportSchema;
use AlohaSocial\Core\Domain\Notification\ConversationMuteSchema;
use AlohaSocial\Core\Domain\Notification\NotificationPolicySchema;
use AlohaSocial\Core\Domain\Notification\NotificationSchema;
use AlohaSocial\Core\Domain\Notification\NotificationSenderSchema;
use AlohaSocial\Core\Domain\Post\PostPinSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSchema;
use AlohaSocial\Core\Domain\Search\SearchIndexSchema;
use AlohaSocial\Core\Domain\Search\TrendSchema;
use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Brings an existing installation up to the current core surface.
 *
 * The core added columns to posts, followers and notifications and introduced
 * tables for pins, collections, account moves, moderation, stories,
 * recommendations, discovery, inbound AT Protocol sync, account deletion and
 * the optional native PDS. The host still owns the migration, so this class
 * runs the core schema classes instead of copying their DDL.
 *
 * Every step is additive and checks the catalogue first. No account, post or
 * queue row is deleted, and running the migration twice is a no-op. That is
 * the property the update path relies on: a new release may add tables and
 * columns, but it must never require recreating an account.
 */
final class Version202611160007CoreUpdateSchema implements Migration
{
    /**
     * New tables that did not exist in the 0.8.0 host schema.
     *
     * @return list<class-string>
     */
    public static function newSchemas(): array
    {
        return [
            NotificationPolicySchema::class,
            NotificationSenderSchema::class,
            ConversationMuteSchema::class,
            PostPinSchema::class,
            MediaCollectionSchema::class,
            AccountMoveSchema::class,
            ReportSchema::class,
            ModerationActionSchema::class,
            HeldPostSchema::class,
            StorySchema::class,
            RecommendationSchema::class,
            SearchIndexSchema::class,
            TrendSchema::class,
            AtprotoInboundSchema::class,
            AccountDeletionSchema::class,
            PdsSchema::class,
        ];
    }

    public function version(): string
    {
        return '202611160007';
    }

    public function description(): string
    {
        return 'Adopt the newer core schema without touching existing accounts or posts.';
    }

    public function up(Connection $connection): void
    {
        $this->addCoreColumns($connection);

        // Account deletion has its own idempotent upgrade path.
        AccountDeletionSchema::upgrade($connection);

        // The queue gained subject columns used by account deletion and the
        // durable follow lifecycle. Existing queue rows must survive.
        JobSchema::upgrade($connection);

        SchemaUpgrade::createIndexIfMissing(
            $connection,
            NotificationSchema::TABLE,
            NotificationSchema::TABLE . '_visible',
            'recipient_id, status, created_at, id',
        );

        foreach (self::newSchemas() as $schema) {
            if ($schema === AccountDeletionSchema::class) {
                continue;
            }

            $this->createSchema($connection, $schema);
        }
    }

    public function down(Connection $connection): void
    {
        foreach (array_reverse(self::newSchemas()) as $schema) {
            if ($schema === AccountDeletionSchema::class) {
                $connection->execute('DROP TABLE IF EXISTS ' . AccountDeletionSchema::TABLE);

                continue;
            }

            foreach (array_reverse($schema::statements()) as $statement) {
                if (preg_match('/^CREATE TABLE (\w+)/', $statement, $match) === 1) {
                    $connection->execute('DROP TABLE IF EXISTS ' . $match[1]);
                }
            }
        }
    }

    private function addCoreColumns(Connection $connection): void
    {
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            PostSchema::TABLE,
            'archived_at',
            'VARCHAR(20) NULL',
        );

        SchemaUpgrade::addColumnIfMissing(
            $connection,
            'social_follower',
            'state',
            "VARCHAR(16) NOT NULL DEFAULT 'accepted'",
        );
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            'social_follower',
            'follow_activity',
            'TEXT NULL',
        );

        SchemaUpgrade::addColumnIfMissing(
            $connection,
            NotificationSchema::TABLE,
            'status',
            'VARCHAR(16) NULL',
        );
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            NotificationSchema::TABLE,
            'conversation_id',
            'VARCHAR(512) NULL',
        );
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            NotificationSchema::TABLE,
            'policy_category',
            'VARCHAR(16) NULL',
        );
        SchemaUpgrade::addColumnIfMissing(
            $connection,
            NotificationSchema::TABLE,
            'policy_action',
            'VARCHAR(16) NULL',
        );
    }

    /**
     * @param class-string $schema
     */
    private function createSchema(Connection $connection, string $schema): void
    {
        $statements = $schema::statements();
        $table = $this->tableName($statements);

        if ($table !== null && SchemaUpgrade::tableExists($connection, $table)) {
            return;
        }

        foreach ($statements as $statement) {
            $connection->execute($statement);
        }
    }

    /**
     * @param list<string> $statements
     */
    private function tableName(array $statements): ?string
    {
        foreach ($statements as $statement) {
            if (preg_match('/^CREATE TABLE (\w+)/', $statement, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
