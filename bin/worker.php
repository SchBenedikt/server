<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Config\HostConfig;

/**
 * Pull one bounded AT Protocol page and project stored records into social tables.
 *
 * A relay outage must not stop the queue worker, so both steps are best
 * effort here; the core marks the checkpoint stale and the next tick retries.
 */
function syncAtproto(Runtime $runtime): void
{
    if ($runtime->app->atprotoSync === null) {
        return;
    }

    // Reconciliation is local work over records already stored in the
    // database, so it must continue while a relay pull is backed off or fails.
    if ($runtime->atprotoBackoff === null || $runtime->atprotoBackoff->ready()) {
        try {
            $runtime->app->atprotoSync->sync(100);
            $runtime->atprotoBackoff?->recordSuccess();
        } catch (Throwable $exception) {
            $delay = $runtime->atprotoBackoff?->recordFailure() ?? 0;
            fwrite(
                STDERR,
                'AT Protocol sync failed: ' . $exception->getMessage()
                . ($delay > 0 ? ' (retrying in ' . $delay . 's)' : '') . "\n",
            );
        }
    }

    try {
        $runtime->atprotoReconciler?->run();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'AT Protocol reconciliation failed: ' . $exception->getMessage() . "\n");
    }
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$once = in_array('--once', array_slice($argv, 1), true);

try {
    $runtime = (new ApplicationFactory(HostConfig::load($root), $root))->boot();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Worker cannot start: ' . $exception->getMessage() . "\n");
    exit(1);
}

if ($once) {
    $runtime->admin->heartbeat();
    syncAtproto($runtime);
    $handled = $runtime->app->worker->runOnce(10);
    fwrite(STDOUT, 'handled=' . $handled . "\n");
    exit(0);
}

$stop = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void {
        $stop = true;
    });
    pcntl_signal(SIGINT, static function () use (&$stop): void {
        $stop = true;
    });
}

while (!$stop) {
    // A non-empty batch is drained immediately; an empty one sleeps, so the
    // worker is not a busy loop.
    $runtime->admin->heartbeat();
    syncAtproto($runtime);
    $handled = $runtime->app->worker->runOnce(10);
    if ($handled === 0) {
        usleep(500_000);
    }
}

fwrite(STDOUT, "Worker stopped.\n");
