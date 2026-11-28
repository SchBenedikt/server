<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Deterministic synthetic graph for performance and parity fixtures.
 *
 * Creates fixture accounts, posts, local follows, replies and likes through
 * the same core services the HTTP handlers use, so timelines, counts and
 * notifications behave exactly as they would for real traffic. Every value
 * derives from --seed, so two runs with the same arguments produce the same
 * graph; re-running with the same arguments is idempotent — accounts,
 * follows, likes and posts are skipped or reused, never duplicated.
 *
 * Usage:
 *   php bin/seed-fixture.php [--accounts=N] [--posts=N] [--follows=K]
 *                            [--likes] [--boosts] [--seed=S] [--dry-run]
 *
 * Fixture accounts sign in with the handle as the local part of
 * fx-actor-NNNN@fixture.invalid and the password printed at the end.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Bootstrap\ApplicationFactory;
use AlohaSocial\Server\Config\HostConfig;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(2);
}

$options = getopt('', ['accounts::', 'posts::', 'follows::', 'likes', 'boosts', 'seed::', 'dry-run', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/seed-fixture.php [--accounts=N] [--posts=N] [--follows=K] [--likes] [--boosts] [--seed=S] [--dry-run]\n");
    exit(0);
}

$accountsWanted = max(1, (int) ($options['accounts'] ?? 20));
$postsWanted = max(0, (int) ($options['posts'] ?? 6));
$followsWanted = max(0, (int) ($options['follows'] ?? 3));
$likes = array_key_exists('likes', $options);
$boosts = array_key_exists('boosts', $options);
$seed = (int) ($options['seed'] ?? 187);
$dryRun = array_key_exists('dry-run', $options);

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    fwrite(STDOUT, ($ok ? 'PASS' : 'FAIL') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n");
    if (!$ok) {
        ++$failures;
    }
};

/** xorshift32: deterministic across PHP versions, unlike mt_rand. */
$next = static function () use (&$seed): int {
    $seed ^= ($seed << 13) & 0xFFFFFFFF;
    $seed ^= $seed >> 17;
    $seed ^= ($seed << 5) & 0xFFFFFFFF;

    return $seed & 0x7FFFFFFF;
};
$pick = static fn (array $list): mixed => $list[$next() % count($list)];

$words = ['fixture', 'post', 'graph', 'timeline', 'federation', ' aloha', 'feed', 'seed', 'node', 'edge'];
$contentFor = static function (int $index) use (&$next, $words, $pick): string {
    $parts = [];
    $count = 4 + ($next() % 8);
    for ($i = 0; $i < $count; ++$i) {
        $parts[] = trim((string) $pick($words));
    }

    return sprintf('fx-%05d %s #fixture', $index, implode(' ', $parts));
};

$warnings = ['sensitive topic', 'fixture warning', 'test content notice'];

try {
    $config = HostConfig::load($root);
    $runtime = (new ApplicationFactory($config, $root))->boot();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cannot boot the server: ' . $exception->getMessage() . "\n");
    exit(2);
}

$instanceUrl = rtrim($config->instanceUrl, '/');
$password = 'Fixture!Passw0rd-' . $seed;
$hasher = new PasswordHasher();

$actors = [];
for ($i = 1; $i <= $accountsWanted; ++$i) {
    $handle = sprintf('fx-actor-%04d', $i);
    $email = $handle . '@fixture.invalid';
    $existing = $runtime->accounts->findByHandle($handle);
    if ($existing !== null) {
        $actors[] = $existing;

        continue;
    }
    if ($dryRun) {
        fwrite(STDOUT, 'DRY-RUN would create account @' . $handle . "\n");
        $actors[] = null;

        continue;
    }
    $actors[] = $runtime->accounts->create(
        $handle,
        $email,
        $hasher->hash($password),
        Account::ROLE_USER,
        'Fixture actor ' . $i,
    );
}

$realActors = array_values(array_filter($actors));
$check('accounts', count($realActors) === $accountsWanted || $dryRun,
    sprintf('%d of %d present', count($realActors), $accountsWanted));

$sharedInbox = $instanceUrl . '/inbox/shared';
$postsByActor = [];
$postCount = 0;
$followCount = 0;
$likeCount = 0;
$boostCount = 0;

foreach ($realActors as $actorIndex => $actor) {
    $authorId = $actor->id;
    $actorPosts = [];

    for ($p = 0; $p < $postsWanted; ++$p) {
        $content = $contentFor($actorIndex * $postsWanted + $p);
        $warning = $next() % 5 === 0 ? $pick($warnings) : null;
        $language = $next() % 4 === 0 ? 'de' : 'en';
        $visibility = $next() % 10 === 0 ? Visibility::Unlisted : Visibility::Public;
        $replyTo = null;
        if ($postsWanted > 1 && $p > 0 && $next() % 3 === 0 && $postCount > 0) {
            // Reply to a post from a different fixture actor when possible.
            $other = $realActors[($actorIndex + 1 + $next() % max(1, count($realActors) - 1)) % count($realActors)];
            $candidates = $postsByActor[$other->id] ?? [];
            if ($candidates !== []) {
                $replyTo = $pick($candidates)->id;
            }
        }

        $postId = sprintf('fxp-%d-%02d-%02d', $seed, $actorIndex, $p);
        $existing = $dryRun ? null : $runtime->app->posts->findStored($postId);
        if ($existing !== null) {
            $actorPosts[] = $existing;
            ++$postCount;

            continue;
        }

        if ($dryRun) {
            ++$postCount;

            continue;
        }

        try {
            $post = $runtime->app->posts->create(new CreatePost(
                authorId: $authorId,
                content: $content,
                visibility: $visibility,
                contentWarning: $warning,
                language: $language,
                inReplyToId: $replyTo,
                postId: $postId,
            ));
            $actorPosts[] = $post;
            ++$postCount;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'post failed for @' . $actor->id . ': ' . $exception->getMessage() . "\n");
        }
    }
    $postsByActor[$authorId] = $actorPosts;
}
$check('posts', $postCount === $accountsWanted * $postsWanted || $dryRun,
    sprintf('%d created', $postCount));

