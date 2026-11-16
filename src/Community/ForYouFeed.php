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

use function array_keys;
use function array_slice;
use function count;
use function in_array;
use function log;
use function max;
use function min;
use function strtolower;
use function usort;

/**
 * A first-party "For You" ranking over local public posts.
 *
 * The core owns the social graph and the engagement rows. This host service
 * only reads them: it scores recent public posts by engagement, freshness,
 * followed authors and followed hashtags, then removes muted and blocked
 * authors and damps repeated authors so one account cannot fill the page.
 *
 * The rank is deterministic for a given database state, which keeps cursor
 * paging stable within one session. No remote call is made, so the feed still
 * works when a discovery instance is unavailable.
 */
final class ForYouFeed
{
    public const MAX_LIMIT = 40;

    private const CANDIDATE_LIMIT = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     posts: list<string>,
     *     reasons: array<string, string>,
     *     next: int|null,
     *     total: int
     * }
     */
    public function page(string $accountId, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $candidates = $this->candidates($accountId);
        if ($candidates === []) {
            return ['posts' => [], 'reasons' => [], 'next' => null, 'total' => 0];
        }

        $followedAuthors = $this->followedAuthors($accountId);
        $followedTags = $this->followedTags($accountId);
        $feedback = $this->feedback($accountId);
        $now = $this->clock->now()->getTimestamp();

        $scored = [];
        foreach ($candidates as $candidate) {
            $postId = (string) $candidate['id'];
            $authorId = (string) $candidate['author_id'];

            // A post the reader asked to see less of never enters the feed.
            if (($feedback[$postId] ?? '') === 'less') {
                continue;
            }

            $published = Time::parse((string) $candidate['published_at'])->getTimestamp();
            $ageHours = max(0, ($now - $published) / 3600);
            $engagement = (int) $candidate['favourites'] * 2
                + (int) $candidate['boosts'] * 3
                + (int) $candidate['replies'] * 1.5;
            $score = log(1 + $engagement) * 3.2;
            $score += max(0, 6.0 * (1 - $ageHours / 168));
            $reason = 'Aus der Instanz und deinem Umfeld';

            if (isset($followedAuthors[$authorId])) {
                $score += 5.0;
                $reason = 'Weil du diesem Konto folgst';
            }
            $tags = $this->postTags($postId);
            $matching = array_values(array_intersect($tags, $followedTags));
            if ($matching !== []) {
                $score += min(3.0, count($matching) * 1.5);
                $reason = 'Weil du #' . $matching[0] . ' folgst';
            }
            if ((int) $candidate['boosts'] > 0) {
                $score += 2.0;
            }
            if (($feedback[$postId] ?? '') === 'more') {
                $score += 4.0;
                $reason = 'Weil du mehr davon sehen möchtest';
            }

            $scored[] = [
                'id' => $postId,
                'author_id' => $authorId,
                'score' => $score,
                'reason' => $reason,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return $b['id'] <=> $a['id'];
            }

            return $b['score'] <=> $a['score'];
        });

        $seenAuthors = [];
        foreach ($scored as $index => $entry) {
            $author = $entry['author_id'];
            $repeat = $seenAuthors[$author] ?? 0;
            $scored[$index]['score'] -= $repeat * 1.6;
            $seenAuthors[$author] = $repeat + 1;
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return $b['id'] <=> $a['id'];
            }

            return $b['score'] <=> $a['score'];
        });

        $total = count($scored);
        $window = array_slice($scored, $offset, $limit);
        $posts = array_column($window, 'id');
        $reasons = [];
        foreach ($window as $entry) {
            $reasons[$entry['id']] = $entry['reason'];
        }
        $next = $offset + $limit;

        return [
            'posts' => array_values($posts),
            'reasons' => $reasons,
            'next' => $next < $total ? $next : null,
            'total' => $total,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(string $accountId): array
    {
        $since = Time::format($this->clock->now()->modify('-7 days'));

        return $this->db->fetchAll(
            'SELECT p.id, p.author_id, p.published_at,'
            . ' (SELECT COUNT(*) FROM social_favourite f WHERE f.post_id = p.id) AS favourites,'
            . ' (SELECT COUNT(*) FROM social_boost b WHERE b.post_id = p.id) AS boosts,'
            . ' (SELECT COUNT(*) FROM social_post r WHERE r.in_reply_to_id = p.id AND r.deleted_at IS NULL) AS replies'
            . ' FROM social_post p'
            . " WHERE p.deleted_at IS NULL AND p.visibility = 'public' AND p.published_at >= :since"
            . ' AND p.author_id <> :viewer'
            . ' AND p.author_id NOT IN (SELECT actor_id FROM social_mute WHERE user_id = :muted_viewer)'
            . ' AND p.author_id NOT IN (SELECT actor_id FROM social_block WHERE local_user_id = :blocked_viewer)'
            . ' ORDER BY p.published_at DESC LIMIT ' . self::CANDIDATE_LIMIT,
            [
                'since' => $since,
                'viewer' => $accountId,
                'muted_viewer' => $accountId,
                'blocked_viewer' => $accountId,
            ],
        );
    }

    /**
     * @return array<string, true>
     */
    private function followedAuthors(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT actor_id FROM social_following WHERE local_user_id = :account_id AND state = 'accepted'",
            ['account_id' => $accountId],
        );

        $result = [];
        foreach ($rows as $row) {
            $actor = (string) ($row['actor_id'] ?? '');
            if ($actor !== '') {
                $result[$actor] = true;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function followedTags(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT hashtag FROM social_followed_hashtag WHERE user_id = :account_id',
            ['account_id' => $accountId],
        );

        return array_values(array_filter(
            array_map(static fn (array $row): string => strtolower((string) ($row['hashtag'] ?? '')), $rows),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function postTags(string $postId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT hashtag FROM social_post_hashtag WHERE post_id = :post_id',
            ['post_id' => $postId],
        );

        return array_values(array_filter(
            array_map(static fn (array $row): string => strtolower((string) ($row['hashtag'] ?? '')), $rows),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    /**
     * Stores a private "less/more of this" marker for the viewer.
     *
     * The marker is scoped to the account and the post, so it never changes
     * what anyone else sees and cannot be used to target another account.
     *
     * @return array{post_id: string, kind: string}
     */
    public function setFeedback(string $accountId, string $postId, string $kind): array
    {
        $postId = trim($postId);
        if ($postId === '') {
            throw CommunityException::invalid('Der Beitrag fehlt.', ['post_id' => 'Bitte wähle einen Beitrag.']);
        }
        if (!in_array($kind, ['less', 'more'], true)) {
            throw CommunityException::invalid(
                'Diese Rückmeldung ist ungültig.',
                ['kind' => 'Erlaubt sind "less" und "more".'],
            );
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO recommendation_feedback (account_id, post_id, kind, created_at)'
            . ' VALUES (:account_id, :post_id, :kind, :created_at)'
            . ' ON CONFLICT (account_id, post_id) DO UPDATE SET kind = excluded.kind, created_at = excluded.created_at',
            [
                'account_id' => $accountId,
                'post_id' => $postId,
                'kind' => $kind,
                'created_at' => $now,
            ],
        );

        return ['post_id' => $postId, 'kind' => $kind];
    }

    /**
     * @return array<string, string> post id to "less" or "more"
     */
    private function feedback(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT post_id, kind FROM recommendation_feedback WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );

        $result = [];
        foreach ($rows as $row) {
            $postId = (string) ($row['post_id'] ?? '');
            $kind = (string) ($row['kind'] ?? '');
            if ($postId !== '' && ($kind === 'less' || $kind === 'more')) {
                $result[$postId] = $kind;
            }
        }

        return $result;
    }
}
