<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Story\PdoStoryRepository;
use AlohaSocial\Core\Domain\Story\Story;
use AlohaSocial\Core\Domain\Story\StoryRepository;
use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

use function array_merge;
use function array_slice;
use function in_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function parse_str;
use function parse_url;
use function rawurlencode;
use function trim;

/**
 * 24-hour host text stories, stored as core stories.
 *
 * The core story service requires an uploaded image/video and federates every
 * story. Host text stories are a different surface: they are text on a colour
 * background, local-only, and may exist without a media upload. This adapter
 * therefore uses the core story entity and repository, stores the text in the
 * core caption, and gives each story a generated background URL as its media
 * reference. That keeps one story table and one view table, while preserving
 * the host text/border UX. The core's media-story routes can read the same row
 * without a second storage path.
 */
final class Stories
{
    public const BACKGROUNDS = ['ocean', 'sunset', 'forest', 'berry', 'night', 'sand'];

    private const OBJECT_PREFIX = 'host-story:';

    private const BACKGROUND_COLOURS = [
        'ocean' => '#0b3b5c',
        'sunset' => '#d35b3f',
        'forest' => '#24543f',
        'berry' => '#5a2a55',
        'night' => '#171a2b',
        'sand' => '#a9794d',
    ];

    private readonly SocialGraph $graph;

