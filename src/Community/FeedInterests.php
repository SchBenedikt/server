<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

use function abs;
use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function arsort;
use function count;
use function exp;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function round;
use function strlen;
use function strtolower;
use function trim;

/**
 * Learns what one reader is interested in from what they do, not from a
 * profile they filled in.
 *
 * Two sources feed the model. Explicit markers (like, boost, bookmark, reply,
 * "show more", "show less") say what mattered. Impressions say what merely
 * passed by: a post that stayed on screen for a few hundred milliseconds is a
 * weaker signal than one that was read for several seconds, and it marks the
 * post as already seen so the next page can move on.
 *
 * Every signal is decayed over a thirty-day half-life, so old enthusiasm fades
 * and the feed follows changing interests. The result is a small, normalised
 * weight per author and per hashtag; the ranker multiplies it into its score.
 * Nothing here leaves the instance and nothing is shared between accounts.
 */
final class FeedInterests
{
    public const MAX_IMPRESSIONS = 60;

    private const HISTORY_DAYS = 180;

    private const DECAY_DAYS = 30.0;

    private const SEEN_LIMIT = 4000;

    private const MAX_TOP_TAGS = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Stores the posts one reader saw, with the time they stayed on screen.
     *
     * The author is resolved here rather than trusted from the client; an
     * unknown or deleted post is ignored so the table cannot be filled with
     * arbitrary ids.
     *
     * @param list<mixed> $items
     */
    public function record(string $accountId, array $items): int
    {
        $accepted = [];
        foreach (array_slice($items, 0, self::MAX_IMPRESSIONS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $postId = is_string($item['post_id'] ?? null) ? trim($item['post_id']) : '';
            if ($postId === '' || strlen($postId) > 255) {
                continue;
            }
            $dwell = $item['dwell_ms'] ?? 0;
            $dwell = is_int($dwell) ? $dwell : (is_float($dwell) ? (int) $dwell : 0);
            $accepted[$postId] = max(0, min(120_000, $dwell));
        }
        if ($accepted === []) {
            return 0;
        }

        $authors = $this->authorsFor($accepted);
        $stamp = Time::format($this->clock->now());
        $recorded = 0;

        $this->db->transaction(function (Connection $db) use ($accountId, $accepted, $authors, $stamp, &$recorded): void {
            foreach ($accepted as $postId => $dwell) {
                $authorId = $authors[$postId] ?? '';
                if ($authorId === '') {
                    continue;
                }

                $db->execute(
                    'INSERT INTO recommendation_impression'
                    . ' (account_id, post_id, author_id, seen_at, dwell_ms, times)'
                    . ' VALUES (:account_id, :post_id, :author_id, :seen_at, :dwell_ms, 1)'
                    . ' ON CONFLICT (account_id, post_id) DO UPDATE SET'
                    . ' seen_at = excluded.seen_at,'
                    . ' dwell_ms = (recommendation_impression.dwell_ms + excluded.dwell_ms) / 2,'
                    . ' times = recommendation_impression.times + 1',
                    [
                        'account_id' => $accountId,
                        'post_id' => $postId,
                        'author_id' => $authorId,
                        'seen_at' => $stamp,
                        'dwell_ms' => $dwell,
                    ],
                );
                $recorded++;
            }
        });

        return $recorded;
    }

    /**
     * @param array<string, int> $dwells
     * @return array<string, string> post id to author id
     */
    private function authorsFor(array $dwells): array
    {
        $postIds = array_values(array_map(static fn (string $id): string => $id, array_keys($dwells)));
        if ($postIds === []) {
            return [];
        }

        $authors = $this->postAuthors($postIds, 'social_post', 'id', 'author_id');
        $missing = array_values(array_filter($postIds, static fn (string $id): bool => !isset($authors[$id])));
        if ($missing !== []) {
            $authors += $this->postAuthors($missing, 'social_remote_post', 'activity_id', 'actor_id');
        }

        return $authors;
    }

    /** @param list<string> $postIds @return array<string, string> */
    private function postAuthors(array $postIds, string $table, string $idColumn, string $authorColumn): array
    {
        $parameters = [];
        $placeholders = [];
        foreach ($postIds as $index => $postId) {
            $placeholder = 'post_' . $index;
            $placeholders[] = ':' . $placeholder;
            $parameters[$placeholder] = $postId;
        }

        $rows = $this->db->fetchAll(
            'SELECT ' . $idColumn . ' AS post_id, ' . $authorColumn . ' AS author_id FROM ' . $table
            . ' WHERE ' . $idColumn . ' IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        );

        $authors = [];
        foreach ($rows as $row) {
            $postId = (string) ($row['post_id'] ?? '');
            $authorId = (string) ($row['author_id'] ?? '');
            if ($postId !== '' && $authorId !== '') {
                $authors[$postId] = $authorId;
            }
        }

        return $authors;
    }

    /**
     * @return array<string, int> post id to the timestamp it was last seen
     */
    public function seen(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT post_id, seen_at FROM recommendation_impression'
            . ' WHERE account_id = :account_id ORDER BY seen_at DESC LIMIT ' . self::SEEN_LIMIT,
            ['account_id' => $accountId],
        );

        $seen = [];
        foreach ($rows as $row) {
            $postId = (string) ($row['post_id'] ?? '');
            if ($postId === '') {
                continue;
            }
            $seen[$postId] = Time::parse((string) $row['seen_at'])->getTimestamp();
        }

        return $seen;
    }

    /**
     * @return array{
     *     authors: array<string, float>,
     *     tags: array<string, float>,
     *     top: list<array{kind: string, key: string, weight: float}>
     * }
     */
    public function model(string $accountId): array
    {
        $now = $this->clock->now();
        $nowStamp = $now->getTimestamp();
        $since = Time::format($now->modify('-' . self::HISTORY_DAYS . ' days'));

        $events = $this->db->fetchAll(
            'SELECT p.author_id AS author_id, p.id AS post_id, f.created_at AS created_at, 1.2 AS weight'
            . ' FROM social_favourite f JOIN social_post p ON p.id = f.post_id'
            . ' WHERE f.user_id = :favourite_viewer AND f.created_at >= :favourite_since'
            . ' UNION ALL'
            . ' SELECT p.author_id, p.id, b.created_at, 1.8 FROM social_boost b JOIN social_post p ON p.id = b.post_id'
            . ' WHERE b.user_id = :boost_viewer AND b.created_at >= :boost_since'
            . ' UNION ALL'
            . ' SELECT p.author_id, p.id, k.created_at, 1.6 FROM social_bookmark k JOIN social_post p ON p.id = k.post_id'
            . ' WHERE k.user_id = :bookmark_viewer AND k.created_at >= :bookmark_since'
            . ' UNION ALL'
            . ' SELECT parent.author_id, parent.id, r.published_at, 2.2 FROM social_post r'
            . ' JOIN social_post parent ON parent.id = r.in_reply_to_id'
            . ' WHERE r.author_id = :reply_viewer AND r.deleted_at IS NULL AND r.published_at >= :reply_since'
            . ' UNION ALL'
            . " SELECT p.author_id, p.id, fb.created_at, CASE fb.kind WHEN 'more' THEN 2.5 ELSE -3.0 END"
            . ' FROM recommendation_feedback fb JOIN social_post p ON p.id = fb.post_id'
            . ' WHERE fb.account_id = :feedback_viewer AND fb.created_at >= :feedback_since',
            [
                'favourite_viewer' => $accountId,
                'favourite_since' => $since,
                'boost_viewer' => $accountId,
                'boost_since' => $since,
                'bookmark_viewer' => $accountId,
                'bookmark_since' => $since,
                'reply_viewer' => $accountId,
                'reply_since' => $since,
                'feedback_viewer' => $accountId,
                'feedback_since' => $since,
            ],
        );

        $postIds = [];
        foreach ($events as $event) {
            $postId = (string) ($event['post_id'] ?? '');
            if ($postId !== '') {
                $postIds[$postId] = true;
            }
        }
        $tagByPost = $this->tagsFor(array_keys($postIds));

        $authors = [];
        $tags = [];
        foreach ($events as $event) {
            $weight = (float) ($event['weight'] ?? 0.0);
            $createdAt = Time::parse((string) ($event['created_at'] ?? Time::format($now)))->getTimestamp();
            $ageDays = max(0.0, ($nowStamp - $createdAt) / 86400);
            $value = $weight * exp(-$ageDays / self::DECAY_DAYS);

            $authorId = (string) ($event['author_id'] ?? '');
            if ($authorId !== '') {
                $authors[$authorId] = ($authors[$authorId] ?? 0.0) + $value;
            }
            foreach ($tagByPost[(string) ($event['post_id'] ?? '')] ?? [] as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0.0) + $value * 0.8;
            }
        }

        // Follows are intentionally not part of this model: the ranker already
        // gives a followed author a strong explicit bonus, and counting it
        // twice would let a follow outweigh fresh engagement everywhere.

        // Reading time is a weak implicit signal: a post that stays open adds
        // a little, one that is scrolled past immediately subtracts a little.
        $impressions = $this->db->fetchAll(
            'SELECT author_id, dwell_ms, times FROM recommendation_impression'
            . ' WHERE account_id = :account_id ORDER BY seen_at DESC LIMIT 500',
            ['account_id' => $accountId],
        );
        foreach ($impressions as $row) {
            $authorId = (string) ($row['author_id'] ?? '');
            if ($authorId === '') {
                continue;
            }
            $times = max(1, min(3, (int) ($row['times'] ?? 1)));
            $dwell = (int) ($row['dwell_ms'] ?? 0);
            if ($dwell >= 4000) {
                $authors[$authorId] = ($authors[$authorId] ?? 0.0) + 0.12 * $times;
            } elseif ($dwell <= 600) {
                $authors[$authorId] = ($authors[$authorId] ?? 0.0) - 0.10 * $times;
            }
        }

        $authors = $this->normalize($authors);
        $tags = $this->normalize($tags);

        return [
            'authors' => $authors,
            'tags' => $tags,
            'top' => $this->topTags($tags),
        ];
    }

