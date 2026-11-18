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

use function in_array;
use function max;
use function mb_strlen;
use function min;
use function trim;

/**
 * 24-hour stories.
 *
 * A story is deliberately separate from a post: it never enters a timeline,
 * is not federated and disappears without a cleanup job because every read
 * filters by `expires_at`. A background deletion is unnecessary.
 */
final class Stories
{
    public const BACKGROUNDS = ['ocean', 'sunset', 'forest', 'berry', 'night', 'sand'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Active stories grouped by author, newest first.
     *
     * @return array{
     *     stories: list<array<string, mixed>>,
     *     groups: list<array{account_id: string, handle: string, name: string, stories: list<array<string, mixed>>}>
     * }
     */
    public function active(?string $viewerId = null, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM story WHERE expires_at > :now';
        $parameters = ['now' => Time::format($this->clock->now())];

        if ($viewerId !== null && $viewerId !== '') {
            // A story is for the author and the people who follow them. A
            // public carousel that includes every local account leaks who is
            // posting and fills the bar with strangers.
            $sql .= ' AND (account_id = :viewer OR account_id IN ('
                . ' SELECT actor_id FROM social_following'
                . " WHERE local_user_id = :follow_viewer AND state = 'accepted'"
                . '))';
            $parameters['viewer'] = $viewerId;
            $parameters['follow_viewer'] = $viewerId;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
        $rows = $this->db->fetchAll($sql, $parameters);

        $stories = $this->withAuthors(array_map([$this, 'hydrate'], $rows));
        $groups = [];
        foreach ($stories as $story) {
            $accountId = (string) $story['account_id'];
            $groups[$accountId] ??= [
                'account_id' => $accountId,
                'handle' => (string) $story['author_handle'],
                'name' => (string) $story['author_name'],
                'stories' => [],
            ];
            $groups[$accountId]['stories'][] = $story;
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
            'SELECT * FROM story WHERE account_id = :account_id AND expires_at > :now ORDER BY created_at DESC',
            ['account_id' => $accountId, 'now' => Time::format($this->clock->now())],
        );

        return $this->withAuthors(array_map([$this, 'hydrate'], $rows));
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

        $now = $this->clock->now();
        $id = bin2hex(random_bytes(12));

        $this->db->execute(
            'INSERT INTO story (id, account_id, content, background, created_at, expires_at)'
            . ' VALUES (:id, :account_id, :content, :background, :created_at, :expires_at)',
            [
                'id' => $id,
                'account_id' => $accountId,
                'content' => $text,
                'background' => $tone,
                'created_at' => Time::format($now),
                'expires_at' => Time::format($now->modify('+24 hours')),
            ],
        );

        return $this->find($id) ?? throw CommunityException::notFound('The story was not saved.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM story WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function delete(string $id, string $accountId): void
    {
        $story = $this->find($id);
        if ($story === null) {
            throw CommunityException::notFound('This story no longer exists.');
        }
        if ((string) $story['account_id'] !== $accountId) {
            throw CommunityException::forbidden('You can only delete your own stories.');
        }

        $this->db->execute('DELETE FROM story WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'account_id' => (string) $row['account_id'],
            'content' => (string) $row['content'],
            'background' => (string) $row['background'],
            'created_at' => (string) $row['created_at'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }
}
