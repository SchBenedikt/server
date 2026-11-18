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
}
