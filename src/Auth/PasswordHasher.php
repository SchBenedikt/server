<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

use InvalidArgumentException;

use function in_array;
use function password_algos;
use function strlen;

/**
 * Password hashing with PHP's own API.
 *
 * Argon2id when the build provides it, bcrypt otherwise. The host owns the
 * hashes; the core never sees a password. bcrypt silently truncates after 72
 * bytes, so this class refuses longer new passwords instead of accepting two
 * different secrets as the same credential.
 */
final class PasswordHasher
{
    public const BCRYPT_MAX_BYTES = 72;

    public function hash(string $password): string
    {
        if (strlen($password) > self::maxPasswordBytes()) {
            throw new InvalidArgumentException(
                'The password exceeds the byte limit of the configured hasher.',
            );
        }

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

    public static function supportsArgon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID')
            && in_array(PASSWORD_ARGON2ID, password_algos(), true);
    }

    public static function maxPasswordBytes(): int
    {
        return self::supportsArgon2id() ? PasswordPolicy::MAX_LENGTH : self::BCRYPT_MAX_BYTES;
    }

    private static function algorithm(): string|int
    {
        return self::supportsArgon2id() ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
