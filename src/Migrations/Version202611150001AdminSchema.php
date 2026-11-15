<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Host-owned tables for moderation and administration.
 *
 * Reports are a host concern: the core describes posts and accounts but has
 * no moderation queue. Keeping the queue here lets the admin surface stay a
 * first-party feature without inventing core concepts. `runtime_state` stores
 * small operational facts such as the worker heartbeat, and `admin_audit`
 * records every privileged mutation.
 */
final class Version202611150001AdminSchema implements Migration
{
    public function version(): string
    {
        return '202611150001';
    }

    public function description(): string
    {
        return 'Create the moderation report, admin audit and runtime state tables.';
    }

    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE moderation_report ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' reporter_id VARCHAR(64) NOT NULL,'
            . ' target_type VARCHAR(16) NOT NULL,'
            . ' target_id VARCHAR(512) NOT NULL,'
            . ' category VARCHAR(32) NOT NULL,'
            . ' comment TEXT NULL,'
            . ' state VARCHAR(16) NOT NULL,'
            . ' resolution_note VARCHAR(500) NULL,'
            . ' created_at VARCHAR(20) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL,'
            . ' resolved_at VARCHAR(20) NULL,'
            . ' resolved_by VARCHAR(64) NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX moderation_report_state ON moderation_report (state, created_at)');
        $connection->execute('CREATE INDEX moderation_report_target ON moderation_report (target_type, target_id)');
        $connection->execute('CREATE INDEX moderation_report_reporter ON moderation_report (reporter_id)');

        $connection->execute(
            'CREATE TABLE admin_audit ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' actor_id VARCHAR(64) NOT NULL,'
            . ' action VARCHAR(64) NOT NULL,'
            . ' target_type VARCHAR(32) NULL,'
            . ' target_id VARCHAR(512) NULL,'
            . ' details TEXT NULL,'
            . ' created_at VARCHAR(20) NOT NULL'
            . ')',
        );
        $connection->execute('CREATE INDEX admin_audit_created ON admin_audit (created_at)');

        $connection->execute(
            'CREATE TABLE runtime_state ('
            . ' name VARCHAR(32) NOT NULL PRIMARY KEY,'
            . ' value VARCHAR(255) NOT NULL,'
            . ' updated_at VARCHAR(20) NOT NULL'
            . ')',
        );
    }

    public function down(Connection $connection): void
    {
        foreach (['runtime_state', 'admin_audit', 'moderation_report'] as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
