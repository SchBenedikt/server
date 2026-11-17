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
use DateTimeImmutable;
use Throwable;

use function array_fill;
use function array_map;
use function bin2hex;
use function count;
use function implode;
use function in_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function random_bytes;
use function trim;

/**
 * The Kokua board: practical help offered and requested by the community.
 *
 * Kokua is Hawaiian for help. A card is intentionally not a post: it is not
 * federated, has no likes and disappears from the open board once its author
 * marks it done. Other members can offer a hand with a short note; the author
 * decides when the exchange is complete. This keeps the feature about mutual
 * aid rather than reach or engagement.
 */
final class KokuaBoard
{
    public const KINDS = ['request', 'offer'];

    public const CATEGORIES = ['everyday', 'skills', 'company', 'goods', 'other'];

    public const STATES = ['open', 'done'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     limit: int,
     *     offset: int,
     *     counts: array{open_requests: int, open_offers: int, done: int}
     * }
     */
    public function page(
        ?string $viewerId,
        string $kind = 'all',
        string $category = 'all',
        string $state = 'open',
        int $limit = 30,
        int $offset = 0,
    ): array {
        $limit = max(1, min(50, $limit));
        $offset = max(0, min(10_000, $offset));

        $where = [];
        $parameters = [];

        if (in_array($kind, self::KINDS, true)) {
            $where[] = 'kind = :kind';
            $parameters['kind'] = $kind;
        }
        if (in_array($category, self::CATEGORIES, true)) {
            $where[] = 'category = :category';
            $parameters['category'] = $category;
        }
        if (in_array($state, self::STATES, true)) {
            $where[] = 'status = :status';
            $parameters['status'] = $state;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM kokua' . $whereSql,
            $parameters,
        );

        $rows = $this->db->fetchAll(
            'SELECT * FROM kokua' . $whereSql
            . ' ORDER BY CASE WHEN status = \'open\' THEN 0 ELSE 1 END,'
            . ' created_at DESC, id DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        );

        return [
            'items' => $this->presentMany($rows, $viewerId),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'counts' => $this->counts(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, ?string $viewerId = null): ?array
    {
        $row = $this->findRow($id);
        if ($row === null) {
            return null;
        }

        return $this->presentMany([$row], $viewerId)[0];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $accountId, array $input): array
    {
        $kind = $this->kind($input['kind'] ?? null);
        $title = $this->title($input['title'] ?? null);
        $details = $this->details($input['details'] ?? null);
        $category = $this->category($input['category'] ?? null);
        $place = $this->place($input['place'] ?? null);
        $neededBy = $this->neededBy($input['needed_by'] ?? null);

        $now = Time::format($this->clock->now());
        $id = bin2hex(random_bytes(12));

        $this->db->execute(
            'INSERT INTO kokua (id, account_id, kind, title, details, category, place, needed_by, status, created_at, updated_at, done_at)'
            . ' VALUES (:id, :account_id, :kind, :title, :details, :category, :place, :needed_by, \'open\', :now, :now, NULL)',
            [
                'id' => $id,
                'account_id' => $accountId,
                'kind' => $kind,
                'title' => $title,
                'details' => $details,
                'category' => $category,
                'place' => $place,
                'needed_by' => $neededBy,
                'now' => $now,
            ],
        );

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('The Kokua could not be saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(string $id, string $accountId, mixed $status): array
    {
        $current = $this->findRow($id);
        if ($current === null) {
            throw CommunityException::notFound('This Kokua no longer exists.');
        }
        $this->assertOwner($current, $accountId);

        $next = is_string($status) ? trim($status) : '';
        if (!in_array($next, self::STATES, true)) {
            throw CommunityException::invalid(
                'Choose whether the Kokua is open or done.',
                ['status' => 'Use open or done.'],
            );
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE kokua SET status = :status, done_at = :done_at, updated_at = :updated_at WHERE id = :id',
            [
                'status' => $next,
                'done_at' => $next === 'done' ? $now : null,
                'updated_at' => $now,
                'id' => $id,
            ],
        );

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('This Kokua no longer exists.');
    }

    public function delete(string $id, string $accountId): void
    {
        $current = $this->findRow($id);
        if ($current === null) {
            throw CommunityException::notFound('This Kokua no longer exists.');
        }
        $this->assertOwner($current, $accountId);

        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM kokua_help WHERE kokua_id = :id', ['id' => $id]);
            $this->db->execute('DELETE FROM kokua WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Offer a hand, or update the note of an existing offer. Offering twice is
     * idempotent, so a double click cannot inflate the helper count.
     *
     * @return array<string, mixed>
     */
    public function help(string $id, string $accountId, mixed $message = null): array
    {
        $current = $this->findRow($id);
        if ($current === null) {
            throw CommunityException::notFound('This Kokua no longer exists.');
        }
        if ((string) $current['account_id'] === $accountId) {
            throw CommunityException::invalid(
                'You cannot offer help on your own Kokua.',
                ['account_id' => 'Wait for someone else to respond.'],
            );
        }
        if ((string) $current['status'] !== 'open') {
            throw CommunityException::invalid(
                'This Kokua is already done.',
                ['status' => 'Offer help on an open Kokua.'],
            );
        }

        $note = $this->message($message);
        $existing = $this->db->fetchOne(
            'SELECT id FROM kokua_help WHERE kokua_id = :kokua_id AND account_id = :account_id',
            ['kokua_id' => $id, 'account_id' => $accountId],
        );

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO kokua_help (id, kokua_id, account_id, message, created_at)'
                . ' VALUES (:id, :kokua_id, :account_id, :message, :created_at)',
                [
                    'id' => bin2hex(random_bytes(12)),
                    'kokua_id' => $id,
                    'account_id' => $accountId,
                    'message' => $note,
                    'created_at' => Time::format($this->clock->now()),
                ],
            );
        } else {
            $this->db->execute(
                'UPDATE kokua_help SET message = :message WHERE id = :id',
                ['message' => $note, 'id' => (string) $existing['id']],
            );
        }

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('This Kokua no longer exists.');
    }

    /**
     * @return array<string, mixed>
     */
    public function withdrawHelp(string $id, string $accountId): array
    {
        $current = $this->findRow($id);
        if ($current === null) {
            throw CommunityException::notFound('This Kokua no longer exists.');
        }

        $this->db->execute(
            'DELETE FROM kokua_help WHERE kokua_id = :kokua_id AND account_id = :account_id',
            ['kokua_id' => $id, 'account_id' => $accountId],
        );

        return $this->find($id, $accountId) ?? throw CommunityException::notFound('This Kokua no longer exists.');
    }

    /**
     * @return array{open_requests: int, open_offers: int, done: int}
     */
    private function counts(): array
    {
        $row = $this->db->fetchOne(
            'SELECT'
            . ' SUM(CASE WHEN status = \'open\' AND kind = \'request\' THEN 1 ELSE 0 END) AS open_requests,'
            . ' SUM(CASE WHEN status = \'open\' AND kind = \'offer\' THEN 1 ELSE 0 END) AS open_offers,'
            . ' SUM(CASE WHEN status = \'done\' THEN 1 ELSE 0 END) AS done'
            . ' FROM kokua',
        );

        return [
            'open_requests' => (int) ($row['open_requests'] ?? 0),
            'open_offers' => (int) ($row['open_offers'] ?? 0),
            'done' => (int) ($row['done'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertOwner(array $row, string $accountId): void
    {
        if ((string) $row['account_id'] !== $accountId) {
            throw CommunityException::forbidden('Only the author can change this Kokua.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM kokua WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function presentMany(array $rows, ?string $viewerId): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        $helpers = $this->helpersFor($ids);

        return array_map(
            fn (array $row): array => $this->present($row, $helpers[(string) $row['id']] ?? [], $viewerId),
            $rows,
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, list<array{account_id: string, message: string, created_at: string}>>
     */
    private function helpersFor(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            'SELECT kokua_id, account_id, message, created_at FROM kokua_help'
            . ' WHERE kokua_id IN (' . $placeholders . ')'
            . ' ORDER BY created_at ASC, id ASC',
            $ids,
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['kokua_id']][] = [
                'account_id' => (string) $row['account_id'],
                'message' => (string) $row['message'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{account_id: string, message: string, created_at: string}> $helpers
     *
     * @return array<string, mixed>
     */
    private function present(array $row, array $helpers, ?string $viewerId): array
    {
        $viewerHelping = false;
        if ($viewerId !== null) {
            foreach ($helpers as $helper) {
                if ($helper['account_id'] === $viewerId) {
                    $viewerHelping = true;
                    break;
                }
            }
        }

        return [
            'id' => (string) $row['id'],
            'account_id' => (string) $row['account_id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'details' => (string) $row['details'],
            'category' => (string) $row['category'],
            'place' => (string) $row['place'],
            'needed_by' => $row['needed_by'] === null ? null : (string) $row['needed_by'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'done_at' => $row['done_at'] === null ? null : (string) $row['done_at'],
            'helpers' => $helpers,
            'help_count' => count($helpers),
            'viewer_helping' => $viewerHelping,
            'is_owner' => $viewerId !== null && (string) $row['account_id'] === $viewerId,
        ];
    }

    private function kind(mixed $value): string
    {
        $kind = is_string($value) ? trim($value) : '';
        if (!in_array($kind, self::KINDS, true)) {
            throw CommunityException::invalid(
                'Choose whether you ask for or offer help.',
                ['kind' => 'Use request or offer.'],
            );
        }

        return $kind;
    }

    private function title(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';
        if ($title === '') {
            throw CommunityException::invalid(
                'A Kokua needs a short title.',
                ['title' => 'Please enter a title.'],
            );
        }
        if (mb_strlen($title) > 100) {
            throw CommunityException::invalid(
                'The title is too long.',
                ['title' => 'At most 100 characters.'],
            );
        }

        return $title;
    }

    private function details(mixed $value): string
    {
        $details = is_string($value) ? trim($value) : '';
        if (mb_strlen($details) > 1000) {
            throw CommunityException::invalid(
                'The details are too long.',
                ['details' => 'At most 1000 characters.'],
            );
        }

        return $details;
    }

    private function category(mixed $value): string
    {
        $category = is_string($value) ? trim($value) : '';
        if (!in_array($category, self::CATEGORIES, true)) {
            throw CommunityException::invalid(
                'Choose a category.',
                ['category' => 'Choose everyday, skills, company, goods or other.'],
            );
        }

        return $category;
    }

    private function place(mixed $value): string
    {
        $place = is_string($value) ? trim($value) : '';
        if (mb_strlen($place) > 80) {
            throw CommunityException::invalid(
                'The place is too long.',
                ['place' => 'At most 80 characters.'],
            );
        }

        return $place;
    }

    private function neededBy(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw CommunityException::invalid(
                'The date is invalid.',
                ['needed_by' => 'Choose a date.'],
            );
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw CommunityException::invalid(
                'The date is invalid.',
                ['needed_by' => 'Choose a valid date.'],
            );
        }

        $today = $this->clock->now()->format('Y-m-d');
        $normalized = $date->format('Y-m-d');
        if ($normalized < $today) {
            throw CommunityException::invalid(
                'The date is in the past.',
                ['needed_by' => 'Choose today or a later date.'],
            );
        }

        return $normalized;
    }

    private function message(mixed $value): string
    {
        $message = is_string($value) ? trim($value) : '';
        if (mb_strlen($message) > 300) {
            throw CommunityException::invalid(
                'The note is too long.',
                ['message' => 'At most 300 characters.'],
            );
        }

        return $message;
    }
}
