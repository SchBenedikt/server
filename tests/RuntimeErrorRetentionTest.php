<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Admin\RuntimeErrorRetention;
use AlohaSocial\Server\Tests\Support\TestServer;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class RuntimeErrorRetentionTest extends TestCase
{
    public function testPurgeIsDryRunnableBoundedAndLeavesTheAuditTrailUntouched(): void
    {
        $server = new TestServer();
        $db = $server->runtime->database;
        $now = $server->runtime->clock->now();
        foreach ([
            ['old-a', Time::format($now->modify('-120 days'))],
            ['old-b', Time::format($now->modify('-100 days'))],
            ['recent', Time::format($now->modify('-30 days'))],
        ] as [$id, $createdAt]) {
            $db->execute(
                'INSERT INTO runtime_error (id, level, source, message, context, created_at)'
                    . ' VALUES (:id, :level, :source, :message, :context, :created_at)',
                [
                    'id' => $id,
                    'level' => 'warning',
                    'source' => 'test',
                    'message' => 'test error',
                    'context' => null,
                    'created_at' => $createdAt,
                ],
            );
        }
        $db->execute(
            'INSERT INTO admin_audit (id, actor_id, action, target_type, target_id, details, created_at)'
                . ' VALUES (:id, :actor, :action, :type, :target, :details, :created)',
            [
                'id' => 'audit-old',
                'actor' => 'deleted-account-id',
                'action' => 'account.disable',
                'type' => 'account',
                'target' => 'deleted-account-id',
                'details' => '{}',
                'created' => '2020-01-01T00:00:00Z',
            ],
        );

        $cleanup = new RuntimeErrorRetention($db, $server->runtime->clock);
        $preview = $cleanup->purge(90, 1, true);
        self::assertSame(2, $preview['matched']);
        self::assertSame(0, $preview['deleted']);
        self::assertSame(3, (int) $db->fetchValue('SELECT COUNT(*) FROM runtime_error'));

        $firstBatch = $cleanup->purge(90, 1);
        self::assertSame(2, $firstBatch['matched']);
        self::assertSame(1, $firstBatch['deleted']);
        self::assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM runtime_error'));

        $secondBatch = $cleanup->purge(90, 1);
        self::assertSame(1, $secondBatch['deleted']);
        self::assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM runtime_error'));
        self::assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM admin_audit'));
    }
}
