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

use function max;
use function min;

/**
 * "On this day": the viewer's own posts from earlier years.
 *
 * The query deliberately reads by month and day rather than a stored
 * anniversary index. It is a small, read-only projection over the author
 * index the core already maintains.
 */
final class Memories
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     date: string,
     *     total: int,
     *     years: list<array{year: int, posts: list<array{id: string, published_at: string}>}>
     * }
     */
    public function forAccount(string $accountId, int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        $now = $this->clock->now();
        $day = $now->format('m-d');
        $thisYear = $now->format('Y') . '-01-01T00:00:00Z';

        $rows = $this->db->fetchAll(
            'SELECT id, published_at FROM social_post'
            . ' WHERE author_id = :author_id AND deleted_at IS NULL'
            . " AND visibility <> 'direct'"
            . ' AND SUBSTR(published_at, 6, 5) = :day'
            . ' AND published_at < :this_year'
            . ' ORDER BY published_at DESC LIMIT ' . $limit,
            [
                'author_id' => $accountId,
                'day' => $day,
                'this_year' => $thisYear,
            ],
        );

        $years = [];
        foreach ($rows as $row) {
            $published = (string) $row['published_at'];
            $year = (int) substr($published, 0, 4);
            $years[$year] ??= ['year' => $year, 'posts' => []];
            $years[$year]['posts'][] = ['id' => (string) $row['id'], 'published_at' => $published];
        }

        krsort($years);

        return [
            'date' => $day,
            'total' => count($rows),
            'years' => array_values($years),
        ];
    }
}
