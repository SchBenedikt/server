<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

use function bin2hex;
use function hash;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function random_bytes;
use function strtolower;
use function trim;

/**
 * Administrator-issued registration invitations.
 *
 * An invite is a bearer token shown to the admin exactly once; only its
 * SHA-256 hash is stored. Redemption is a conditional update inside the
 * caller's transaction, so a use is consumed atomically with the account
 * creation it enabled — a failed registration never burns a use, and two
 * concurrent redemptions of a single-use invite cannot both succeed.
 *
 * A redeemed invite satisfies a closed registration and pre-empts the
 * approval queue: the administrator who minted it already vouched for the
 * signup. Email verification, when enabled, still applies.
 */
final class Invitations
{
    public const MAX_ISSUE = 500;

    private const MAX_NOTE = 255;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Creates an invitation and returns the plaintext code — the only time
     * it exists outside the issuing admin's hands.
     *
     * @return array{invitation: array<string, mixed>, code: string}
     */
    public function create(string $adminId, int $maxUses, ?int $expiresInDays, ?string $note): array
    {
        if ($maxUses < 1 || $maxUses > self::MAX_ISSUE) {
            throw new \InvalidArgumentException('max_uses must be between 1 and ' . self::MAX_ISSUE . '.');
        }
        if ($expiresInDays !== null && ($expiresInDays < 1 || $expiresInDays > 3650)) {
            throw new \InvalidArgumentException('expires_in_days must be between 1 and 3650.');
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && ($note === '' || mb_strlen($note) > self::MAX_NOTE)) {
            throw new \InvalidArgumentException('The note must be 1–' . self::MAX_NOTE . ' characters.');
        }

        $code = 'inv_' . bin2hex(random_bytes(24));
        $id = bin2hex(random_bytes(16));
        $now = Time::format($this->clock->now());
        $expiresAt = $expiresInDays === null
            ? null
            : Time::format($this->clock->now()->modify('+' . $expiresInDays . ' days'));

        $this->db->execute(
            'INSERT INTO account_invitation'
            . ' (id, code_hash, code_prefix, created_by, max_uses, uses, expires_at, note, revoked_at, created_at)'
            . ' VALUES (:id, :hash, :prefix, :by, :max_uses, 0, :expires, :note, NULL, :created)',
            [
                'id' => $id,
                'hash' => self::hashCode($code),
                'prefix' => mb_substr($code, 0, 12),
                'by' => $adminId,
                'max_uses' => $maxUses,
                'expires' => $expiresAt,
                'note' => $note,
                'created' => $now,
            ],
        );

        return [
            'invitation' => $this->find($id) ?? [],
            'code' => $code,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.id, i.code_prefix, i.created_by, a.handle AS created_by_handle, i.max_uses, i.uses,'
            . ' i.expires_at, i.note, i.revoked_at, i.created_at'
            . ' FROM account_invitation i LEFT JOIN account a ON a.id = i.created_by'
            . ' ORDER BY i.created_at DESC',
        );

        return array_map(fn (array $row): array => [
            'id' => (string) $row['id'],
            'code_prefix' => (string) $row['code_prefix'],
            'created_by' => (string) $row['created_by'],
            'created_by_handle' => is_string($row['created_by_handle'] ?? null) ? $row['created_by_handle'] : null,
            'max_uses' => (int) $row['max_uses'],
            'uses' => (int) $row['uses'],
            'expires_at' => $row['expires_at'],
            'note' => $row['note'],
            'revoked_at' => $row['revoked_at'],
            'created_at' => (string) $row['created_at'],
            'state' => $this->stateOf($row),
        ], $rows);
    }

    public function revoke(string $id): bool
    {
        return $this->db->execute(
            'UPDATE account_invitation SET revoked_at = :now'
            . ' WHERE id = :id AND revoked_at IS NULL',
            ['now' => Time::format($this->clock->now()), 'id' => $id],
        ) > 0;
    }

    /**
     * Consumes one use of the invitation inside the caller's transaction.
     * Returns the invitation row on success, null when the code is unknown,
     * revoked, expired or exhausted — the caller maps null to a refusal.
     *
     * @return array<string, mixed>|null
     */
    public function redeem(string $code): ?array
    {
        $hash = self::hashCode($code);
        $row = $this->db->fetchOne(
            'SELECT id, max_uses, uses, expires_at, revoked_at FROM account_invitation'
            . ' WHERE code_hash = :hash',
            ['hash' => $hash],
        );
        if ($row === null) {
            return null;
        }

        $now = Time::format($this->clock->now());
        $consumed = $this->db->execute(
            'UPDATE account_invitation SET uses = uses + 1'
            . ' WHERE id = :id AND revoked_at IS NULL AND uses < max_uses'
            . ' AND (expires_at IS NULL OR expires_at > :now)',
            ['id' => (string) $row['id'], 'now' => $now],
        );
        if ($consumed !== 1) {
            return null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, code_prefix, created_by, max_uses, uses, expires_at, note, revoked_at, created_at'
            . ' FROM account_invitation WHERE id = :id',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'code_prefix' => (string) $row['code_prefix'],
            'created_by' => (string) $row['created_by'],
            'max_uses' => (int) $row['max_uses'],
            'uses' => (int) $row['uses'],
            'expires_at' => $row['expires_at'],
            'note' => $row['note'],
            'revoked_at' => $row['revoked_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stateOf(array $row): string
    {
        if (is_string($row['revoked_at'] ?? null)) {
            return 'revoked';
        }
        $expiresAt = $row['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '' && $expiresAt <= Time::format($this->clock->now())) {
            return 'expired';
        }
        if ((int) $row['uses'] >= (int) $row['max_uses']) {
            return 'exhausted';
        }

        return 'active';
    }

    private static function hashCode(string $code): string
    {
        return hash('sha256', strtolower(trim($code)));
    }
}
