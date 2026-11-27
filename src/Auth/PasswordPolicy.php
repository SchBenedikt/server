<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Auth;

/**
 * The host's single password policy.
 *
 * Every path that creates or replaces a password validates through this class:
 * setup, registration, administration, the emailed reset flow and the CLI
 * tools. Keeping the limits in one place prevents a password that one entry
 * point accepts from being silently rejected by another.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * The byte limit protects the hasher from unbounded request bodies. A
     * password of 4096 bytes is far beyond any human passphrase.
     */
    public const MAX_LENGTH = 4096;

    /**
     * A user-facing error for an unacceptable password, or null when it is
     * acceptable.
     */
    public static function error(string $password): ?string
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_LENGTH) {
            return 'The password needs at least ' . self::MIN_LENGTH . ' characters.';
        }

        if (strlen($password) > self::MAX_LENGTH) {
            return 'The password is too long.';
        }

        return null;
    }
}
