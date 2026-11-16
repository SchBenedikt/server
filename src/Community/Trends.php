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

use function arsort;
use function array_slice;
use function count;
use function max;
use function min;
use function parse_url;
use function preg_match_all;
use function rtrim;
use function strtolower;

/**
 * Local trends: hashtags and links used in recent public posts.
 *
 * The core already indexes hashtags per post; aggregating them needs no
 * second index and no remote call. Links are extracted from the post text at
 * read time because a link preview is cosmetic, not a stored relation.
 */
final class Trends
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     tags: list<array{tag: string, posts: int, accounts: int}>,
     *     links: list<array{url: string, host: string, posts: int}>
     * }
     */
    public function trends(int $days = 7, int $limit = 10): array
    {
        $days = max(1, min(30, $days));
        $limit = max(1, min(25, $limit));

        return [
            'tags' => $this->hashtags($days, $limit),
            'links' => $this->links($days, $limit),
        ];
    }

    /**
     * @return list<array{tag: string, posts: int, accounts: int}>
     */
    public function hashtags(int $days = 7, int $limit = 10): array
    {
        $days = max(1, min(30, $days));
        $limit = max(1, min(25, $limit));

        $rows = $this->db->fetchAll(
            'SELECT h.hashtag AS tag, COUNT(DISTINCT p.id) AS posts, COUNT(DISTINCT p.author_id) AS accounts'
            . ' FROM social_post_hashtag h'
            . ' JOIN social_post p ON p.id = h.post_id'
            . " WHERE p.deleted_at IS NULL AND p.visibility = 'public' AND p.published_at >= :since"
            . ' GROUP BY h.hashtag'
            . ' ORDER BY posts DESC, accounts DESC, h.hashtag ASC LIMIT ' . $limit,
            ['since' => $this->since($days)],
        );

        return array_map(
            static fn (array $row): array => [
                'tag' => (string) $row['tag'],
                'posts' => (int) $row['posts'],
                'accounts' => (int) $row['accounts'],
            ],
            $rows,
        );
    }

    /**
     * @return list<array{url: string, host: string, posts: int}>
     */
    public function links(int $days = 7, int $limit = 10): array
    {
        $days = max(1, min(30, $days));
        $limit = max(1, min(25, $limit));

        $rows = $this->db->fetchAll(
            'SELECT content FROM social_post'
            . " WHERE deleted_at IS NULL AND visibility = 'public' AND published_at >= :since"
            . ' ORDER BY published_at DESC LIMIT 500',
            ['since' => $this->since($days)],
        );

        $counts = [];
        foreach ($rows as $row) {
            $content = (string) ($row['content'] ?? '');
            if ($content === '' || preg_match_all('#https?://[^\s<>"\']+#iu', $content, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $url) {
                $url = rtrim($url, '.,;:!?)]}');
                $host = parse_url($url, PHP_URL_HOST);
                if (!is_string($host) || $host === '') {
                    continue;
                }

                $key = strtolower($url);
                $counts[$key] ??= ['url' => $url, 'host' => strtolower($host), 'posts' => 0];
                $counts[$key]['posts']++;
            }
        }

        arsort($counts);

        return array_slice(array_values($counts), 0, $limit);
    }

    private function since(int $days): string
    {
        return Time::format($this->clock->now()->modify('-' . $days . ' days'));
    }
}
