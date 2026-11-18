<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Accounts;

use AlohaSocial\Core\Domain\Federation\LocalActorProfile;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileProvider;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Community\Preferences;

/**
 * Publish host-owned local profile settings to ActivityPub and the core inbox.
 *
 * In particular, a locked account must require approval for remote Follow
 * activities as well as for local follows. Returning null for a known account
 * would silently make the core treat its locked flag as false.
 */
final class AccountProfileProvider implements LocalActorProfileProvider
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly Preferences $preferences,
    ) {
    }

    public function profile(string $userId): ?LocalActorProfile
    {
        $account = $this->accounts->find($userId);
        if ($account === null || $account->isDisabled()) {
            return null;
        }

        $preferences = $this->preferences->forAccount($userId);
        $createdAt = Time::parse($account->createdAt);
        $updatedAt = Time::parse($preferences['updated_at'] ?? $account->createdAt);

        return new LocalActorProfile(
            userId: $account->id,
            displayName: $account->name(),
            summary: '',
            avatarUrl: null,
            headerUrl: null,
            attachmentUrls: [],
            fields: [],
            locked: $preferences['locked'],
            hideCollections: $preferences['locked'],
            updatedAt: $updatedAt,
            createdAt: $createdAt,
        );
    }
}
