<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use Closure;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;

use function random_int;

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
    /** Keep the sweep horizon in step with every declared request window. */
    public const MAX_WINDOW_SECONDS = 3600;

    private readonly Closure $shouldSweep;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        ?Closure $shouldSweep = null,
    ) {
        $this->shouldSweep = $shouldSweep ?? static fn (): bool => random_int(1, 64) === 1;
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
        if ($windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new InvalidArgumentException('Rate limit windows must not exceed the cleanup horizon.');
        }

        $bucket = self::bucket($route, $identity);
        $now = $this->clock->now();
        $timestamp = $now->getTimestamp();
        $nowString = Time::format($now);
        $windowCutoff = Time::format($now->modify('-' . $windowSeconds . ' seconds'));

        if (($this->shouldSweep)()) {
            $cutoff = Time::format($now->modify('-' . self::MAX_WINDOW_SECONDS . ' seconds'));
            $this->db->execute(
                'DELETE FROM rate_limit WHERE window_started_at < :cutoff',
                ['cutoff' => $cutoff],
            );
        }

        // One conditional upsert reserves the slot. A read followed by an
        // update lets two workers admit the same last request; the conflict
        // update is evaluated against the committed row, so exactly `limit`
        // callers receive a row and everyone else receives no row.
        for ($tries = 0; $tries < 2; $tries++) {
            $reserved = $this->db->fetchOne(
                'INSERT INTO rate_limit (bucket, hits, window_started_at) VALUES (:bucket, 1, :now)'
                . ' ON CONFLICT (bucket) DO UPDATE SET'
                . ' hits = CASE WHEN rate_limit.window_started_at <= :reset_cutoff THEN 1 ELSE rate_limit.hits + 1 END,'
                . ' window_started_at = CASE WHEN rate_limit.window_started_at <= :window_cutoff THEN :reset_now ELSE rate_limit.window_started_at END'
                . ' WHERE rate_limit.window_started_at <= :where_cutoff OR rate_limit.hits < :limit'
                . ' RETURNING hits, window_started_at',
                [
                    'bucket' => $bucket,
                    'now' => $nowString,
                    'reset_cutoff' => $windowCutoff,
                    'window_cutoff' => $windowCutoff,
                    'reset_now' => $nowString,
                    'where_cutoff' => $windowCutoff,
                    'limit' => $limit,
                ],
            );

            if ($reserved !== null) {
                return null;
            }

            $row = $this->db->fetchOne(
                'SELECT hits, window_started_at FROM rate_limit WHERE bucket = :bucket',
                ['bucket' => $bucket],
            );

            if ($row === null) {
                continue;
            }

            $started = strtotime((string) $row['window_started_at']);
            if ($started === false) {
                $this->db->execute('DELETE FROM rate_limit WHERE bucket = :bucket', ['bucket' => $bucket]);

                continue;
            }

            $retryAfter = $started + $windowSeconds - $timestamp;
            if ($retryAfter <= 0) {
                // The row expired between the failed upsert and this read;
                // retry against the new window instead of rejecting it.
                continue;
            }

            return $retryAfter;
        }

        // A concurrent reset or clock-boundary race removed the row twice.
        // Deny for one second rather than admitting without a reservation.
        return 1;
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
