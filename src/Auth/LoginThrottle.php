<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use Throwable;

/**
 * A fixed-window failed-login throttle, keyed by handle and client address.
 *
 * Five failures per fifteen minutes are tolerated. The counter is stored in
 * the database, so a restart does not reset an attack and two PHP-FPM workers
 * share one budget.
 */
final class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const WINDOW_SECONDS = 900;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function isLimited(string $handle, ?string $ip): bool
    {
        $row = $this->row($this->bucket($handle, $ip));
        if ($row === null) {
            return false;
        }

        if ($this->windowExpired((string) $row['window_started_at'])) {
            return false;
        }

        return (int) $row['attempts'] >= self::MAX_ATTEMPTS;
    }

    public function recordFailure(string $handle, ?string $ip): void
    {
        $bucket = $this->bucket($handle, $ip);
        $now = Time::format($this->clock->now());
        $row = $this->row($bucket);

        if ($row === null || $this->windowExpired((string) $row['window_started_at'])) {
            $this->db->execute(
                'DELETE FROM login_attempt WHERE bucket = :bucket',
                ['bucket' => $bucket],
            );
            $this->db->execute(
                'INSERT INTO login_attempt (bucket, attempts, window_started_at) VALUES (:bucket, 1, :now)',
                ['bucket' => $bucket, 'now' => $now],
            );

            return;
        }

        $this->db->execute(
            'UPDATE login_attempt SET attempts = attempts + 1 WHERE bucket = :bucket',
            ['bucket' => $bucket],
        );
    }

    public function clear(string $handle, ?string $ip): void
    {
        $this->db->execute(
            'DELETE FROM login_attempt WHERE bucket = :bucket',
            ['bucket' => $this->bucket($handle, $ip)],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $bucket): ?array
    {
        try {
            return $this->db->fetchOne(
                'SELECT attempts, window_started_at FROM login_attempt WHERE bucket = :bucket',
                ['bucket' => $bucket],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function windowExpired(string $startedAt): bool
    {
        $start = strtotime($startedAt);
        if ($start === false) {
            return true;
        }

        return $start + self::WINDOW_SECONDS <= $this->clock->now()->getTimestamp();
    }

    private function bucket(string $handle, ?string $ip): string
    {
        return hash('sha256', strtolower(trim($handle)) . '|' . ($ip ?? 'unknown'));
    }
}
