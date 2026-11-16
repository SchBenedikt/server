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

use function max;
use function min;
use function mb_strlen;
use function trim;

/**
 * Instance-wide announcements written by administrators.
 *
 * An announcement is intentionally not a post: it has no author timeline, no
 * federation delivery and no replies. It is a short operator notice shown
 * above the feed until it expires or is deactivated.
 */
final class Announcements
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function active(int $limit = 5): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM announcement'
            . ' WHERE active = 1 AND (expires_at IS NULL OR expires_at > :now)'
            . ' ORDER BY created_at DESC LIMIT ' . max(1, min(20, $limit)),
            ['now' => Time::format($this->clock->now())],
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM announcement ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit)),
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $authorId, mixed $content, mixed $expiresAt = null): array
    {
        $text = $this->content($content);
        $expiry = $this->expiry($expiresAt);
        $now = Time::format($this->clock->now());
        $id = bin2hex(random_bytes(12));

        $this->db->execute(
            'INSERT INTO announcement (id, author_id, content, active, created_at, updated_at, expires_at)'
            . ' VALUES (:id, :author_id, :content, 1, :now, :now, :expires_at)',
            [
                'id' => $id,
                'author_id' => $authorId,
                'content' => $text,
                'now' => $now,
                'expires_at' => $expiry,
            ],
        );

        return $this->find($id) ?? throw CommunityException::notFound('Die Ankündigung wurde nicht gespeichert.');
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $id, mixed $content, mixed $active = null, mixed $expiresAt = null): array
    {
        $current = $this->find($id);
        if ($current === null) {
            throw CommunityException::notFound('Diese Ankündigung gibt es nicht mehr.');
        }

        $text = $content === null ? $current['content'] : $this->content($content);
        $isActive = $active === null ? (bool) $current['active'] : $this->boolean($active);
        $expiry = $expiresAt === null ? $current['expires_at'] : $this->expiry($expiresAt);

        $this->db->execute(
            'UPDATE announcement SET content = :content, active = :active, expires_at = :expires_at, updated_at = :updated_at'
            . ' WHERE id = :id',
            [
                'content' => $text,
                'active' => $isActive ? 1 : 0,
                'expires_at' => $expiry,
                'updated_at' => Time::format($this->clock->now()),
                'id' => $id,
            ],
        );

        return $this->find($id) ?? throw CommunityException::notFound('Diese Ankündigung gibt es nicht mehr.');
    }

    public function delete(string $id): void
    {
        $this->db->execute('DELETE FROM announcement WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM announcement WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    private function content(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            throw CommunityException::invalid(
                'Eine Ankündigung braucht einen Text.',
                ['content' => 'Bitte gib einen Text ein.'],
            );
        }
        if (mb_strlen($text) > 500) {
            throw CommunityException::invalid(
                'Die Ankündigung ist zu lang.',
                ['content' => 'Höchstens 500 Zeichen.'],
            );
        }

        return $text;
    }

    private function expiry(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw CommunityException::invalid('Das Ablaufdatum ist ungültig.', ['expires_at' => 'Bitte ein Datum angeben.']);
        }

        try {
            $date = Time::parse($value);
        } catch (\Throwable) {
            throw CommunityException::invalid('Das Ablaufdatum ist ungültig.', ['expires_at' => 'Bitte ein gültiges Datum angeben.']);
        }

        if ($date <= $this->clock->now()) {
            throw CommunityException::invalid('Das Ablaufdatum liegt in der Vergangenheit.', ['expires_at' => 'Wähle einen Zeitpunkt in der Zukunft.']);
        }

        return Time::format($date);
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $expires = $row['expires_at'] ?? null;

        return [
            'id' => (string) $row['id'],
            'author_id' => (string) $row['author_id'],
            'content' => (string) $row['content'],
            'active' => (int) $row['active'] === 1,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'expires_at' => $expires === null ? null : (string) $expires,
            'expired' => $expires !== null && Time::parse((string) $expires) <= $this->clock->now(),
        ];
    }
}
