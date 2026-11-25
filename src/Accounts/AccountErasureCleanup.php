<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;

/**
 * Removes host-owned private account data after Core has frozen the account.
 * The disabled account row remains as an irreversible tombstone; audit and
 * moderation records remain for accountability and point to that tombstone.
 */
final readonly class AccountErasureCleanup
{
    public function __construct(
        private Connection $db,
        private ActivityFactory $activities,
        private Clock $clock,
    ) {
    }

    /**
     * @return array<string, int> Deleted row counts by data group.
     */
    public function erase(string $accountId): array
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new \InvalidArgumentException('Account erasure cleanup needs an account id.');
        }

        $actorId = $this->activities->actorId($accountId);

        $now = Time::format($this->clock->now());

        return $this->db->transaction(function (Connection $db) use ($accountId, $actorId, $now): array {
            $account = $db->fetchOne(
                'SELECT handle, email FROM account WHERE id = :account_id',
                ['account_id' => $accountId],
            );
            if ($account === null) {
                throw new \InvalidArgumentException('Account erasure cleanup cannot find the host account.');
            }
            $token = bin2hex(random_bytes(32));
            $handle = (string) ($account['handle'] ?? '');
            $email = (string) ($account['email'] ?? '');
            if (preg_match('/^deleted-([a-f0-9]{64})$/', $handle, $match) === 1
                && $email === 'deleted+' . $match[1] . '@example.invalid'
            ) {
                $token = $match[1];
            }
            $deletedHandle = 'deleted-' . $token;
            $deletedEmail = 'deleted+' . $token . '@example.invalid';

            $deleted = [];
            $delete = static function (string $name, string $sql, array $parameters) use ($db, &$deleted): void {
                $deleted[$name] = $db->execute($sql, $parameters);
            };
            $user = ['account_id' => $accountId];

            $delete('sessions', 'DELETE FROM session WHERE account_id = :account_id', $user);
            $delete('atproto_sessions', 'DELETE FROM atproto_session WHERE account_id = :account_id', $user);
            $delete(
                'linked_bluesky_credentials',
                'DELETE FROM atproto_account WHERE account_id = :account_id',
                $user,
            );
            $delete(
                'linked_atproto_oauth_account',
                'DELETE FROM atproto_oauth_account WHERE account_id = :account_id',
                $user,
            );
            $delete(
                'follow_requests',
                'DELETE FROM follow_request WHERE target_account_id = :account_id OR actor_id = :actor_id',
                ['account_id' => $accountId, 'actor_id' => $actorId],
            );
            $delete('announcements', 'DELETE FROM announcement WHERE author_id = :account_id', $user);
            $delete(
                'story_views',
                'DELETE FROM story_seen WHERE account_id = :viewer_id OR story_id IN'
                    . ' (SELECT id FROM story WHERE account_id = :owner_id)',
                ['viewer_id' => $accountId, 'owner_id' => $accountId],
            );
            $delete('stories', 'DELETE FROM story WHERE account_id = :account_id', $user);
            $delete(
                'collection_items',
                'DELETE FROM collection_item WHERE collection_id IN'
                    . ' (SELECT id FROM collection WHERE account_id = :account_id)',
                $user,
            );
            $delete('collections', 'DELETE FROM collection WHERE account_id = :account_id', $user);
            $delete(
                'kokua_replies',
                'DELETE FROM kokua_help WHERE account_id = :helper_id OR kokua_id IN'
                    . ' (SELECT id FROM kokua WHERE account_id = :owner_id)',
                ['helper_id' => $accountId, 'owner_id' => $accountId],
            );
            $delete('kokua_posts', 'DELETE FROM kokua WHERE account_id = :account_id', $user);
            $delete('drafts', 'DELETE FROM draft WHERE account_id = :account_id', $user);
            $delete('archived_posts', 'DELETE FROM archived_post WHERE account_id = :account_id', $user);
            $delete(
                'recommendation_feedback',
                'DELETE FROM recommendation_feedback WHERE account_id = :account_id',
                $user,
            );
            $delete(
                'recommendation_snapshots',
                'DELETE FROM recommendation_snapshot WHERE account_id = :account_id',
                $user,
            );
            $delete(
                'recommendation_impressions',
                'DELETE FROM recommendation_impression WHERE account_id = :account_id',
                $user,
            );
            $delete('featured_tags', 'DELETE FROM featured_tag WHERE account_id = :account_id', $user);
            $delete('account_preferences', 'DELETE FROM account_preference WHERE account_id = :account_id', $user);
            $delete('password_resets', 'DELETE FROM password_reset WHERE account_id = :account_id', $user);
            $delete(
                'atproto_oauth_flows',
                'DELETE FROM atproto_oauth_flow WHERE account_id = :account_id',
                $user,
            );
            $delete(
                'mastodon_oauth_codes',
                'DELETE FROM mastodon_oauth_code WHERE account_id = :account_id',
                $user,
            );
            $delete(
                'mastodon_oauth_tokens',
                'DELETE FROM mastodon_oauth_token WHERE account_id = :account_id',
                $user,
            );
            $notificationIds = array_fill_keys(array_map(
                static fn (array $row): string => (string) $row['id'],
                $db->fetchAll(
                    'SELECT id FROM social_notification WHERE recipient_id = :recipient_id OR actor_id = :actor_id'
                        . ' OR post_id IN (SELECT id FROM social_post WHERE author_id = :post_author_id)',
                    ['recipient_id' => $accountId, 'actor_id' => $accountId, 'post_author_id' => $accountId],
                ),
            ), true);
            if ($notificationIds !== []) {
                foreach ($db->fetchAll(
                    "SELECT sequence, payload_json FROM mastodon_stream_event WHERE event_name = 'notification.created'",
                ) as $event) {
                    $payload = json_decode((string) $event['payload_json'], true);
                    if (is_array($payload) && isset($notificationIds[$payload['notification_id'] ?? ''])) {
                        $db->execute(
                            'DELETE FROM mastodon_stream_event WHERE sequence = :sequence',
                            ['sequence' => (int) $event['sequence']],
                        );
                    }
                }
            }
            $delete(
                'mastodon_stream_events',
                'DELETE FROM mastodon_stream_event WHERE actor_id = :actor_id OR sequence IN'
                    . ' (SELECT event_sequence FROM mastodon_stream_event_recipient WHERE account_id = :recipient_account_id)',
                ['actor_id' => $accountId, 'recipient_account_id' => $accountId],
            );

            $db->execute(
                'UPDATE account SET handle = :handle, email = :email, display_name = NULL, password_hash = :password_hash,'
                    . ' disabled_at = COALESCE(disabled_at, :disabled_at)'
                    . ' WHERE id = :account_id',
                [
                    'handle' => $deletedHandle,
                    'email' => $deletedEmail,
                    'password_hash' => '!account-erased!',
                    'disabled_at' => $now,
                    'account_id' => $accountId,
                ],
            );

            return $deleted;
        });
    }

    /**
     * Finish host-owned cleanup for Core deletions that completed after the
     * original HTTP request returned. This scan is safe to repeat: `erase()`
     * is transactional and preserves the generated tombstone on retries.
     *
     * @return array{processed: int, failed: int}
     */
    public function finalizeCompleted(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Account erasure batch size must be between 1 and 1000.');
        }

        $rows = $this->db->fetchAll(
            'SELECT deletion.user_id FROM ' . AccountDeletionSchema::TABLE . ' deletion'
            . ' INNER JOIN account ON account.id = deletion.user_id'
            . " WHERE deletion.state = 'erased' AND account.handle NOT LIKE 'deleted-%'"
            . ' ORDER BY deletion.completed_at, deletion.user_id LIMIT :limit',
            ['limit' => $limit],
        );

        $processed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $accountId = is_string($row['user_id'] ?? null) ? trim($row['user_id']) : '';
            if ($accountId === '') {
                continue;
            }

            try {
                $this->erase($accountId);
                ++$processed;
            } catch (\Throwable) {
                // A failed account remains eligible and will be retried on a
                // later worker tick; it must not block other completed erasures.
                ++$failed;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
