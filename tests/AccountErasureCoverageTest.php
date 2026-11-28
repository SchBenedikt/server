<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Account\PdoAccountDataRepository;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * Every table in the schema must be classified for account erasure: either
 * the core account-data repository deletes its rows, the host cleanup does,
 * or the table is retained on purpose. A new table without a classification
 * fails here instead of silently leaking or being dropped.
 */
final class AccountErasureCoverageTest extends TestCase
{
    /**
     * Host-owned tables deleted by AccountErasureCleanup that the core
     * repository does not already cover. Tables both layers touch
     * (social_story_*, social_media_collection_*, social_post_pin) are
     * listed once under the core registry.
     */
    private const HOST_ERASED = [
        'account_preference',
        'announcement',
        'atproto_account',
        'atproto_oauth_account',
        'atproto_oauth_flow',
        'atproto_session',
        'draft',
        'email_change',
        'email_verification',
        'featured_tag',
        'follow_request',
        'kokua',
        'kokua_help',
        'mail_outbox',
        'mastodon_oauth_code',
        'mastodon_oauth_token',
        'mastodon_stream_event',
        // Recipient rows cascade when the cleanup deletes the events the
        // account received.
        'mastodon_stream_event_recipient',
        'password_reset',
        'recommendation_feedback',
        'recommendation_impression',
        'recommendation_snapshot',
        'session',
        'social_starter_pack',
        'social_starter_pack_member',
    ];

    /** Tables kept after erasure, with the reason they hold no erasable account data. */
    private const RETAINED = [
        'account' => 'tombstoned in place: handle and email rewritten, credentials destroyed',
        'admin_audit' => 'operator audit trail',
        'installation_identity' => 'instance origin binding, not account data',
        'instance_key' => 'instance signing key',
        'login_attempt' => 'bucketed throttle counters without an account reference',
        'mastodon_oauth_app' => 'registered OAuth clients are instance configuration',
        'moderation_report' => 'moderation accountability record',
        'rate_limit' => 'ephemeral per-bucket counters',
        'runtime_error' => 'operations log',
        'runtime_state' => 'instance runtime state',
        'schema_migration' => 'migration bookkeeping',
        'social_account_deletion' => 'deletion state record that drives finalization',
        'social_activity_log' => 'audit trail',
        'social_atproto_remote_account' => 'cached remote actors are peer data',
        'social_atproto_remote_record' => 'cached remote repository records are peer data',
        'social_atproto_sync_checkpoint' => 'AT Protocol sync position',
        'social_job' => 'transient queue rows keyed by job subject',
        'social_moderation_action' => 'moderation audit trail',
        'social_pds_sequence' => 'global firehose sequence counter',
        'social_remote_actor_profile' => 'cached remote actor profiles are peer data',
        'social_remote_engagement' => 'cached remote engagement is peer data',
        'social_remote_pending_update' => 'pending remote object refreshes are peer data',
        'social_remote_poll' => 'cached remote polls are peer data',
        'social_remote_post' => 'cached remote posts are peer data',
        'social_remote_post_attachment' => 'cached remote attachments are peer data',
        'social_remote_reply' => 'cached remote replies are peer data',
        'social_remote_thread_post' => 'cached remote thread posts are peer data',
        'social_search_trend' => 'aggregate trend counters',
    ];

    public function testEveryTableIsClassifiedForAccountErasure(): void
    {
        $server = new TestServer();
        $actual = array_column(
            $server->runtime->database->fetchAll(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            ),
            'name',
        );

        $classified = array_merge(
            PdoAccountDataRepository::erasedTables(),
            self::HOST_ERASED,
            array_keys(self::RETAINED),
        );
        sort($classified);

        self::assertSame($actual, $classified, 'Unclassified tables: ' . implode(', ', array_diff($actual, $classified)));
        self::assertSame($classified, array_values(array_unique($classified)), 'A table is classified twice.');
    }

    public function testEveryHostErasedTableIsDeletedByTheCleanup(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/Accounts/AccountErasureCleanup.php',
        );

        foreach (self::HOST_ERASED as $table) {
            self::assertStringContainsString(
                $table,
                $source,
                $table . ' is classified as host-erased but never appears in AccountErasureCleanup.',
            );
        }
    }
}
