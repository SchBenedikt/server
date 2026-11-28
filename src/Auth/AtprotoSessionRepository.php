<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Hash-only storage for native PDS bearer credentials.
 *
 * The raw access and refresh tokens exist only in the response to the client.
 * Every lookup hashes the presented value, and expiry is checked in SQL with
 * the same fixed-width timestamps the rest of the host stores.
 */
final class AtprotoSessionRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function create(
        string $accountId,
        string $accessHash,
        string $refreshHash,
        string $createdAt,
        string $accessExpiresAt,
        string $refreshExpiresAt,
    ): void {
        $this->db->execute(
            'INSERT INTO atproto_session'
            . ' (access_hash, refresh_hash, account_id, created_at, access_expires_at, refresh_expires_at)'
            . ' VALUES (:access_hash, :refresh_hash, :account_id, :created_at, :access_expires_at, :refresh_expires_at)',
            [
                'access_hash' => $accessHash,
                'refresh_hash' => $refreshHash,
                'account_id' => $accountId,
                'created_at' => $createdAt,
                'access_expires_at' => $accessExpiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
            ],
        );
    }

    public function accountForAccess(string $accessHash, string $now): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT account_id FROM atproto_session'
            . ' WHERE access_hash = :access_hash AND access_expires_at > :now AND refresh_expires_at > :now',
            ['access_hash' => $accessHash, 'now' => $now],
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function accountForRefresh(string $refreshHash, string $now): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT account_id FROM atproto_session'
            . ' WHERE refresh_hash = :refresh_hash AND refresh_expires_at > :now',
            ['refresh_hash' => $refreshHash, 'now' => $now],
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function deleteByAccess(string $accessHash): void
    {
        $this->db->execute(
            'DELETE FROM atproto_session WHERE access_hash = :access_hash',
            ['access_hash' => $accessHash],
        );
    }

    public function deleteByTokenHash(string $hash): void
    {
        $this->db->execute(
            'DELETE FROM atproto_session WHERE access_hash = :hash OR refresh_hash = :hash',
            ['hash' => $hash],
        );
    }

    public function deleteByRefresh(string $refreshHash): void
    {
        $this->db->execute(
            'DELETE FROM atproto_session WHERE refresh_hash = :refresh_hash',
            ['refresh_hash' => $refreshHash],
        );
    }

    public function deleteForAccount(string $accountId): int
    {
        return $this->db->execute(
            'DELETE FROM atproto_session WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );
    }

    public function deleteExpired(string $now): int
    {
        return $this->db->execute(
            'DELETE FROM atproto_session WHERE refresh_expires_at <= :now',
            ['now' => $now],
        );
    }

    /**
     * Sessions are addressed by a prefix of the stored access-token hash —
     * enough bits to name one session without ever exposing token material.
     *
     * @return list<array{id: string, created_at: string, access_expires_at: string, refresh_expires_at: string}>
     */
    public function listForAccount(string $accountId, string $now): array
    {
        $rows = $this->db->fetchAll(
            'SELECT access_hash, created_at, access_expires_at, refresh_expires_at FROM atproto_session'
            . ' WHERE account_id = :account_id AND refresh_expires_at > :now'
            . ' ORDER BY created_at DESC',
            ['account_id' => $accountId, 'now' => $now],
        );

        return array_map(static function (array $row): array {
            return [
                'id' => substr((string) $row['access_hash'], 0, 16),
                'created_at' => (string) $row['created_at'],
                'access_expires_at' => (string) $row['access_expires_at'],
                'refresh_expires_at' => (string) $row['refresh_expires_at'],
            ];
        }, $rows);
    }

    /**
     * Revokes one session owned by the account. The prefix must be at least
     * 16 hex characters so a guessed fragment cannot match a foreign hash.
     */
    public function revokeForAccount(string $accountId, string $hashPrefix): bool
    {
        if (preg_match('/\A[0-9a-f]{16,64}\z/', $hashPrefix) !== 1) {
            return false;
        }

        return $this->db->execute(
            'DELETE FROM atproto_session WHERE account_id = :account_id AND access_hash LIKE :prefix',
            ['account_id' => $accountId, 'prefix' => $hashPrefix . '%'],
        ) > 0;
    }
}
