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

use function array_map;
use function is_bool;
use function is_string;
use function mb_strlen;
use function preg_match;
use function strtolower;
use function trim;

/**
 * Host-owned public pages such as rules, terms and privacy notices.
 *
 * Pages are plain text rendered as paragraphs — never HTML — so an
 * administrator cannot inject markup into a public surface. A page only
 * exists publicly once it is published; drafts stay invisible to readers.
 * Nothing is seeded: an instance without configured pages simply has none,
 * which the UI reports as an honest absence rather than fabricated legal
 * text.
 */
final class InstancePages
{
    private const MAX_TITLE = 200;

    private const MAX_BODY = 50000;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Published pages for public navigation: slug, title and freshness only.
     *
     * @return list<array{slug: string, title: string, updated_at: string}>
     */
    public function published(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT slug, title, updated_at FROM instance_page WHERE published = 1 ORDER BY slug',
        );

        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'updated_at' => (string) $row['updated_at'],
        ], $rows);
    }

    /**
     * One published page, or null when it does not exist or is a draft.
     *
     * @return array{slug: string, title: string, body: string, updated_at: string}|null
     */
    public function find(string $slug): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT slug, title, body, updated_at FROM instance_page'
            . ' WHERE slug = :slug AND published = 1',
            ['slug' => $this->slug($slug)],
        );
        if ($row === null) {
            return null;
        }

        return [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Every page including drafts, for the administration.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT slug, title, body, published, created_at, updated_at, updated_by'
            . ' FROM instance_page ORDER BY slug',
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Creates or replaces a page. The body is stored verbatim as plain text.
     *
     * @return array<string, mixed>
     */
    public function save(string $adminId, string $slug, mixed $title, mixed $body, mixed $published): array
    {
        $slug = $this->slug($slug);
        $title = $this->title($title);
        $text = $this->body($body);
        $isPublished = is_bool($published) ? $published : $published === 1 || $published === '1' || $published === 'true';
        $now = Time::format($this->clock->now());

        $this->db->execute(
            'INSERT INTO instance_page (slug, title, body, published, created_at, updated_at, updated_by)'
            . ' VALUES (:slug, :title, :body, :published, :created, :updated, :admin)'
            . ' ON CONFLICT (slug) DO UPDATE SET'
            . ' title = excluded.title, body = excluded.body, published = excluded.published,'
            . ' updated_at = excluded.updated_at, updated_by = excluded.updated_by',
            [
                'slug' => $slug,
                'title' => $title,
                'body' => $text,
                'published' => $isPublished ? 1 : 0,
                'created' => $now,
                'updated' => $now,
                'admin' => $adminId,
            ],
        );

        return $this->adminFind($slug) ?? throw CommunityException::notFound('The page was not saved.');
    }

    public function delete(string $slug): void
    {
        $this->db->execute('DELETE FROM instance_page WHERE slug = :slug', ['slug' => $this->slug($slug)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function adminFind(string $slug): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM instance_page WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    private function slug(mixed $value): string
    {
        $slug = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug) !== 1) {
            throw CommunityException::invalid(
                'This page address is invalid.',
                ['slug' => 'Use lowercase letters, numbers and dashes.'],
            );
        }

        return $slug;
    }

    private function title(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            throw CommunityException::invalid(
                'The page needs a title.',
                ['title' => 'At most ' . self::MAX_TITLE . ' characters.'],
            );
        }

        return $title;
    }

    private function body(mixed $value): string
    {
        $body = is_string($value) ? trim($value) : '';
        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            throw CommunityException::invalid(
                'The page needs text.',
                ['body' => 'At most ' . self::MAX_BODY . ' characters.'],
            );
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'published' => (int) $row['published'] === 1,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'updated_by' => (string) $row['updated_by'],
        ];
    }
}
