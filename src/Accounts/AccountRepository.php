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
    ): Account {
        $handle = strtolower(trim($handle));
        $email = strtolower(trim($email));

        $this->db->execute(
            'INSERT INTO account (id, handle, email, password_hash, role, display_name, created_at, disabled_at)'
            . ' VALUES (:id, :handle, :email, :password_hash, :role, :display_name, :created_at, NULL)',
            [
                'id' => $handle,
                'handle' => $handle,
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role,
                'display_name' => $displayName,
                'created_at' => Time::format($this->clock->now()),
            ],
        );

        $account = $this->find($handle);
        if ($account === null) {
            throw new RuntimeException('The account was written but cannot be read back.');
        }

        return $account;
    }

    public function updatePasswordHash(string $id, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE account SET password_hash = :hash WHERE id = :id',
            ['hash' => $passwordHash, 'id' => $id],
        );
    }

    public function disable(string $id): void
    {
        $this->db->execute(
            'UPDATE account SET disabled_at = :now WHERE id = :id',
            ['now' => Time::format($this->clock->now()), 'id' => $id],
        );
    }

    public function count(): int
    {
        $value = $this->db->fetchValue('SELECT COUNT(*) FROM account WHERE disabled_at IS NULL');

        return $value === null ? 0 : (int) $value;
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
            $sql .= ' AND (handle LIKE :query OR display_name LIKE :query OR email LIKE :query)';
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
        );
    }
}