    private readonly StoryRepository $repository;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        ?SocialGraph $graph = null,
        ?StoryRepository $repository = null,
    ) {
        $this->graph = $graph ?? new SocialGraph($db);
        $this->repository = $repository ?? new PdoStoryRepository($db);
    }

    /**
     * Active stories grouped by author, newest first.
     *
     * @return array{
     *     stories: list<array<string, mixed>>,
     *     groups: list<array{account_id: string, handle: string, name: string, unseen: bool, stories: list<array<string, mixed>>}>
     * }
     */
    public function active(?string $viewerId = null, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, owner_id, caption, remote_media_url, created_at, expires_at FROM ' . StorySchema::TABLE
            . ' WHERE is_local = 1 AND deleted_at IS NULL AND expires_at > :now'
            . ' AND object_id LIKE :object_prefix';
        $parameters = [
            'now' => Time::format($this->clock->now()),
            'object_prefix' => self::OBJECT_PREFIX . '%',
        ];

        if ($viewerId !== null && $viewerId !== '') {
            // A story is for the author and the people who follow them. Local
            // follows are follower edges (`social_follower`), not remote
            // `social_following` rows, and their target is a local handle
            // rather than an actor URL. Remote stories are not part of this
            // local text format.
            $following = $this->graph->localFollowingSubquery($viewerId, 'actor_id', 'story_follow');
            $sql .= ' AND (owner_id = :viewer OR owner_id IN (' . $following['sql'] . '))';
            $parameters['viewer'] = $viewerId;
            $parameters = array_merge($parameters, $following['params']);
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $rows = $this->db->fetchAll($sql, $parameters);

        $stories = $this->withAuthors(array_map(fn (array $row): array => $this->hydrateRow($row), $rows));
        if ($viewerId !== null && $viewerId !== '') {
            $stories = $this->withSeen($stories, $viewerId);
        }

        $groups = [];
        foreach ($stories as $story) {
            $accountId = (string) $story['account_id'];
            $groups[$accountId] ??= [
                'account_id' => $accountId,
                'handle' => (string) $story['author_handle'],
                'name' => (string) $story['author_name'],
                'unseen' => false,
                'stories' => [],
            ];
            $groups[$accountId]['stories'][] = $story;
            if (($story['seen'] ?? false) === false) {
                $groups[$accountId]['unseen'] = true;
            }
        }

        // The reader's own story is the first ring, like every story UI. The
        // remaining groups stay newest-first.
        if ($viewerId !== null && isset($groups[$viewerId])) {
            $own = $groups[$viewerId];
            unset($groups[$viewerId]);
            $groups = [$viewerId => $own] + $groups;
        }

        return ['stories' => $stories, 'groups' => array_values($groups)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, owner_id, caption, remote_media_url, created_at, expires_at FROM ' . StorySchema::TABLE
            . ' WHERE owner_id = :account_id AND is_local = 1 AND deleted_at IS NULL'
            . ' AND expires_at > :now AND object_id LIKE :object_prefix ORDER BY created_at DESC, id DESC',
            [
                'account_id' => $accountId,
                'now' => Time::format($this->clock->now()),
                'object_prefix' => self::OBJECT_PREFIX . '%',
            ],
        );

        return $this->withAuthors(array_map(fn (array $row): array => $this->hydrateRow($row), $rows));
    }

    /**
     * Stories store the account id, but a client renders a handle. Resolving it
     * here keeps the story bar from comparing an id with a handle, which used
     * to hide the reader's own ring and its delete action.
     *
     * @param list<array<string, mixed>> $stories
     *
     * @return list<array<string, mixed>>
     */
    private function withAuthors(array $stories): array
    {
        if ($stories === []) {
            return $stories;
        }

        $ids = [];
        foreach ($stories as $story) {
            $id = (string) $story['account_id'];
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $placeholders = [];
        $parameters = [];
        $index = 0;
        foreach (array_keys($ids) as $id) {
            $key = 'author_' . $index++;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $id;
        }

        $accounts = [];
        if ($placeholders !== []) {
            $rows = $this->db->fetchAll(
                'SELECT id, handle, display_name FROM account WHERE id IN (' . implode(', ', $placeholders) . ')',
                $parameters,
            );
            foreach ($rows as $row) {
                $accounts[(string) $row['id']] = $row;
            }
        }

        foreach ($stories as $position => $story) {
            $account = $accounts[(string) $story['account_id']] ?? null;
            $stories[$position]['author_handle'] = $account === null
                ? (string) $story['account_id']
                : (string) $account['handle'];
            $stories[$position]['author_name'] = $account === null
                ? ''
                : (string) ($account['display_name'] ?? '');
        }

        return $stories;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $accountId, mixed $content, mixed $background = null): array
    {
        $text = is_string($content) ? trim($content) : '';
        if ($text === '') {
            throw CommunityException::invalid(
                'A story needs text.',
                ['content' => 'Please enter text.'],
            );
        }
        if (mb_strlen($text) > 500) {
            throw CommunityException::invalid(
                'The story is too long.',
                ['content' => 'At most 500 characters.'],
            );
        }

        $tone = is_string($background) ? trim($background) : '';
        if (!in_array($tone, self::BACKGROUNDS, true)) {
            $tone = self::BACKGROUNDS[0];
        }

        $now = Time::toSecondPrecision($this->clock->now());
        $id = bin2hex(random_bytes(12));
        $story = new Story(
            $id,
            $accountId,
            true,
            self::OBJECT_PREFIX . $id,
            $text,
            $now,
            $now->modify('+24 hours'),
            null,
            $this->backgroundUrl($id, $tone),
        );
        $this->repository->insert($story);

        return $this->find($id) ?? throw CommunityException::notFound('The story was not saved.');
    }

    /**
     * A raw row read keeps old rows readable even when their expiry predates
     * their creation (the legacy host table had no such invariant). The core
     * entity is still used for writes; this adapter is the compatibility edge.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->findRow($id);
        if ($row === null || $row['deleted_at'] !== null
            || !str_starts_with((string) $row['object_id'], self::OBJECT_PREFIX)
        ) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    public function delete(string $id, string $accountId): void
    {
        $row = $this->findRow($id);
        if ($row === null || $row['deleted_at'] !== null
            || !str_starts_with((string) $row['object_id'], self::OBJECT_PREFIX)
        ) {
            throw CommunityException::notFound('This story no longer exists.');
        }
        if ((string) $row['owner_id'] !== $accountId) {
            throw CommunityException::forbidden('You can only delete your own stories.');
        }

        $now = Time::toSecondPrecision($this->clock->now());
        $this->db->execute(
            'UPDATE ' . StorySchema::TABLE . ' SET deleted_at = :deleted_at'
            . ' WHERE id = :id AND deleted_at IS NULL',
            ['deleted_at' => Time::format($now), 'id' => $id],
        );
        $this->repository->markTombstone((string) $row['object_id'], $accountId, $now);
    }

    /**
     * Marks stories as watched for one account.
     *
     * Only a story the account may actually see is stored, so a client cannot
     * fill the table with arbitrary ids. Repeating the call is a no-op and
     * reports how many markers were new.
     *
     * @param list<mixed> $storyIds
     */
    public function markSeen(string $accountId, array $storyIds): int
    {
        $ids = [];
        foreach ($storyIds as $id) {
            if (is_string($id) && $id !== '' && mb_strlen($id) <= 64) {
                $ids[$id] = true;
            }
        }

        $ids = array_slice(array_keys($ids), 0, 100);
        if ($ids === []) {
            return 0;
        }

        $visible = [];
        foreach ($ids as $id) {
            $row = $this->findRow($id);
            if ($row === null || $row['deleted_at'] !== null
                || !str_starts_with((string) $row['object_id'], self::OBJECT_PREFIX)
                || (string) $row['expires_at'] <= Time::format($this->clock->now())
            ) {
                continue;
            }
            if ($this->canView($accountId, (string) $row['owner_id'])) {
                $visible[] = $row;
            }
        }
        if ($visible === []) {
            return 0;
        }

        $placeholders = [];
        $parameters = ['viewer' => $accountId];
        foreach ($visible as $index => $story) {
            $key = 'story_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = (string) $story['id'];
        }

        $rows = $this->db->fetchAll(
            'SELECT story_id FROM ' . StorySchema::VIEW_TABLE . ' WHERE viewer_id = :viewer'
            . ' AND story_id IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        );
        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) $row['story_id']] = true;
        }

        $marked = 0;
        foreach ($visible as $story) {
            if (isset($seen[(string) $story['id']])) {
                continue;
            }
            $this->repository->recordView(
                (string) $story['id'],
                $accountId,
                Time::toSecondPrecision($this->clock->now()),
            );
            ++$marked;
        }

        return $marked;
    }

    /**
     * @param list<array<string, mixed>> $stories
     *
     * @return list<array<string, mixed>>
     */
    private function withSeen(array $stories, string $viewerId): array
    {
        if ($stories === []) {
            return $stories;
        }

        $placeholders = [];
        $parameters = ['viewer' => $viewerId];
        foreach ($stories as $index => $story) {
            $key = 'seen_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = (string) $story['id'];
        }

        $rows = $this->db->fetchAll(
            'SELECT story_id FROM ' . StorySchema::VIEW_TABLE . ' WHERE viewer_id = :viewer'
            . ' AND story_id IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        );

        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) $row['story_id']] = true;
        }

        foreach ($stories as $position => $story) {
            $stories[$position]['seen'] = isset($seen[(string) $story['id']]);
        }

        return $stories;
    }

    private function canView(string $viewerId, string $ownerId): bool
    {
        if ($ownerId === $viewerId) {
            return true;
        }

        return in_array($ownerId, $this->graph->localFolloweeIds($viewerId), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, owner_id, caption, remote_media_url, created_at, expires_at, object_id, deleted_at'
            . ' FROM ' . StorySchema::TABLE . ' WHERE id = :id',
            ['id' => $id],
        );
    }

    private function backgroundUrl(string $id, string $tone): string
    {
        return '/api/v1/host/stories/' . rawurlencode($id) . '/background?background=' . rawurlencode($tone);
    }

    /**
     * A generated SVG keeps the text story's colour background available to
     * core clients without storing a second background row.
     */
    public function backgroundSvg(string $tone): string
    {
        if (!in_array($tone, self::BACKGROUNDS, true)) {
            $tone = self::BACKGROUNDS[0];
        }
        $colour = self::BACKGROUND_COLOURS[$tone] ?? self::BACKGROUND_COLOURS['ocean'];

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1920" viewBox="0 0 1080 1920">'
            . '<rect width="1080" height="1920" fill="' . $colour . '"/>'
            . '<circle cx="860" cy="360" r="260" fill="#ffffff" fill-opacity="0.08"/>'
            . '<circle cx="180" cy="1560" r="340" fill="#ffffff" fill-opacity="0.06"/>'
            . '</svg>';
    }

    private function backgroundFromUrl(?string $url): string
    {
        if ($url === null) {
            return self::BACKGROUNDS[0];
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return self::BACKGROUNDS[0];
        }

        parse_str($query, $parameters);
        $tone = $parameters['background'] ?? null;

        return is_string($tone) && in_array($tone, self::BACKGROUNDS, true)
            ? $tone
            : self::BACKGROUNDS[0];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'account_id' => (string) $row['owner_id'],
            'content' => (string) $row['caption'],
            'background' => $this->backgroundFromUrl(
                is_string($row['remote_media_url'] ?? null) ? $row['remote_media_url'] : null,
            ),
            'created_at' => (string) $row['created_at'],
            'expires_at' => (string) $row['expires_at'],
            'seen' => false,
        ];
    }
}