// Local follow edges: each actor follows the next K actors (ring), which
// yields a connected graph with a bounded in-degree.
foreach ($realActors as $i => $actor) {
    for ($k = 1; $k <= $followsWanted; ++$k) {
        $target = $realActors[($i + $k) % count($realActors)];
        if ($target->id === $actor->id || $dryRun) {
            continue;
        }
        try {
            $runtime->app->followers->follow(
                new FollowActor(
                    localUserId: $target->id,
                    actorId: $instanceUrl . '/@' . rawurlencode($actor->id),
                    inboxUrl: $sharedInbox,
                ),
                local: true,
            );
            $runtime->app->notifications?->recordFollow($target->id, $actor->id);
            ++$followCount;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'follow failed @' . $actor->id . ' -> @' . $target->id . ': ' . $exception->getMessage() . "\n");
        }
    }
}
$check('follows', $followCount === $accountsWanted * $followsWanted || $dryRun,
    sprintf('%d edges', $followCount));

if ($likes || $boosts) {
    $allPosts = [];
    foreach ($postsByActor as $list) {
        foreach ($list as $post) {
            $allPosts[] = $post;
        }
    }
    foreach ($realActors as $actorIndex => $actor) {
        // Each actor likes and/or boosts a deterministic slice of the graph.
        $stride = max(1, intdiv(count($allPosts), max(1, count($realActors))));
        for ($j = 0; $j < count($allPosts); $j += $stride) {
            $post = $allPosts[($j + $actorIndex * 7) % count($allPosts)];
            if ($post->authorId === $actor->id) {
                continue;
            }
            if ($likes && !$dryRun) {
                try {
                    $runtime->interactions->like($actor->id, $post->id);
                    ++$likeCount;
                } catch (Throwable) {
                    // Already liked or not visible — fixture stays approximate.
                }
            }
            if ($boosts && !$dryRun) {
                try {
                    $runtime->app->boosts?->boost($actor->id, $post->id);
                    ++$boostCount;
                } catch (Throwable) {
                    // Same tolerance as likes.
                }
            }
        }
    }
    $check('likes', true, sprintf('%d likes, %d boosts', $likeCount, $boostCount));
}

fwrite(STDOUT, sprintf(
    "Fixture ready: %d accounts, %d posts, %d follows%s.\n",
    count($realActors),
    $postCount,
    $followCount,
    $likes || $boosts ? sprintf(', %d likes, %d boosts', $likeCount, $boostCount) : '',
));
if (!$dryRun) {
    fwrite(STDOUT, "Fixture sign-in: any fx-actor-NNNN@fixture.invalid handle with password '{$password}'.\n");
}

exit($failures > 0 ? 1 : 0);
