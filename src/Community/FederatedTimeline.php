<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Federation\RemoteActorProfiles;
use AlohaSocial\Server\Federation\RemoteEngagement;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function end;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function ltrim;
use function max;
use function mb_strlen;
use function min;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function rtrim;
use function strtolower;
use function trim;

/**
 * The instance's federated timeline.
 *
 * A Nextcloud Social reader expects three public timelines: the local one,
 * the accounts they follow, and everything this instance has received from
 * other servers. The core home timeline only carries followed actors, and the
 * public timeline is local by design, so the federated view is a host
 * projection over the remote-post store.
 *
 * The store already contains every public and follower-visible post this
 * instance accepted, including boosts. Reading it is a bounded database
 * query: no network request is made for a feed page, which keeps scrolling a
 * federated timeline cheap and independent of peer availability.
 *
 * Cards are presented through the same media pipeline as the home timeline,
 * so cached remote attachments, alt text, counter snapshots and boost
 * wrappers render exactly like a followed post.
 */
final class FederatedTimeline
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 40;

    private const MAX_TAG_LENGTH = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly RemoteEngagement $engagement,
        private readonly ?MediaService $media = null,
        private readonly ?RemoteActorProfiles $actorProfiles = null,
    ) {
    }

    /**
     * One page of the federated timeline, newest first.
     *
     * `before`/`before_id` are the keyset cursor returned as `next_before` and
     * `next_before_id`. A cursor pair rather than an offset keeps the page
     * stable while new posts arrive at the top.
     *
     * @return array{
     *     posts: list<array<string, mixed>>,
     *     next_before: ?string,
     *     next_before_id: ?string,
     *     has_more: bool
     * }
     */
    public function page(
        int $limit = self::DEFAULT_LIMIT,
        ?string $before = null,
        ?string $beforeId = null,
        ?string $tag = null,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // This timeline is a discovery listing readable without an account.
        // Only a verified public audience may appear: unlisted stays out of
        // every listing by definition, followers-only and unknown audiences
        // require a follow, and direct rows are never stored for display.
        $where = ['deleted_at IS NULL', "audience = 'public'"];
        $parameters = [];

        $tag = $this->normalizeTag($tag);
        if ($tag !== null) {
            // LIKE is case-sensitive on PostgreSQL and case-insensitive on
            // SQLite; lowering both sides gives the same result on both.
            $where[] = 'LOWER(content) LIKE :tag';
            $parameters['tag'] = '%#' . strtolower($tag) . '%';
        }

        $before = $before !== null ? trim($before) : null;
        if ($before !== null && $before !== '') {
            $parameters['before'] = $before;
            $beforeId = $beforeId !== null ? trim($beforeId) : '';
            if ($beforeId !== '') {
                $where[] = '(published_at < :before OR (published_at = :before AND activity_id < :before_id))';
                $parameters['before_id'] = $beforeId;
            } else {
                $where[] = 'published_at < :before';
            }
        }

        $rows = $this->db->fetchAll(
            'SELECT activity_id, object_id, object_author_id, actor_id, kind, content,'
            . ' content_warning, published_at, in_reply_to_id, quoted_object_id'
            . ' FROM social_remote_post'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY published_at DESC, activity_id DESC'
            . ' LIMIT ' . ($limit + 1),
            $parameters,
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $entries = [];
        foreach ($rows as $row) {
            $activityId = (string) ($row['activity_id'] ?? '');
            $objectId = (string) ($row['object_id'] ?? '');
            $actorId = (string) ($row['actor_id'] ?? '');
            $publishedAt = (string) ($row['published_at'] ?? '');
            if ($activityId === '' || $objectId === '' || $actorId === '' || $publishedAt === '') {
                continue;
            }

            try {
                $published = Time::parse($publishedAt);
            } catch (\Throwable) {
                continue;
            }

            $entries[] = new TimelineEntry(
                id: $activityId,
                authorId: $actorId,
                objectId: $objectId,
                objectAuthorId: is_string($row['object_author_id'] ?? null) && $row['object_author_id'] !== ''
                    ? $row['object_author_id']
                    : null,
                content: (string) ($row['content'] ?? ''),
                contentWarning: is_string($row['content_warning'] ?? null) && $row['content_warning'] !== ''
                    ? $row['content_warning']
                    : null,
                publishedAt: $published,
                // A null visibility marks a remote entry for the media
                // presenter, which resolves its attachments through the
                // remote link table instead of the local one.
                visibility: null,
                boostedBy: ($row['kind'] ?? '') === RemotePostKind::Boost->value ? $actorId : null,
                inReplyToId: is_string($row['in_reply_to_id'] ?? null) && $row['in_reply_to_id'] !== ''
                    ? $row['in_reply_to_id']
                    : null,
            );
        }

        $posts = $this->media?->presentTimelineEntries($entries)
            ?? array_map(static fn (TimelineEntry $entry): array => $entry->toArray(), $entries);

        $counts = $this->countsFor($posts);

        // A boosted card is attributed to the original author, so the profile
        // cache is keyed by object_author_id when that differs from the actor
        // that performed the Announce.
        $authorIds = [];
        foreach ($posts as $post) {
            $authorId = (string) ($post['object_author_id'] ?? '');
            if ($authorId === '') {
                $authorId = (string) ($post['author_id'] ?? '');
            }
            if ($authorId !== '') {
                $authorIds[] = $authorId;
            }
        }
        $profiles = $this->actorProfiles?->profiles($authorIds) ?? [];

        foreach ($posts as $index => $post) {
            $postId = (string) ($post['id'] ?? '');
            $postObjectId = (string) ($post['object_id'] ?? '');
            $values = $counts[$postObjectId] ?? $counts[$postId] ?? null;

            $post['is_remote'] = true;
            // Remote entries are public or unlisted by the query above; the
            // reader sees the same public projection as on the origin
            // instance.
            $post['visibility'] = 'public';
            $displayAuthorId = (string) ($post['object_author_id'] ?? '');
            if ($displayAuthorId === '') {
                $displayAuthorId = (string) ($post['author_id'] ?? '');
            }
            $profile = $profiles[$displayAuthorId] ?? null;
            $post['author_handle'] = $profile['handle'] ?? $this->handleFor($displayAuthorId);
            $post['author_display_name'] = $profile['display_name'] ?? null;
            $post['author_avatar'] = $profile['avatar_url'] ?? null;
            $post['external_url'] = $postObjectId !== '' ? $postObjectId : null;

            if (isset($rows[$index])) {
                $row = $rows[$index];
                $quoted = $row['quoted_object_id'] ?? null;
                if (is_string($quoted) && $quoted !== '') {
                    $post['quoted_object_id'] = $quoted;
                }
            }

            if ($values !== null) {
                $post['likes_count'] = $values['likes'];
                $post['boosts_count'] = $values['boosts'];
                $post['replies_count'] = $values['replies'];
            }

            $posts[$index] = $post;
        }

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'posts' => array_values($posts),
            'next_before' => $hasMore && $last !== null ? (string) $last['published_at'] : null,
            'next_before_id' => $hasMore && $last !== null ? (string) $last['activity_id'] : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * A readable `@name@host` handle derived from the actor URL.
     *
     * The remote-post store keeps the canonical actor id only; the actor
     * document is deliberately not fetched for a feed page. Mastodon, Pleroma,
     * Misskey, GoToSocial and PeerTube all end their actor path in the
     * account name, so the last segment is a safe display fallback.
     */
    private function handleFor(string $actorId): ?string
    {
        $parts = parse_url($actorId);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            return null;
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        $segments = array_values(array_filter(
            explode('/', rtrim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));
        $name = $segments === [] ? '' : ltrim((string) end($segments), '@');

        if ($name === '' || strtolower($name) === $host) {
            return '@' . $host;
        }

        return '@' . $name . '@' . $host;
    }

    /**
     * @param list<array<string, mixed>> $posts
     *
     * @return array<string, array{likes: int, boosts: int, replies: int, fetched_at: string}>
     */
    private function countsFor(array $posts): array
    {
        $ids = [];
        foreach ($posts as $post) {
            foreach (['id', 'object_id'] as $field) {
                $value = $post[$field] ?? null;
                if (is_string($value) && $value !== '') {
                    $ids[$value] = true;
                }
            }
        }

        return $this->engagement->counts(array_keys($ids));
    }

    private function normalizeTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $tag = strtolower(ltrim(trim($tag), '#'));

        return $tag !== ''
            && mb_strlen($tag) <= self::MAX_TAG_LENGTH
            && preg_match('/^[\p{L}\p{N}_]+$/u', $tag) === 1
                ? $tag
                : null;
    }
}
