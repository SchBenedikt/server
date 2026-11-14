<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

/**
 * A fixed-window request limiter.
 *
 * The core declares a budget per route (`30/900` means 30 requests per 900
 * seconds) and leaves enforcement to the host; this class is that
 * enforcement. The counter lives in the database so PHP-FPM workers share one
 * budget and a restart does not reset it.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return int|null null when the request may proceed, otherwise the
     *                  number of seconds until the window resets
     */
    public function attempt(string $route, string $identity, int $limit, int $windowSeconds): ?int
    {
        if ($limit < 1 || $windowSeconds < 1) {
            return null;
        }

        $bucket = self::bucket($route, $identity);
        $now = $this->clock->now();

        return $this->db->transaction(function () use ($bucket, $now, $limit, $windowSeconds): ?int {
            $row = $this->db->fetchOne(
                'SELECT hits, window_started_at FROM rate_limit WHERE bucket = :bucket',
                ['bucket' => $bucket],
            );

            $started = $row === null ? false : strtotime((string) $row['window_started_at']);

            if ($row === null || $started === false || $started + $windowSeconds <= $now->getTimestamp()) {
                $this->db->execute('DELETE FROM rate_limit WHERE bucket = :bucket', ['bucket' => $bucket]);
                $this->db->execute(
                    'INSERT INTO rate_limit (bucket, hits, window_started_at) VALUES (:bucket, 1, :now)',
                    ['bucket' => $bucket, 'now' => Time::format($now)],
                );

                return null;
            }

            if ((int) $row['hits'] >= $limit) {
                return max(1, $started + $windowSeconds - $now->getTimestamp());
            }

            $this->db->execute(
                'UPDATE rate_limit SET hits = hits + 1 WHERE bucket = :bucket',
                ['bucket' => $bucket],
            );

            return null;
        });
    }

    public function reset(string $route, string $identity): void
    {
        $this->db->execute(
            'DELETE FROM rate_limit WHERE bucket = :bucket',
            ['bucket' => self::bucket($route, $identity)],
        );
    }

    public static function bucket(string $route, string $identity): string
    {
        return hash('sha256', $route . '|' . $identity);
    }
}
