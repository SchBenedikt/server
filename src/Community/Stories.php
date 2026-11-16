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
     *     groups: list<array{account_id: string, stories: list<array<string, mixed>>}>
     * }
     */
    public function active(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->fetchAll(
            'SELECT * FROM story WHERE expires_at > :now ORDER BY created_at DESC LIMIT ' . $limit,
            ['now' => Time::format($this->clock->now())],
        );

        $stories = array_map([$this, 'hydrate'], $rows);
        $groups = [];
        foreach ($stories as $story) {
            $accountId = (string) $story['account_id'];
            $groups[$accountId] ??= ['account_id' => $accountId, 'stories' => []];
            $groups[$accountId]['stories'][] = $story;
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

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $accountId, mixed $content, mixed $background = null): array
    {
        $text = is_string($content) ? trim($content) : '';
        if ($text === '') {
            throw CommunityException::invalid(
                'Eine Story braucht einen Text.',
                ['content' => 'Bitte gib einen Text ein.'],
            );
        }
        if (mb_strlen($text) > 500) {
            throw CommunityException::invalid(
                'Die Story ist zu lang.',
                ['content' => 'Höchstens 500 Zeichen.'],
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

        return $this->find($id) ?? throw CommunityException::notFound('Die Story wurde nicht gespeichert.');
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
            throw CommunityException::notFound('Diese Story gibt es nicht mehr.');
        }
        if ((string) $story['account_id'] !== $accountId) {
            throw CommunityException::forbidden('Du kannst nur deine eigenen Stories löschen.');
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
