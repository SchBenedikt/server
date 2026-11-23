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

use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function log;
use function max;
use function mb_strtolower;
use function min;
use function preg_match_all;
use function strcmp;
use function strtolower;
use function usort;

/**
 * A first-party "For You" ranking over local and federated public posts.
 *
 * The core owns the social graph, the engagement rows and the cache of remote
 * posts. This host service only reads them: it scores recent public posts by
 * engagement, freshness, followed authors and followed hashtags, then removes
 * muted and blocked authors and damps repeated authors so one account cannot
 * fill the page. Remote candidates are posts that local accounts actually
 * follow, so the feed reaches the fediverse without turning into a public
 * firehose.
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
            $remote = (int) ($candidate['remote'] ?? 0) === 1;

            // A post the reader asked to see less of never enters the feed.
            if (($feedback[$postId] ?? '') === 'less') {
                continue;
            }

            $published = Time::parse((string) $candidate['published_at'])->getTimestamp();
            $ageHours = max(0, ($now - $published) / 3600);
            $engagement = $remote ? 0 : (int) $candidate['favourites'] * 2
                + (int) $candidate['boosts'] * 3
                + (int) $candidate['replies'] * 1.5;
            $score = log(1 + $engagement) * 3.2;
            $score += max(0, 6.0 * (1 - $ageHours / 168));
            $reason = $remote
                ? 'From a fediverse account you follow'
                : 'From the instance and your circles';

            if (isset($followedAuthors[$authorId])) {
                $score += 5.0;
                $reason = $remote
                    ? 'Because you follow this account on the fediverse'
                    : 'Because you follow this account';
            }
            $tags = $remote
                ? $this->tagsFromText((string) ($candidate['content'] ?? ''))
                : $this->postTags($postId);
            $matching = array_values(array_intersect($tags, $followedTags));
            if ($matching !== []) {
                $score += min(3.0, count($matching) * 1.5);
                $reason = 'Because you follow #' . $matching[0];
            }
            if (!$remote && (int) $candidate['boosts'] > 0) {
                $score += 2.0;
            }
            if (($feedback[$postId] ?? '') === 'more') {
                $score += 4.0;
                $reason = 'Because you want to see more of this';
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
     * The instance-wide trending page.
     *
     * Unlike For You this is the same for every reader and needs no account:
     * it ranks the last week of public posts by engagement and freshness and
     * includes cached federated posts so the page is not a local echo. Repeat
     * authors are damped exactly as in the personal feed.
     *
     * @return array{
     *     posts: list<string>,
     *     reasons: array<string, string>,
     *     next: int|null,
     *     total: int
     * }
     */
    public function trending(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $now = $this->clock->now();
        $since = Time::format($now->modify('-7 days'));

        $local = $this->db->fetchAll(
            'SELECT p.id, p.author_id, p.published_at, 0 AS remote,'
            . ' (SELECT COUNT(*) FROM social_favourite f WHERE f.post_id = p.id) AS favourites,'
            . ' (SELECT COUNT(*) FROM social_boost b WHERE b.post_id = p.id) AS boosts,'
            . ' (SELECT COUNT(*) FROM social_post r WHERE r.in_reply_to_id = p.id AND r.deleted_at IS NULL) AS replies'
            . ' FROM social_post p'
            . " WHERE p.deleted_at IS NULL AND p.visibility = 'public' AND p.in_reply_to_id IS NULL AND p.published_at >= :since"
            . ' ORDER BY p.published_at DESC LIMIT ' . self::CANDIDATE_LIMIT,
            ['since' => $since],
        );

        $remote = $this->db->fetchAll(
            'SELECT r.activity_id AS id, r.actor_id AS author_id, r.content, r.published_at,'
            . ' 0 AS favourites, 0 AS boosts, 0 AS replies, 1 AS remote'
            . ' FROM social_remote_post r'
            . " WHERE r.deleted_at IS NULL AND r.kind = 'post' AND r.in_reply_to_id IS NULL AND r.published_at >= :since"
            . ' ORDER BY r.published_at DESC LIMIT ' . self::CANDIDATE_LIMIT,
            ['since' => $since],
        );

        $scored = [];
        foreach (array_merge($local, $remote) as $candidate) {
            $postId = (string) $candidate['id'];
            $remotePost = (int) ($candidate['remote'] ?? 0) === 1;
            $published = Time::parse((string) $candidate['published_at'])->getTimestamp();
            $ageHours = max(0, ($now->getTimestamp() - $published) / 3600);
            $engagement = $remotePost ? 0 : (int) $candidate['favourites'] * 2
                + (int) $candidate['boosts'] * 3
                + (int) $candidate['replies'] * 1.5;

            $scored[] = [
                'id' => $postId,
                'author_id' => (string) $candidate['author_id'],
                'score' => log(1 + $engagement) * 4.0 + max(0, 4.0 * (1 - $ageHours / 168)),
                'reason' => $remotePost
                    ? 'Popular across the fediverse right now'
                    : 'Popular on this instance',
            ];
        }

        $compare = static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return $b['id'] <=> $a['id'];
            }

            return $b['score'] <=> $a['score'];
        };

        usort($scored, $compare);

        $seenAuthors = [];
        foreach ($scored as $index => $entry) {
            $author = $entry['author_id'];
            $repeat = $seenAuthors[$author] ?? 0;
            $scored[$index]['score'] -= $repeat * 1.6;
            $seenAuthors[$author] = $repeat + 1;
        }
        usort($scored, $compare);

        $total = count($scored);
        $window = array_slice($scored, $offset, $limit);
        $reasons = [];
        foreach ($window as $entry) {
            $reasons[$entry['id']] = $entry['reason'];
        }

        $next = $offset + $limit;

        return [
            'posts' => array_values(array_column($window, 'id')),
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
        $now = $this->clock->now();
        $since = Time::format($now->modify('-7 days'));

        $local = $this->db->fetchAll(
            'SELECT p.id, p.author_id, p.published_at, p.content, 0 AS remote,'
            . ' (SELECT COUNT(*) FROM social_favourite f WHERE f.post_id = p.id) AS favourites,'
            . ' (SELECT COUNT(*) FROM social_boost b WHERE b.post_id = p.id) AS boosts,'
            . ' (SELECT COUNT(*) FROM social_post r WHERE r.in_reply_to_id = p.id AND r.deleted_at IS NULL) AS replies'
            . ' FROM social_post p'
            . " WHERE p.deleted_at IS NULL AND p.visibility = 'public' AND p.in_reply_to_id IS NULL"
            // Own posts stay in the feed. A single-author instance would
            // otherwise always see an empty feed even though posts exist.
            // No age window here: the 500 newest posts are candidates and the
            // score already damps older ones, so a quiet instance still has a
            // feed instead of an empty page after seven days.
            . ' AND p.author_id NOT IN ('
            . 'SELECT actor_id FROM social_mute WHERE user_id = :muted_viewer'
            . ' AND (expires_at IS NULL OR expires_at > :local_mute_now))'
            . ' AND p.author_id NOT IN (SELECT actor_id FROM social_block WHERE local_user_id = :blocked_viewer)'
            . ' ORDER BY p.published_at DESC LIMIT ' . self::CANDIDATE_LIMIT,
            [
                'muted_viewer' => $accountId,
                'local_mute_now' => Time::format($now),
                'blocked_viewer' => $accountId,
            ],
        );

        $remote = $this->db->fetchAll(
            'SELECT r.activity_id AS id, r.actor_id AS author_id, r.object_author_id,'
            . ' r.content, r.published_at, 0 AS favourites, 0 AS boosts, 0 AS replies, 1 AS remote'
            . ' FROM social_remote_post r'
            . " WHERE r.deleted_at IS NULL AND r.kind = 'post' AND r.in_reply_to_id IS NULL AND r.published_at >= :since"
            . ' AND r.actor_id IN ('
            . " SELECT f.actor_id FROM social_following f WHERE f.local_user_id = :remote_viewer AND f.state = 'accepted'"
            . ')'
            . ' AND NOT EXISTS ('
            . ' SELECT 1 FROM social_mute m WHERE m.user_id = :remote_mute_viewer'
            . ' AND (m.actor_id = r.actor_id OR m.actor_id = r.object_author_id)'
            . ' AND (m.expires_at IS NULL OR m.expires_at > :mute_now)'
            . ')'
            . ' AND NOT EXISTS ('
            . ' SELECT 1 FROM social_block b WHERE b.local_user_id = :remote_block_viewer'
            . ' AND (b.actor_id = r.actor_id OR b.actor_id = r.object_author_id)'
            . ')'
            . ' ORDER BY r.published_at DESC LIMIT ' . self::CANDIDATE_LIMIT,
            [
                'since' => $since,
                'remote_viewer' => $accountId,
                'remote_mute_viewer' => $accountId,
                'remote_block_viewer' => $accountId,
                'mute_now' => Time::format($now),
            ],
        );

        $candidates = array_merge($local, $remote);
        usort($candidates, static fn (array $a, array $b): int => strcmp(
            (string) $b['published_at'],
            (string) $a['published_at'],
        ));

        return array_slice($candidates, 0, self::CANDIDATE_LIMIT);
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
     * Remote posts arrive as text, not as a local hashtag index. Extracting the
     * tags here keeps the follow signal working without a second write path.
     *
     * @return list<string>
     */
    private function tagsFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]{1,100})/u', $text, $matches);
        $tags = [];
        foreach ($matches[1] ?? [] as $tag) {
            $normalized = mb_strtolower((string) $tag);
            if ($normalized !== '') {
                $tags[$normalized] = true;
            }
        }

        return array_keys($tags);
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
            throw CommunityException::invalid('The post is missing.', ['post_id' => 'Please choose a post.']);
        }
        if (!in_array($kind, ['less', 'more'], true)) {
            throw CommunityException::invalid(
                'This feedback is invalid.',
                ['kind' => 'Allowed values are "less" and "more".'],
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
