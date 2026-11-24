<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Admin;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

/** Bounded retention for operational error rows; administrator audit is separate. */
final readonly class RuntimeErrorRetention
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{cutoff:string,matched:int,deleted:int,dry_run:bool,retention_days:int}
     */
    public function purge(int $retentionDays, int $limit = 100, bool $dryRun = false): array
    {
        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw new \InvalidArgumentException('Runtime error retention must be between 1 and 3650 days.');
        }
        $limit = max(1, min(1000, $limit));
        $cutoff = Time::format($this->clock->now()->modify('-' . $retentionDays . ' days'));
        $matched = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM runtime_error WHERE created_at < :cutoff',
            ['cutoff' => $cutoff],
        );
        if ($dryRun || $matched === 0) {
            return [
                'cutoff' => $cutoff,
                'matched' => $matched,
                'deleted' => 0,
                'dry_run' => $dryRun,
                'retention_days' => $retentionDays,
            ];
        }

        $deleted = $this->db->execute(
            'DELETE FROM runtime_error WHERE id IN ('
                . 'SELECT id FROM runtime_error WHERE created_at < :cutoff'
                . ' ORDER BY created_at ASC, id ASC LIMIT ' . $limit . ')',
            ['cutoff' => $cutoff],
        );

        return [
            'cutoff' => $cutoff,
            'matched' => $matched,
            'deleted' => $deleted,
            'dry_run' => false,
            'retention_days' => $retentionDays,
        ];
    }
}
