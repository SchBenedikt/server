<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Service\Cleanup\CleanupOptions;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    public function testWorkerRunsAQueuedCleanupJob(): void
    {
        $server = new TestServer();

        $job = $server->runtime->app->queue->push(
            JobType::CLEANUP,
            CleanupOptions::fromArray(['kind' => 'all', 'limit' => 10])->toArray(),
        );

        self::assertNotEmpty($job->id);
        self::assertGreaterThanOrEqual(1, $server->runtime->app->queue->pendingCount());

        self::assertSame(1, $server->runtime->app->worker->runOnce(10));
        self::assertSame(0, $server->runtime->app->queue->pendingCount());
    }
}
