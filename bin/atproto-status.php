<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInboundRepository;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['json', 'reconcile', 'reset']);
$json = isset($options['json']);
$reconcile = isset($options['reconcile']);
$reset = isset($options['reset']);
$source = 'jetstream';

try {
    $runtime = (new ApplicationFactory(HostConfig::load($root), $root))->boot();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cannot inspect the AT Protocol stream: ' . $exception->getMessage() . "\n");
    exit(1);
}

$store = new PdoAtprotoInboundRepository($runtime->database);

if ($reset) {
    $runtime->database->execute(
        'DELETE FROM social_atproto_sync_checkpoint WHERE source = :source',
        ['source' => $source],
    );
    $runtime->atprotoBackoff?->recordSuccess();

    if ($json) {
        fwrite(STDOUT, json_encode(['reset' => true, 'source' => $source], JSON_THROW_ON_ERROR) . "\n");
    } else {
        fwrite(STDOUT, 'Checkpoint for ' . $source . " cleared. The next worker tick starts from now.\n");
    }

    exit(0);
}

$checkpoint = $store->checkpoint($source);

if ($reconcile && $checkpoint !== null) {
    $store->saveCheckpoint($checkpoint->withReconcileRequired(
        true,
        Time::toSecondPrecision($runtime->clock->now()),
    ));
    $checkpoint = $store->checkpoint($source);
}

$records = [];
$rows = $runtime->database->fetchAll(
    'SELECT collection, COUNT(*) AS total FROM social_atproto_remote_record'
    . ' WHERE source = :source GROUP BY collection ORDER BY collection',
    ['source' => $source],
);
foreach ($rows as $row) {
    $records[(string) $row['collection']] = (int) $row['total'];
}

$now = $runtime->clock->now()->getTimestamp();
$lag = $checkpoint?->lastEventAt === null
    ? null
    : max(0, $now - $checkpoint->lastEventAt->getTimestamp());

$report = [
    'source' => $source,
    'configured' => $runtime->config->atprotoJetstreamUrl !== '',
    'relay' => $runtime->config->atprotoJetstreamUrl,
    'cursor' => $checkpoint?->cursor,
    'last_sequence' => $checkpoint?->lastSequence,
    'last_event_at' => $checkpoint?->lastEventAt?->format(DATE_ATOM),
    'last_attempt_at' => $checkpoint?->lastAttemptAt->format(DATE_ATOM),
    'lag_seconds' => $lag,
    'stale' => $checkpoint?->stale ?? false,
    'reconcile_required' => $checkpoint?->reconcileRequired ?? false,
    'last_error' => $checkpoint?->lastError,
    'backoff_ready' => $runtime->atprotoBackoff?->ready() ?? true,
    'records' => $records,
];

if ($json) {
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

$line = static function (string $label, string $value): void {
    fwrite(STDOUT, str_pad($label, 20) . $value . "\n");
};

$line('Source', $report['source'] . ' (' . ($report['configured'] ? 'configured' : 'disabled') . ')');
if ($report['relay'] !== '') {
    $line('Relay', $report['relay']);
}
$line('Cursor', $report['cursor'] ?? 'none');
$line('Last sequence', $report['last_sequence'] === null ? 'none' : (string) $report['last_sequence']);
$line('Last event', $report['last_event_at'] ?? 'never');
$line('Last attempt', $report['last_attempt_at'] ?? 'never');
$line('Lag', $lag === null ? 'unknown' : $lag . 's');
$line('Stale', $report['stale'] ? 'yes' : 'no');
$line('Reconcile', $report['reconcile_required'] ? 'required' : 'not required');
$line('Backoff', $report['backoff_ready'] ? 'ready' : 'waiting');
$line('Last error', $report['last_error'] ?? 'none');

foreach ($report['records'] as $collection => $total) {
    $line('Records', $collection . ': ' . $total);
}

fwrite(STDOUT, "\nRecovery: --reset clears the cursor, --reconcile forces a full pass.\n");
