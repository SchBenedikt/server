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
use RuntimeException;

/**
 * The account table.
 *
 * The core has no user table (ADR 0006); this repository is the identity
 * authority of the standalone host and the source of the id the core is
 * trusted with.
 */
final class AccountRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function find(string $id): ?Account
    {
        $row = $this->db->fetchOne('SELECT * FROM account WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::fromRow($row);
    }

    public function findByHandle(string $handle): ?Account
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM account WHERE handle = :handle',
            ['handle' => strtolower(trim($handle))],
        );

        return $row === null ? null : self::fromRow($row);
    }

    public function findByEmail(string $email): ?Account
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM account WHERE email = :email',
            ['email' => strtolower(trim($email))],
        );

        return $row === null ? null : self::fromRow($row);
    }

    public function exists(string $handle, string $email): bool
    {
        return $this->findByHandle($handle) !== null || $this->findByEmail($email) !== null;
    }

    public function create(
        string $handle,
        string $email,
        string $passwordHash,
        string $role = Account::ROLE_USER,
        ?string $displayName = null,
        bool $disabled = false,
    ): Account {
        $handle = strtolower(trim($handle));
        $email = strtolower(trim($email));

        $this->db->execute(
            'INSERT INTO account (id, handle, email, password_hash, role, display_name, created_at, disabled_at, credential_version)'
            . ' VALUES (:id, :handle, :email, :password_hash, :role, :display_name, :created_at, :disabled_at, 1)',
            [
                'id' => $handle,
                'handle' => $handle,
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role,
                'display_name' => $displayName,
                'created_at' => Time::format($this->clock->now()),
                'disabled_at' => $disabled ? Time::format($this->clock->now()) : null,
            ],
        );

        $account = $this->find($handle);
        if ($account === null) {
            throw new RuntimeException('The account was written but cannot be read back.');
        }

        return $account;
    }

    /**
     * Replaces the credential and advances its generation.
     *
     * The increment is part of the same statement, so a concurrent reset
     * cannot observe the new hash with a stale generation.
     */
    public function updatePasswordHash(string $id, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE account SET password_hash = :hash, credential_version = credential_version + 1 WHERE id = :id',
            ['hash' => $passwordHash, 'id' => $id],
        );
    }

    /**
     * Upgrades the stored hash without changing the password.
     *
     * A login-time algorithm upgrade must not invalidate an outstanding
     * password-reset link, so it deliberately leaves the generation alone.
     */
    public function rehashPasswordHash(string $id, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE account SET password_hash = :hash WHERE id = :id',
            ['hash' => $passwordHash, 'id' => $id],
        );
    }

    public function updateDisplayName(string $id, ?string $displayName): void
    {
        $name = $displayName === null ? null : trim($displayName);
        $this->db->execute(
            'UPDATE account SET display_name = :display_name WHERE id = :id',
            [
                'display_name' => $name === '' ? null : $name,
                'id' => $id,
            ],
        );
    }

    public function disable(string $id): void
    {
        $this->db->execute(
            'UPDATE account SET disabled_at = :now WHERE id = :id',
            ['now' => Time::format($this->clock->now()), 'id' => $id],
        );
    }

    public function enable(string $id): void
    {
        $this->db->execute(
            'UPDATE account SET disabled_at = NULL WHERE id = :id',
            ['id' => $id],
        );
    }

    public function updateRole(string $id, string $role): void
    {
        $this->db->execute(
            'UPDATE account SET role = :role WHERE id = :id',
            ['role' => $role, 'id' => $id],
        );
    }

    public function activeAdminCount(): int
    {
        $value = $this->db->fetchValue(
            "SELECT COUNT(*) FROM account WHERE role = 'admin' AND disabled_at IS NULL",
        );

        return $value === null ? 0 : (int) $value;
    }

    public function count(): int
    {
        $value = $this->db->fetchValue('SELECT COUNT(*) FROM account WHERE disabled_at IS NULL');

        return $value === null ? 0 : (int) $value;
    }

    /**
     * Public display names for the shared UI directory.
     *
     * Email addresses and other account fields never leave the host.
     *
     * @return array<string, string>
     */
    public function displayNames(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, display_name FROM account WHERE disabled_at IS NULL AND display_name IS NOT NULL'
            . ' ORDER BY handle ASC LIMIT 500',
        );

        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['display_name'] ?? ''));
            if ($name !== '') {
                $names[(string) $row['id']] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<string>|null $allowedIds
     *
     * @return list<string>
     */
    public function search(string $query, int $limit, int $offset, ?array $allowedIds = null): array
    {
        $limit = max(1, min(80, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT id FROM account WHERE disabled_at IS NULL';
        $parameters = [];

        $query = trim($query);
        if ($query !== '') {
            // Never match the private registration email here. Admin account
            // search has its own authorized query in AdminService::users().
            $sql .= ' AND (handle LIKE :query OR display_name LIKE :query)';
            $parameters['query'] = '%' . $query . '%';
        }

        if ($allowedIds !== null) {
            if ($allowedIds === []) {
                return [];
            }

            $placeholders = [];
            foreach (array_values($allowedIds) as $index => $id) {
                $key = 'allowed' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $id;
            }

            $sql .= ' AND id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY handle ASC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->db->fetchAll($sql, $parameters);

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): Account
    {
        return new Account(
            id: (string) $row['id'],
            handle: (string) $row['handle'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            role: (string) $row['role'],
            displayName: $row['display_name'] === null ? null : (string) $row['display_name'],
            createdAt: (string) $row['created_at'],
            disabledAt: $row['disabled_at'] === null ? null : (string) $row['disabled_at'],
            credentialVersion: (int) ($row['credential_version'] ?? 1),
        );
    }
}
