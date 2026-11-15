<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Session storage keyed by the hash of the cookie value.
 *
 * A leaked database row cannot be replayed as a cookie, and a stolen cookie
 * stops working when its row is deleted.
 */
final class SessionRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function create(SessionRecord $session, ?string $userAgent = null, ?string $ipHash = null): void
    {
        $this->db->execute(
            'INSERT INTO session (id_hash, account_id, csrf_token, created_at, expires_at, user_agent, ip_hash)'
            . ' VALUES (:id_hash, :account_id, :csrf_token, :created_at, :expires_at, :user_agent, :ip_hash)',
            [
                'id_hash' => $session->idHash,
                'account_id' => $session->accountId,
                'csrf_token' => $session->csrfToken,
                'created_at' => $session->createdAt,
                'expires_at' => $session->expiresAt,
                'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 255),
                'ip_hash' => $ipHash,
            ],
        );
    }

    public function find(string $idHash): ?SessionRecord
    {
        $row = $this->db->fetchOne('SELECT * FROM session WHERE id_hash = :id_hash', ['id_hash' => $idHash]);
        if ($row === null) {
            return null;
        }

        return new SessionRecord(
            idHash: (string) $row['id_hash'],
            accountId: $row['account_id'] === null ? null : (string) $row['account_id'],
            csrfToken: (string) $row['csrf_token'],
            createdAt: (string) $row['created_at'],
            expiresAt: (string) $row['expires_at'],
        );
    }

    public function delete(string $idHash): void
    {
        $this->db->execute('DELETE FROM session WHERE id_hash = :id_hash', ['id_hash' => $idHash]);
    }

    public function deleteForAccount(string $accountId): int
    {
        return $this->db->execute('DELETE FROM session WHERE account_id = :account_id', ['account_id' => $accountId]);
    }

    public function deleteExpired(string $now): int
    {
        return $this->db->execute('DELETE FROM session WHERE expires_at <= :now', ['now' => $now]);
    }
}
