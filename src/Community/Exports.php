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
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Config\HostConfig;

use function array_map;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rawurlencode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A portable, read-only snapshot of one account's own data.
 *
 * The export is deliberately a document, not a database dump: it contains the
 * profile, the account's posts and their attachment metadata, the social graph
 * the account created, and the private markers it owns (bookmarks, drafts,
 * collection, filters, preferences). It never contains another account's
 * private data and never includes password material.
 */
final class Exports
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Preferences $preferences,
        private readonly HostConfig $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Account $account): array
    {
        $accountId = $account->id;

        return [
            'format' => 'aloha-account-export',
            'version' => 1,
            'exported_at' => Time::format($this->clock->now()),
            'instance' => [
                'url' => $this->config->instanceUrl,
                'name' => $this->config->instanceName,
            ],
            'profile' => [
                'id' => $account->id,
                'handle' => $account->handle,
                'email' => $account->email,
                'display_name' => $account->displayName,
                'created_at' => $account->createdAt,
                'role' => $account->role,
            ],
            'preferences' => $this->preferences->forAccount($accountId),
            'posts' => $this->posts($accountId),
            'favourites' => $this->postIdRows('social_favourite', 'user_id', $accountId, 'created_at'),
            'bookmarks' => $this->postIdRows('social_bookmark', 'user_id', $accountId, 'created_at'),
            'following' => $this->rows(
                'SELECT actor_id, inbox_url, followed_at, state FROM social_following'
                . ' WHERE local_user_id = :account_id ORDER BY followed_at ASC, actor_id ASC',
                ['account_id' => $accountId],
            ),
            'followers' => $this->rows(
                'SELECT actor_id, inbox_url, followed_at, is_local FROM social_follower'
                . ' WHERE local_user_id = :account_id ORDER BY followed_at ASC, actor_id ASC',
                ['account_id' => $accountId],
            ),
            'followed_hashtags' => $this->rows(
                'SELECT hashtag, created_at FROM social_followed_hashtag'
                . ' WHERE user_id = :account_id ORDER BY hashtag ASC',
                ['account_id' => $accountId],
            ),
            'mutes' => $this->rows(
                'SELECT actor_id, notifications, created_at, expires_at FROM social_mute'
                . ' WHERE user_id = :account_id ORDER BY created_at ASC',
                ['account_id' => $accountId],
            ),
            'blocks' => $this->rows(
                'SELECT actor_id, blocked_at FROM social_block'
                . ' WHERE local_user_id = :account_id ORDER BY blocked_at ASC',
                ['account_id' => $accountId],
            ),
            'filters' => $this->filters($accountId),
            'featured_tags' => $this->rows(
                'SELECT tag, position, created_at FROM featured_tag'
                . ' WHERE account_id = :account_id ORDER BY position ASC, tag ASC',
                ['account_id' => $accountId],
            ),
            'follow_requests' => $this->rows(
                'SELECT actor_id, actor_handle, is_local, created_at FROM follow_request'
                . ' WHERE target_account_id = :account_id ORDER BY created_at ASC, actor_id ASC',
                ['account_id' => $accountId],
            ),
            'announcements' => $this->rows(
                'SELECT id, content, active, created_at, updated_at, expires_at FROM announcement'
                . ' WHERE author_id = :account_id ORDER BY created_at ASC, id ASC',
                ['account_id' => $accountId],
            ),
            'stories' => $this->rows(
                'SELECT id, content, background, created_at, expires_at FROM story'
                . ' WHERE account_id = :account_id ORDER BY created_at ASC, id ASC',
                ['account_id' => $accountId],
            ),
            'story_views' => $this->rows(
                'SELECT story_id, seen_at FROM story_seen'
                . ' WHERE account_id = :account_id ORDER BY seen_at ASC, story_id ASC',
                ['account_id' => $accountId],
            ),
            'lists' => $this->lists($accountId),
            'collections' => $this->collections($accountId),
            'drafts' => $this->rows(
                'SELECT context, content, content_warning, visibility, attachment_ids, in_reply_to_id,'
                . ' quoted_post_id, poll_options, poll_multiple, poll_hide_totals, poll_expires_in, updated_at'
                . ' FROM draft WHERE account_id = :account_id ORDER BY updated_at ASC',
                ['account_id' => $accountId],
            ),
            'archived_posts' => $this->rows(
                'SELECT post_id, archived_at FROM archived_post'
                . ' WHERE account_id = :account_id ORDER BY archived_at ASC, post_id ASC',
                ['account_id' => $accountId],
            ),
            'recommendation_feedback' => $this->rows(
                'SELECT post_id, kind, created_at FROM recommendation_feedback'
                . ' WHERE account_id = :account_id ORDER BY created_at ASC, post_id ASC',
                ['account_id' => $accountId],
            ),
            'kokua_posts' => $this->rows(
                'SELECT id, kind, title, details, category, place, needed_by, status, created_at, updated_at, done_at'
                . ' FROM kokua WHERE account_id = :account_id ORDER BY created_at ASC, id ASC',
                ['account_id' => $accountId],
            ),
            'kokua_responses' => $this->rows(
                'SELECT kokua_id, message, created_at FROM kokua_help'
                . ' WHERE account_id = :account_id ORDER BY created_at ASC, kokua_id ASC',
                ['account_id' => $accountId],
            ),
            'scheduled_posts' => $this->rows(
                'SELECT content, content_warning, visibility, attachment_ids, recipient_ids, in_reply_to_id,'
                . ' scheduled_at, created_at, state, published_at, failure_reason'
                . ' FROM social_scheduled_post WHERE author_id = :account_id ORDER BY scheduled_at ASC',
                ['account_id' => $accountId],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, content, content_warning, visibility, in_reply_to_id, published_at, edited_at,'
            . ' quoted_object_id, quote_state, revision'
            . ' FROM social_post WHERE author_id = :account_id AND deleted_at IS NULL'
            . ' ORDER BY published_at ASC, id ASC',
            ['account_id' => $accountId],
        );

        $posts = [];
        foreach ($rows as $row) {
            $postId = (string) $row['id'];
            $attachments = $this->db->fetchAll(
                'SELECT a.id, a.mime_type, a.alt_text, a.width, a.height, a.byte_size'
                . ' FROM social_post_attachment pa JOIN social_attachment a ON a.id = pa.attachment_id'
                . ' WHERE pa.post_id = :post_id ORDER BY pa.position ASC',
                ['post_id' => $postId],
            );
            $hashtags = $this->db->fetchAll(
                'SELECT hashtag FROM social_post_hashtag WHERE post_id = :post_id ORDER BY position ASC',
                ['post_id' => $postId],
            );

            $posts[] = [
                'id' => $postId,
                'content' => (string) $row['content'],
                'content_warning' => is_string($row['content_warning'] ?? null) ? $row['content_warning'] : null,
                'visibility' => (string) $row['visibility'],
                'in_reply_to_id' => is_string($row['in_reply_to_id'] ?? null) ? $row['in_reply_to_id'] : null,
                'published_at' => (string) $row['published_at'],
                'edited_at' => is_string($row['edited_at'] ?? null) ? $row['edited_at'] : null,
                'quoted_object_id' => is_string($row['quoted_object_id'] ?? null) ? $row['quoted_object_id'] : null,
                'quote_state' => is_string($row['quote_state'] ?? null) ? $row['quote_state'] : null,
                'revision' => (int) ($row['revision'] ?? 1),
                'url' => $this->config->instanceUrl . '/api/v1/posts/' . rawurlencode($postId),
                'attachments' => $attachments,
                'hashtags' => array_map(static fn (array $tag): string => (string) $tag['hashtag'], $hashtags),
            ];
        }

        return $posts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filters(string $accountId): array
    {
        $rows = $this->rows(
            'SELECT name, action, contexts, keywords, created_at, expires_at FROM social_filter'
            . ' WHERE user_id = :account_id ORDER BY created_at ASC',
            ['account_id' => $accountId],
        );

        foreach ($rows as $index => $row) {
            $decoded = json_decode(is_string($row['contexts'] ?? null) ? $row['contexts'] : '[]', true);
            $rows[$index]['contexts'] = is_array($decoded) ? $decoded : [];
            $keywords = json_decode(is_string($row['keywords'] ?? null) ? $row['keywords'] : '[]', true);
            $rows[$index]['keywords'] = is_array($keywords) ? $keywords : [];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lists(string $accountId): array
    {
        $lists = $this->rows(
            'SELECT id, title, replies_policy, exclusive, created_at, updated_at FROM social_account_list'
            . ' WHERE user_id = :account_id ORDER BY created_at ASC',
            ['account_id' => $accountId],
        );

        foreach ($lists as $index => $list) {
            $lists[$index]['members'] = $this->rows(
                'SELECT account_id, created_at FROM social_account_list_member'
                . ' WHERE list_id = :list_id ORDER BY created_at ASC',
                ['list_id' => (string) $list['id']],
            );
        }

        return $lists;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collections(string $accountId): array
    {
        $collections = $this->rows(
            'SELECT id, title, description, is_public, created_at, updated_at FROM collection'
            . ' WHERE account_id = :account_id ORDER BY created_at ASC',
            ['account_id' => $accountId],
        );

        foreach ($collections as $index => $collection) {
            $collections[$index]['items'] = $this->rows(
                'SELECT post_id, position, created_at FROM collection_item'
                . ' WHERE collection_id = :collection_id ORDER BY position ASC',
                ['collection_id' => (string) $collection['id']],
            );
        }

        return $collections;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postIdRows(string $table, string $column, string $accountId, string $order): array
    {
        return $this->rows(
            'SELECT post_id, ' . $order . ' FROM ' . $table
            . ' WHERE ' . $column . ' = :account_id ORDER BY ' . $order . ' ASC',
            ['account_id' => $accountId],
        );
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        return $this->db->fetchAll($sql, $parameters);
    }

    /**
     * Serialises an export as pretty, UTF-8 JSON.
     *
     * @param array<string, mixed> $export
     */
    public static function toJson(array $export): string
    {
        return json_encode(
            $export,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }
}