    /**
     * @param list<string> $postIds
     * @return array<string, list<string>>
     */
    private function tagsFor(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $parameters = [];
        $placeholders = [];
        foreach ($postIds as $index => $postId) {
            $placeholder = 'post_' . $index;
            $placeholders[] = ':' . $placeholder;
            $parameters[$placeholder] = $postId;
        }

        $rows = $this->db->fetchAll(
            'SELECT post_id, hashtag FROM social_post_hashtag WHERE post_id IN ('
            . implode(', ', $placeholders) . ') ORDER BY post_id, hashtag',
            $parameters,
        );

        $tagsByPost = [];
        foreach ($rows as $row) {
            $postId = (string) ($row['post_id'] ?? '');
            $tag = strtolower((string) ($row['hashtag'] ?? ''));
            if ($postId !== '' && $tag !== '') {
                $tagsByPost[$postId][] = $tag;
            }
        }

        return $tagsByPost;
    }

    /**
     * Scales a weight map into [-1, 1] and drops noise the ranker should not
     * act on.
     *
     * @param array<string, float> $weights
     * @return array<string, float>
     */
    private function normalize(array $weights): array
    {
        $peak = 0.0;
        foreach ($weights as $weight) {
            $peak = max($peak, abs($weight));
        }
        if ($peak <= 0.0) {
            return [];
        }

        $normalized = [];
        foreach ($weights as $key => $weight) {
            $value = round($weight / $peak, 3);
            if (abs($value) >= 0.04) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, float> $tags
     * @return list<array{kind: string, key: string, weight: float}>
     */
    private function topTags(array $tags): array
    {
        $positive = array_filter($tags, static fn (float $weight): bool => $weight > 0.08);
        arsort($positive);

        $top = [];
        foreach (array_slice($positive, 0, self::MAX_TOP_TAGS, true) as $tag => $weight) {
            $top[] = ['kind' => 'tag', 'key' => (string) $tag, 'weight' => (float) $weight];
        }

        return $top;
    }
}
