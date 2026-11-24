<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Service\Cleanup\CleanupOptions;
use AlohaSocial\Server\Admin\RuntimeErrorRetention;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$payload = ['kind' => 'all', 'limit' => 100];
$runNow = false;

foreach (array_slice($argv, 1) as $option) {
    if ($option === '--now') {
        $runNow = true;
        continue;
    }

    if ($option === '--dry-run') {
        $payload['dry_run'] = true;
        continue;
    }

    if (str_starts_with($option, '--kind=')) {
        $payload['kind'] = substr($option, 7);
        continue;
    }

    if (str_starts_with($option, '--limit=')) {
        $payload['limit'] = (int) substr($option, 8);
        continue;
    }

    fwrite(STDERR, 'Unknown option: ' . $option . "\n");
    exit(2);
}

try {
    $runtime = (new ApplicationFactory(HostConfig::load($root), $root))->boot();
    $options = CleanupOptions::fromArray($payload);
    $errorLogReport = $options->kind === CleanupOptions::KIND_ALL
        ? (new RuntimeErrorRetention($runtime->database, $runtime->clock))->purge(
            $runtime->config->runtimeErrorRetentionDays,
            $options->limit,
            $options->dryRun,
        )
        : null;

    if ($runNow) {
        $service = $runtime->app->cleanup;
        if ($service === null) {
            fwrite(STDERR, "The cleanup service is not wired.\n");
            exit(1);
        }

        $report = $service->run($options);
        fwrite(STDOUT, "Cleanup finished.\n");
        if (method_exists($report, 'toArray')) {
            /** @var array<string, mixed> $data */
            $data = $report->toArray();
            fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
        if ($errorLogReport !== null) {
            fwrite(STDOUT, "Runtime error retention:\n");
            fwrite(STDOUT, json_encode($errorLogReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        exit(0);
    }

    $job = $runtime->app->queue->push(JobType::CLEANUP, $options->toArray());
    fwrite(STDOUT, 'Queued cleanup job ' . $job->id . ".\n");
    if ($errorLogReport !== null) {
        fwrite(STDOUT, "Runtime error retention:\n");
        fwrite(STDOUT, json_encode($errorLogReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    fwrite(STDOUT, "Run it with: php bin/worker.php --once\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cleanup failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
