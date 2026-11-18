<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['json', 'handle:']);
$json = isset($options['json']);
$handle = is_string($options['handle'] ?? null) ? ltrim($options['handle'], '@') : '';

try {
    $runtime = (new ApplicationFactory(HostConfig::load($root), $root))->boot();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cannot inspect the feed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$db = $runtime->database;
$report = ['posts' => [], 'remote' => 0, 'accounts' => [], 'feed' => null, 'problems' => []];

try {
    $rows = $db->fetchAll(
        'SELECT visibility, COUNT(*) AS total FROM social_post WHERE deleted_at IS NULL GROUP BY visibility',
    );
    foreach ($rows as $row) {
        $report['posts'][(string) ($row['visibility'] ?? 'unknown')] = (int) ($row['total'] ?? 0);
    }

    $remote = $db->fetchAll('SELECT COUNT(*) AS total FROM social_remote_post WHERE deleted_at IS NULL');
    $report['remote'] = (int) ($remote[0]['total'] ?? 0);

    $accounts = $db->fetchAll('SELECT id, handle FROM account ORDER BY handle ASC LIMIT 50');
    foreach ($accounts as $account) {
        $report['accounts'][] = ['id' => (string) $account['id'], 'handle' => (string) $account['handle']];
    }

    $account = null;
    foreach ($accounts as $candidate) {
        if ($handle === '' || strcasecmp((string) $candidate['handle'], $handle) === 0) {
            $account = $candidate;
            break;
        }
    }

    if ($account === null) {
        $report['problems'][] = $handle === ''
            ? 'No account exists yet.'
            : 'No account with handle ' . $handle . '.';
    } else {
        $accountId = (string) $account['id'];
        $feed = $runtime->forYou->page($accountId, 20, 0);
        $report['feed'] = [
            'account' => (string) $account['handle'],
            'total' => $feed['total'],
            'post_ids' => $feed['posts'],
        ];

        if ($feed['posts'] === []) {
            $report['problems'][] = 'The ranking returned no candidates.';
            $public = (int) ($report['posts']['public'] ?? 0);
            if ($public === 0) {
                $report['problems'][] = 'There is no non-deleted public local post.';
            }
        }

        foreach (array_slice($feed['posts'], 0, 5) as $id) {
            $local = $db->fetchAll('SELECT id FROM social_post WHERE id = :id', ['id' => $id]);
            $remoteRows = $db->fetchAll(
                'SELECT activity_id FROM social_remote_post WHERE activity_id = :id OR object_id = :id',
                ['id' => $id],
            );
            if ($local === [] && $remoteRows === []) {
                $report['problems'][] = 'Feed id is not present in either post table: ' . $id;
            }
        }

        $muted = $db->fetchAll(
            'SELECT COUNT(*) AS total FROM social_mute WHERE user_id = :id',
            ['id' => $accountId],
        );
        if ((int) ($muted[0]['total'] ?? 0) > 0) {
            $report['problems'][] = 'The account has mute rows; they can remove candidates.';
        }
    }
} catch (Throwable $exception) {
    $report['problems'][] = 'Query failed: ' . $exception->getMessage();
}

if ($json) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

fwrite(STDOUT, "Posts by visibility (non-deleted):\n");
foreach ($report['posts'] as $visibility => $total) {
    fwrite(STDOUT, '  ' . $visibility . ': ' . $total . "\n");
}
fwrite(STDOUT, 'Remote posts: ' . $report['remote'] . "\n");

if ($report['accounts'] !== []) {
    fwrite(STDOUT, "Accounts:\n");
    foreach ($report['accounts'] as $account) {
        fwrite(STDOUT, '  @' . $account['handle'] . ' (' . $account['id'] . ")\n");
    }
}

if ($report['feed'] !== null) {
    fwrite(STDOUT, 'For You for @' . $report['feed']['account'] . ': '
        . $report['feed']['total'] . " candidates\n");
    foreach ($report['feed']['post_ids'] as $id) {
        fwrite(STDOUT, '  ' . $id . "\n");
    }
}

if ($report['problems'] !== []) {
    fwrite(STDOUT, "Problems:\n");
    foreach ($report['problems'] as $problem) {
        fwrite(STDOUT, '  - ' . $problem . "\n");
    }
} else {
    fwrite(STDOUT, "Feed diagnosis: OK\n");
}
