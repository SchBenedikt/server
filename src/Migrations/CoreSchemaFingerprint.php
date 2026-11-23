<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use RuntimeException;

use function hash;
use function implode;

/**
 * A stable hash of the Core schema statements the host applies.
 *
 * A migration checksum only covers the host migration file. This fingerprint
 * covers the Core `*Schema::statements()` result, so a Core update that changes
 * a table without a matching host migration fails at boot with a clear message
 * instead of silently giving fresh and upgraded databases different columns.
 */
final class CoreSchemaFingerprint
{
    public const STATE_NAME = 'core_schema_fingerprint';

    /**
     * Every Core-owned schema class the host applies, including the tables
     * added by later migrations. Duplicates are collapsed by class name.
     *
     * @return list<class-string>
     */
    public static function schemas(): array
    {
        $classes = array_merge(
            Version202611140001CoreSchema::schemas(),
            Version202611160007CoreUpdateSchema::newSchemas(),
            [
                \AlohaSocial\Core\Domain\Federation\PendingRemoteUpdateSchema::class,
                \AlohaSocial\Core\Domain\Federation\RemotePollSchema::class,
                \AlohaSocial\Core\Domain\Filter\FilterSchema::class,
                \AlohaSocial\Core\Domain\Interaction\ReactionSchema::class,
                \AlohaSocial\Core\Domain\Post\QuoteGrantSchema::class,
                \AlohaSocial\Core\Domain\Scheduling\ScheduledPostSchema::class,
                \AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema::class,
            ],
        );

        return array_values(array_unique($classes));
    }

    public static function current(): string
    {
        $statements = [];
        foreach (self::schemas() as $schema) {
            foreach ($schema::statements() as $statement) {
                $statements[] = $statement;
            }
        }

        return hash('sha256', implode("\n", $statements));
    }

    public static function assertCurrent(Connection $connection): void
    {
        $stored = $connection->fetchValue(
            'SELECT value FROM runtime_state WHERE name = :name',
            ['name' => self::STATE_NAME],
        );

        if (!is_string($stored) || $stored === '') {
            throw new RuntimeException(
                'The Core schema fingerprint is missing. Run: php bin/update.php',
            );
        }

        if (!hash_equals($stored, self::current())) {
            throw new RuntimeException(
                'The installed Core schema differs from the current Core definitions.'
                . ' Add a host migration for the schema change and run: php bin/update.php',
            );
        }
    }

    public static function store(Connection $connection): void
    {
        $now = \AlohaSocial\Core\Support\Time::format(new \DateTimeImmutable());

        $updated = $connection->execute(
            'UPDATE runtime_state SET value = :value, updated_at = :updated WHERE name = :name',
            ['value' => self::current(), 'updated' => $now, 'name' => self::STATE_NAME],
        );

        if ($updated === 0) {
            $connection->execute(
                'INSERT INTO runtime_state (name, value, updated_at) VALUES (:name, :value, :updated)',
                ['name' => self::STATE_NAME, 'value' => self::current(), 'updated' => $now],
            );
        }
    }
}
