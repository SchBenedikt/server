<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

/**
 * Password hashing with PHP's own API.
 *
 * Argon2id when the build provides it, bcrypt otherwise. The host owns the
 * hashes; the core never sees a password.
 */
final class PasswordHasher
{
    public function hash(string $password): string
    {
        $hash = password_hash($password, self::algorithm());
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('The password could not be hashed.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    private static function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
