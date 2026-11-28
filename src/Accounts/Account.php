<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

/**
 * A local account, owned by the host (ADR 0006).
 *
 * The id is the string the core receives as `Request::$userId`; it appears in
 * the actor URL (`/@id`), so it is the handle rather than an opaque row id.
 */
final readonly class Account
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_MODERATOR = 'moderator';

    public const ROLE_USER = 'user';

    public function __construct(
        public string $id,
        public string $handle,
        public string $email,
        public string $passwordHash,
        public string $role,
        public ?string $displayName,
        public string $createdAt,
        public ?string $disabledAt,
        public int $credentialVersion = 1,
        public ?string $emailVerifiedAt = null,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Moderators handle reports and moderation actions but never instance
     * settings, accounts, pages or other administrative surfaces.
     */
    public function isModerator(): bool
    {
        return $this->role === self::ROLE_MODERATOR;
    }

    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    public function name(): string
    {
        $name = $this->displayName !== null ? trim($this->displayName) : '';

        return $name === '' ? $this->handle : $name;
    }
}
