<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Migrations\Migration;

/**
 * Remembers that an account dismissed the first-run onboarding card, so the
 * guide stays gone across devices instead of relying on browser storage.
 */
final class Version202611280028OnboardingDismissal implements Migration
{
    public function version(): string
    {
        return '202611280028';
    }

    public function description(): string
    {
        return 'Persist per-account onboarding dismissal.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'ALTER TABLE account_preference ADD COLUMN onboarding_dismissed_at VARCHAR(20) NULL',
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('ALTER TABLE account_preference DROP COLUMN onboarding_dismissed_at');
    }
}
