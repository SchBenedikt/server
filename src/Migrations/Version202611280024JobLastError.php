<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Adds `social_job.last_error` so dead and retrying jobs carry the sanitized
 * failure reason operators need for diagnostics. Additive and idempotent via
 * the shared core schema upgrade; existing rows keep NULL.
 */
final class Version202611280024JobLastError implements Migration
{
    public function version(): string
    {
        return '202611280024';
    }

    public function description(): string
    {
        return 'Record the last failure reason on queue jobs.';
    }

    public function up(Connection $connection): void
    {
        JobSchema::upgrade($connection);
    }

    public function down(Connection $connection): void
    {
        // The column is kept on rollback: it is additive diagnostics data and
        // dropping it would erase failure history the admin UI may show.
    }
}
