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
use AlohaSocial\Server\Accounts\AccountRepository;
use AlohaSocial\Server\Federation\RemoteDirectory;
use AlohaSocial\Server\Federation\RemoteDirectoryException;

use function array_map;
use function bin2hex;
use function count;
use function is_string;
use function max;
use function mb_strlen;
use function random_bytes;
use function trim;

/**
 * Starter packs: named sets of accounts a reader can follow in one step.
 *
 * The pack is a host-local directory, not a second social graph. It stores the
 * canonical identity of every member (local account id, ActivityPub actor URL
 * or AT Protocol DID) and enough display data to render the list. Following a
 * pack goes through the normal follow services, so a pack never changes
 * visibility, locking or block rules.
 */
final class StarterPacks
{
    public const MAX_MEMBERS = 150;

    private const MAX_TITLE = 80;

    private const MAX_DESCRIPTION = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AccountRepository $accounts,
        private readonly RemoteDirectory $directory,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAccount(string $accountId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM social_starter_pack_member m WHERE m.pack_id = p.id) AS member_count'
            . ' FROM social_starter_pack p WHERE p.owner_id = :owner'
            . ' ORDER BY p.updated_at DESC, p.created_at DESC, p.id DESC LIMIT 100',
            ['owner' => $accountId],
        );

        return array_map(fn (array $row): array => $this->hydrate($row, false), $rows);
    }

    /**
     * Every pack is readable: a starter pack is meant to be shared. The
     * newest packs come first and the caller's own packs stay in `forAccount`.
     *
     * @return list<array<string, mixed>>
     */
    public function discover(string $viewerId, int $limit = 40): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM social_starter_pack_member m WHERE m.pack_id = p.id) AS member_count'
            . ' FROM social_starter_pack p WHERE p.owner_id <> :viewer'
            . ' ORDER BY p.updated_at DESC, p.created_at DESC, p.id DESC LIMIT ' . max(1, $limit),
            ['viewer' => $viewerId],
        );

        return array_map(fn (array $row): array => $this->hydrate($row, false), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM social_starter_pack WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }

        $pack = $this->hydrate($row, true);
        $pack['member_count'] = count($pack['members']);

        return $pack;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $accountId, mixed $title, mixed $description = ''): array
    {
        $name = $this->title($title);
        $text = $this->description($description);
        $now = Time::format($this->clock->now());
        $id = bin2hex(random_bytes(12));

        $this->db->execute(
            'INSERT INTO social_starter_pack (id, owner_id, title, description, created_at, updated_at)'
            . ' VALUES (:id, :owner, :title, :description, :now, :now)',
            [
                'id' => $id,
                'owner' => $accountId,
                'title' => $name,
                'description' => $text,
                'now' => $now,
            ],
        );

        return $this->find($id) ?? throw CommunityException::notFound('The starter pack was not saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $id, string $accountId, mixed $title = null, mixed $description = null): array
    {
        $pack = $this->ownPack($id, $accountId);

        $this->db->execute(
            'UPDATE social_starter_pack SET title = :title, description = :description, updated_at = :now'
            . ' WHERE id = :id',
            [
                'title' => $title === null ? $pack['title'] : $this->title($title),
                'description' => $description === null ? $pack['description'] : $this->description($description),
                'now' => Time::format($this->clock->now()),
                'id' => $id,
            ],
        );

        return $this->find($id) ?? throw CommunityException::notFound('This starter pack no longer exists.');
    }

    public function delete(string $id, string $accountId): void
    {
        $this->ownPack($id, $accountId);

        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM social_starter_pack_member WHERE pack_id = :id', ['id' => $id]);
            $this->db->execute('DELETE FROM social_starter_pack WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Adds a local account, a fediverse actor or a Bluesky identity.
     *
     * The query may be a local handle, `@name@server`, an actor URL or a DID /
     * Bluesky handle. The resolved identity is stored once; adding the same
     * person twice is a no-op instead of a duplicate row.
     *
     * @return array<string, mixed>
     */
    public function addMember(string $id, string $accountId, mixed $query): array
    {
        $pack = $this->ownPack($id, $accountId);
        $query = is_string($query) ? trim($query) : '';
        if ($query === '') {
            throw CommunityException::invalid('Choose an account to add.', ['member' => 'An account is required.']);
        }
        if ((int) ($pack['member_count'] ?? 0) >= self::MAX_MEMBERS) {
            throw CommunityException::invalid('A starter pack can hold at most ' . self::MAX_MEMBERS . ' accounts.');
        }

        $member = $this->resolve($query);
        $existing = $this->db->fetchValue(
            'SELECT id FROM social_starter_pack_member WHERE pack_id = :pack AND member_id = :member AND network = :network',
            ['pack' => $id, 'member' => $member['member_id'], 'network' => $member['network']],
        );
        if (is_string($existing) && $existing !== '') {
            return $this->find($id) ?? throw CommunityException::notFound('This starter pack no longer exists.');
        }

        $position = (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM social_starter_pack_member WHERE pack_id = :pack',
            ['pack' => $id],
        );
        $now = Time::format($this->clock->now());

        $this->db->execute(
            'INSERT INTO social_starter_pack_member'
            . ' (id, pack_id, member_id, network, handle, display_name, avatar_url, inbox_url, position, created_at)'
            . ' VALUES (:id, :pack, :member, :network, :handle, :display, :avatar, :inbox, :position, :now)',
            [
                'id' => bin2hex(random_bytes(12)),
                'pack' => $id,
                'member' => $member['member_id'],
                'network' => $member['network'],
                'handle' => $member['handle'],
                'display' => $member['display_name'],
                'avatar' => $member['avatar_url'],
                'inbox' => $member['inbox_url'],
                'position' => $position,
                'now' => $now,
            ],
        );
        $this->db->execute(
            'UPDATE social_starter_pack SET updated_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $id],
        );

        return $this->find($id) ?? throw CommunityException::notFound('This starter pack no longer exists.');
    }

    /**
     * @return array<string, mixed>
     */
    public function removeMember(string $id, string $accountId, string $memberRowId): array
    {
        $this->ownPack($id, $accountId);

        $this->db->execute(
            'DELETE FROM social_starter_pack_member WHERE id = :member AND pack_id = :pack',
            ['member' => $memberRowId, 'pack' => $id],
        );
        $this->db->execute(
            'UPDATE social_starter_pack SET updated_at = :now WHERE id = :id',
            ['now' => Time::format($this->clock->now()), 'id' => $id],
        );

        return $this->find($id) ?? throw CommunityException::notFound('This starter pack no longer exists.');
    }

    /**
     * @return array<string, mixed>
     */
    private function ownPack(string $id, string $accountId): array
    {
        $pack = $this->find($id);
        if ($pack === null) {
            throw CommunityException::notFound('This starter pack does not exist.');
        }
        if ((string) $pack['owner_id'] !== $accountId) {
            throw CommunityException::forbidden('Only the owner can change this starter pack.');
        }

        return $pack;
    }

    /**
     * @return array{member_id: string, network: string, handle: string, display_name: ?string, avatar_url: ?string, inbox_url: ?string}
     */
    private function resolve(string $query): array
    {
        $handle = ltrim($query, '@');
        $local = $this->accounts->find($query) ?? $this->accounts->findByHandle($handle);
        if ($local !== null) {
            return [
                'member_id' => $local->id,
                'network' => 'local',
                'handle' => $local->handle,
                'display_name' => $local->displayName,
                'avatar_url' => null,
                'inbox_url' => null,
            ];
        }

        try {
            $lookup = $this->directory->lookup($query);
        } catch (RemoteDirectoryException $exception) {
            throw CommunityException::invalid($exception->getMessage(), ['member' => $exception->getMessage()]);
        }

        $profile = is_array($lookup['profile'] ?? null) ? $lookup['profile'] : [];
        $network = ($lookup['kind'] ?? '') === 'bluesky' ? 'bluesky' : 'activitypub';
        $memberId = $network === 'bluesky'
            ? $this->string($profile['did'] ?? null) ?? $this->string($profile['id'] ?? null)
            : $this->string($profile['id'] ?? null);
        if ($memberId === null || $memberId === '') {
            throw CommunityException::invalid('The account could not be resolved.', ['member' => 'Try a full handle.']);
        }

        return [
            'member_id' => $memberId,
            'network' => $network,
            'handle' => $this->string($profile['handle'] ?? null) ?? $memberId,
            'display_name' => $this->string($profile['display_name'] ?? null),
            'avatar_url' => $this->string($profile['avatar'] ?? null),
            'inbox_url' => $network === 'activitypub' ? $this->string($profile['inbox'] ?? null) : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row, bool $withMembers): array
    {
        $ownerId = (string) ($row['owner_id'] ?? '');
        $owner = $ownerId !== '' ? $this->accounts->find($ownerId) : null;
        $pack = [
            'id' => (string) ($row['id'] ?? ''),
            'owner_id' => $ownerId,
            'owner_handle' => $owner?->handle ?? $ownerId,
            'owner_name' => $owner?->name() ?? $ownerId,
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'member_count' => (int) ($row['member_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];

        if (!$withMembers) {
            $pack['members'] = [];

            return $pack;
        }

        $rows = $this->db->fetchAll(
            'SELECT id, member_id, network, handle, display_name, avatar_url, inbox_url, position, created_at'
            . ' FROM social_starter_pack_member WHERE pack_id = :pack ORDER BY position, created_at, id',
            ['pack' => $pack['id']],
        );
        $pack['members'] = array_map(static fn (array $member): array => [
            'id' => (string) ($member['id'] ?? ''),
            'member_id' => (string) ($member['member_id'] ?? ''),
            'network' => (string) ($member['network'] ?? ''),
            'handle' => (string) ($member['handle'] ?? ''),
            'display_name' => is_string($member['display_name'] ?? null) ? $member['display_name'] : null,
            'avatar_url' => is_string($member['avatar_url'] ?? null) ? $member['avatar_url'] : null,
            'inbox_url' => is_string($member['inbox_url'] ?? null) ? $member['inbox_url'] : null,
            'position' => (int) ($member['position'] ?? 0),
        ], $rows);

        return $pack;
    }

    private function title(mixed $value): string
    {
        $title = is_string($value) ? trim($value) : '';
        if ($title === '') {
            throw CommunityException::invalid('A starter pack needs a title.', ['title' => 'Please enter a title.']);
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw CommunityException::invalid('The title is too long.', ['title' => 'At most ' . self::MAX_TITLE . ' characters.']);
        }

        return $title;
    }

    private function description(mixed $value): string
    {
        $description = is_string($value) ? trim($value) : '';
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw CommunityException::invalid(
                'The description is too long.',
                ['description' => 'At most ' . self::MAX_DESCRIPTION . ' characters.'],
            );
        }

        return $description;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
