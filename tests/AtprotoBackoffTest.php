<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Server\Federation\Atproto\AtprotoBackoff;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * The relay backoff is host state, not part of the durable checkpoint, so it
 * needs its own check: doubling, capping and clearing on success.
 */
final class AtprotoBackoffTest extends TestCase
{
    public function testFailuresBackOffAndSuccessClearsTheState(): void
    {
        $file = sys_get_temp_dir() . '/alohasocial-backoff-' . bin2hex(random_bytes(6)) . '.json';
        $backoff = new AtprotoBackoff($file, new SystemClock(), 5, 20);

        self::assertTrue($backoff->ready());
        self::assertSame(5, $backoff->recordFailure());
        self::assertFalse($backoff->ready());
        self::assertSame(10, $backoff->recordFailure());
        self::assertSame(20, $backoff->recordFailure());
        self::assertSame(20, $backoff->recordFailure());

        $backoff->recordSuccess();

        self::assertTrue($backoff->ready());
        self::assertFileDoesNotExist($file);
    }
}
