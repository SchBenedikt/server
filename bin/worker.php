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

/**
 * Fill the public counters of followed federated posts.
 *
 * Feed cards read these numbers without a network call, so the worker has to
 * warm the cache. Failures are per post and never stop the queue.
 */
function refreshRemoteEngagement(Runtime $runtime, int $limit = 10): void
{
    try {
        $runtime->remoteEngagementRefresher->refresh($limit);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Remote engagement refresh failed: ' . $exception->getMessage() . "\n");
    }
}

/**
 * Warm display names and avatars for the actors of cached federated posts.
 *
 * One guarded actor request per author; the timeline reads the cache and
 * never waits for a peer. A failed actor backs off in the cache service.
 */
function refreshRemoteActorProfiles(Runtime $runtime, int $limit = 8): void
{
    try {
        $runtime->remoteActorProfiles->refreshStale($limit);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Remote actor profile refresh failed: ' . $exception->getMessage() . "\n");
    }
}

/**
 * Deliver queued transactional mail (password resets today).
 *
 * A failing transport leaves rows pending with bounded backoff; the next
 * tick retries. Cancelled rows are swept so the table stays small.
 */
function dispatchMailOutbox(Runtime $runtime): void
{
    try {
        $runtime->passwordReset->prune();
        $runtime->emailVerification->prune();
        $runtime->mailOutbox->prune();
        $result = $runtime->mailOutbox->dispatch(10);
        if ($result['failed'] > 0) {
            fwrite(STDERR, 'Mail outbox marked ' . $result['failed'] . " message(s) failed.\n");
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Mail outbox dispatch failed: ' . $exception->getMessage() . "\n");
    }
}

/** Complete host cleanup after Core's durable account erasure reaches `erased`. */
function finalizeAccountErasures(Runtime $runtime): void
{
    try {
        $result = $runtime->accountErasureCleanup->finalizeCompleted();
        if ($result['failed'] > 0) {
            fwrite(
                STDERR,
                'Account erasure cleanup will retry failed accounts on a later worker tick. ('
                . $result['failed'] . ")\n",
            );
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Account erasure cleanup scan failed: ' . $exception->getMessage() . "\n");
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
    finalizeAccountErasures($runtime);
    dispatchMailOutbox($runtime);
    syncAtproto($runtime);
    refreshRemoteEngagement($runtime, 25);
    refreshRemoteActorProfiles($runtime, 25);
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

$lastEngagementRefresh = 0;
$lastActorProfileRefresh = 0;
$envFile = $runtime->config->envFile;
$envStamp = is_file($envFile) ? (int) filemtime($envFile) : 0;
while (!$stop) {
    // Web setup and deployments rewrite the env file atomically. Rebuild the
    // runtime at a batch boundary so a long-running worker uses the same
    // origin and mail settings as the next web request instead of waiting for
    // a manual service restart.
    clearstatcache(true, $envFile);
    $newStamp = is_file($envFile) ? (int) filemtime($envFile) : 0;
    if ($newStamp !== $envStamp) {
        try {
            $reloaded = HostConfig::load($root);
            $runtime = (new ApplicationFactory($reloaded, $root))->boot();
            $envFile = $runtime->config->envFile;
            $envStamp = is_file($envFile) ? (int) filemtime($envFile) : $newStamp;
            fwrite(STDERR, "Configuration reloaded from " . $envFile . ".\n");
        } catch (Throwable $exception) {
            // Keep the previous runtime. Retry only after the file changes
            // again, so a broken configuration does not flood the journal.
            $envStamp = $newStamp;
            fwrite(STDERR, 'Configuration reload failed; keeping the previous runtime: ' . $exception->getMessage() . "\n");
        }
    }

    // A non-empty batch is drained immediately; an empty one sleeps, so the
    // worker is not a busy loop.
    $runtime->admin->heartbeat();
    finalizeAccountErasures($runtime);
    dispatchMailOutbox($runtime);
    syncAtproto($runtime);

    // Origin requests are rate-limited per peer, so a small batch once a
    // minute is enough to keep visible cards current.
    if (time() - $lastEngagementRefresh >= 60) {
        refreshRemoteEngagement($runtime);
        $lastEngagementRefresh = time();
    }

    if (time() - $lastActorProfileRefresh >= 60) {
        refreshRemoteActorProfiles($runtime);
        $lastActorProfileRefresh = time();
    }

    $handled = $runtime->app->worker->runOnce(10);
    if ($handled === 0) {
        usleep(500_000);
    }
}

fwrite(STDOUT, "Worker stopped.\n");
