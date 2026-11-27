<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;
use function trim;

final class PasswordPolicyTest extends TestCase
{
    public function testPolicyCountsCharactersAndKeepsSpaces(): void
    {
        self::assertNull(PasswordPolicy::error('  eight  '));
        self::assertNotNull(PasswordPolicy::error(' seven '));
        self::assertNull(PasswordPolicy::error(str_repeat('ä', PasswordPolicy::MIN_LENGTH)));
        self::assertNotNull(PasswordPolicy::error(str_repeat('ä', PasswordPolicy::MIN_LENGTH - 1)));
    }

    public function testByteLimitMatchesTheConfiguredHasher(): void
    {
        $limit = PasswordPolicy::maxBytes();

        self::assertSame(
            PasswordHasher::supportsArgon2id() ? PasswordPolicy::MAX_LENGTH : PasswordHasher::BCRYPT_MAX_BYTES,
            $limit,
        );
        self::assertNull(PasswordPolicy::error(str_repeat('a', $limit)));
        self::assertNotNull(PasswordPolicy::error(str_repeat('a', $limit + 1)));
    }

    public function testHasherVerifiesTheExactSecretIncludingWhitespace(): void
    {
        $hasher = new PasswordHasher();
        $password = '  exact secret  ';
        $hash = $hasher->hash($password);

        self::assertTrue($hasher->verify($password, $hash));
        self::assertFalse($hasher->verify(trim($password), $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }
}
