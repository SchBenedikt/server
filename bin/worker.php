<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Config\HostConfig;
use AlohaSocial\Server\Support\RestoreHold;

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
            $runtime->admin->taskSuccess('atproto-sync');
        } catch (Throwable $exception) {
            $runtime->admin->taskFailure('atproto-sync', $exception->getMessage());
            $delay = $runtime->atprotoBackoff?->recordFailure() ?? 0;
            fwrite(
                STDERR,
                'AT Protocol sync failed: ' . $exception->getMessage()
                . ($delay > 0 ? ' (retrying in ' . $delay . 's)' : '') . "\n",
            );
        }
    }

    if ($runtime->atprotoReconciler !== null) {
        try {
            $runtime->atprotoReconciler->run();
            $runtime->admin->taskSuccess('atproto-reconcile');
        } catch (Throwable $exception) {
            $runtime->admin->taskFailure('atproto-reconcile', $exception->getMessage());
            fwrite(STDERR, 'AT Protocol reconciliation failed: ' . $exception->getMessage() . "\n");
        }
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
        $runtime->admin->taskSuccess('engagement');
    } catch (Throwable $exception) {
        $runtime->admin->taskFailure('engagement', $exception->getMessage());
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
        $runtime->admin->taskSuccess('actor-profiles');
    } catch (Throwable $exception) {
        $runtime->admin->taskFailure('actor-profiles', $exception->getMessage());
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
        $runtime->emailChange->prune();
        $runtime->mailOutbox->prune();
        $result = $runtime->mailOutbox->dispatch(10);
        $runtime->admin->taskSuccess('mail');
        if ($result['failed'] > 0) {
            fwrite(STDERR, 'Mail outbox marked ' . $result['failed'] . " message(s) failed.\n");
        }
    } catch (Throwable $exception) {
        $runtime->admin->taskFailure('mail', $exception->getMessage());
        fwrite(STDERR, 'Mail outbox dispatch failed: ' . $exception->getMessage() . "\n");
    }
}

/**
 * Deliver queued Web Push notifications.
 *
 * The notification sink only enqueues; this step performs the bounded batch
 * of HTTPS posts so a slow push service never holds a request or the queue
 * worker. Failed rows back off in the table; gone endpoints are removed by
 * the sender itself.
 */
function dispatchPush(Runtime $runtime): void
{
    try {
        $delivered = 0;
        $failed = 0;
        foreach ($runtime->pushSubscriptions->dueDeliveries(10) as $delivery) {
            $outcome = $runtime->pushSender->deliverForAccount(
                (string) $delivery['account_id'],
                (string) $delivery['payload'],
            );
            if ($outcome['failed'] > 0) {
                ++$failed;
                $runtime->pushSubscriptions->retryDelivery(
                    (string) $delivery['id'],
                    (int) $delivery['attempts'],
                );
            } else {
                $runtime->pushSubscriptions->completeDelivery((string) $delivery['id']);
                $delivered += $outcome['sent'] + $outcome['gone'];
            }
        }
        $runtime->admin->taskSuccess('push');
        if ($failed > 0) {
            fwrite(STDERR, 'Push delivery deferred ' . $failed . " notification(s) for retry.\n");
        }
    } catch (Throwable $exception) {
        $runtime->admin->taskFailure('push', $exception->getMessage());
        fwrite(STDERR, 'Push delivery failed: ' . $exception->getMessage() . "\n");
    }
}

/**
 * Drain one bounded batch from the core job queue. A crashing handler must
 * not kill the worker loop: the job stays claimed and the core retry/dead
 * letter path owns it from here.
 */
function drainQueue(Runtime $runtime, int $limit): int
{
    try {
        $handled = $runtime->app->worker->runOnce($limit);
        $runtime->admin->taskSuccess('queue');

        return $handled;
    } catch (Throwable $exception) {
        $runtime->admin->taskFailure('queue', $exception->getMessage());
        fwrite(STDERR, 'Queue batch failed: ' . $exception->getMessage() . "\n");

        return 0;
    }
}

/** Complete host cleanup after Core's durable account erasure reaches `erased`. */
function finalizeAccountErasures(Runtime $runtime): void
{
    try {
        $result = $runtime->accountErasureCleanup->finalizeCompleted();
        $runtime->admin->taskSuccess('erasure');
        if ($result['failed'] > 0) {
            fwrite(
                STDERR,
                'Account erasure cleanup will retry failed accounts on a later worker tick. ('
                . $result['failed'] . ")\n",
            );
        }
    } catch (Throwable $exception) {
        $runtime->admin->taskFailure('erasure', $exception->getMessage());
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

// A completed `bin/restore.php --apply` leaves var/restore.pending behind:
// the worker must not deliver outbound federation or mail on top of freshly
// restored state until an operator confirms with `bin/restore.php --confirm`.
$restoreHold = static fn (): bool => RestoreHold::active($runtime->config);

if ($once) {
    if ($restoreHold()) {
        fwrite(STDOUT, "Restore hold active — worker tick skipped. Confirm with bin/restore.php --confirm.\n");
        exit(0);
    }
    $runtime->admin->heartbeat();
    finalizeAccountErasures($runtime);
    dispatchMailOutbox($runtime);
    dispatchPush($runtime);
    syncAtproto($runtime);
    refreshRemoteEngagement($runtime, 25);
    refreshRemoteActorProfiles($runtime, 25);
    $handled = drainQueue($runtime, 10);
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

    if ($restoreHold()) {
        // No heartbeat is written while held, so monitoring reports the
        // worker as stale — that is the intended "paused" signal.
        sleep(5);
        continue;
    }

    // A non-empty batch is drained immediately; an empty one sleeps, so the
    // worker is not a busy loop.
    $runtime->admin->heartbeat();
    finalizeAccountErasures($runtime);
    dispatchMailOutbox($runtime);
    dispatchPush($runtime);
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

    $handled = drainQueue($runtime, 10);
    if ($handled === 0) {
        usleep(500_000);
    }
}

fwrite(STDOUT, "Worker stopped.\n");
