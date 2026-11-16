<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

use function array_key_exists;
use function in_array;
use function is_bool;
use function is_string;
use function trim;

/**
 * Account-scoped defaults and notification switches.
 *
 * The core stores no profile settings: these values shape the composer and the
 * notification stream, not who may read a post. Every read therefore falls
 * back to a documented default so an account that never opened the settings
 * page behaves exactly like before this feature existed.
 */
final class Preferences
{
    public const DEFAULT_VISIBILITY = 'public';

    public const DEFAULT_QUOTE_POLICY = 'public';

    public const DEFAULT_DM_POLICY = 'everyone';

    public const VISIBILITIES = ['public', 'unlisted', 'followers'];

    public const QUOTE_POLICIES = ['public', 'followers', 'nobody'];

    public const DM_POLICIES = ['everyone', 'followers', 'nobody'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     account_id: string,
     *     default_visibility: string,
     *     default_quote_policy: string,
     *     dm_policy: string,
     *     show_boosts: bool,
     *     discoverable: bool,
     *     notify_follows: bool,
     *     notify_favourites: bool,
     *     notify_boosts: bool,
     *     notify_mentions: bool,
     *     updated_at: string|null
     * }
     */
    public function forAccount(string $accountId): array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM account_preference WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );

        if ($row === null) {
            return $this->defaults($accountId);
        }

        return [
            'account_id' => $accountId,
            'default_visibility' => in_array($row['default_visibility'], self::VISIBILITIES, true)
                ? (string) $row['default_visibility']
                : self::DEFAULT_VISIBILITY,
            'default_quote_policy' => in_array($row['default_quote_policy'], self::QUOTE_POLICIES, true)
                ? (string) $row['default_quote_policy']
                : self::DEFAULT_QUOTE_POLICY,
            'dm_policy' => in_array($row['dm_policy'], self::DM_POLICIES, true)
                ? (string) $row['dm_policy']
                : self::DEFAULT_DM_POLICY,
            'show_boosts' => (bool) ($row['show_boosts'] ?? true),
            'discoverable' => (bool) ($row['discoverable'] ?? true),
            'notify_follows' => (bool) ($row['notify_follows'] ?? true),
            'notify_favourites' => (bool) ($row['notify_favourites'] ?? true),
            'notify_boosts' => (bool) ($row['notify_boosts'] ?? true),
            'notify_mentions' => (bool) ($row['notify_mentions'] ?? true),
            'updated_at' => is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function update(string $accountId, array $payload): array
    {
        $current = $this->forAccount($accountId);
        $next = $current;

        if (isset($payload['default_visibility'])) {
            $next['default_visibility'] = $this->enum(
                $payload['default_visibility'],
                self::VISIBILITIES,
                'default_visibility',
                'Bitte wähle öffentlich, nicht gelistet oder follower.',
            );
        }
        if (isset($payload['default_quote_policy'])) {
            $next['default_quote_policy'] = $this->enum(
                $payload['default_quote_policy'],
                self::QUOTE_POLICIES,
                'default_quote_policy',
                'Bitte wähle öffentlich, follower oder niemand.',
            );
        }
        if (isset($payload['dm_policy'])) {
            $next['dm_policy'] = $this->enum(
                $payload['dm_policy'],
                self::DM_POLICIES,
                'dm_policy',
                'Bitte wähle alle, follower oder niemand.',
            );
        }

        foreach (['show_boosts', 'discoverable', 'notify_follows', 'notify_favourites', 'notify_boosts', 'notify_mentions'] as $flag) {
            if (array_key_exists($flag, $payload)) {
                if (!is_bool($payload[$flag])) {
                    throw CommunityException::invalid(
                        'Diese Einstellung ist ungültig.',
                        [$flag => 'Bitte wähle an oder aus.'],
                    );
                }
                $next[$flag] = $payload[$flag];
            }
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO account_preference (account_id, default_visibility, default_quote_policy, dm_policy,'
            . ' show_boosts, discoverable, notify_follows, notify_favourites, notify_boosts, notify_mentions, updated_at)'
            . ' VALUES (:account_id, :default_visibility, :default_quote_policy, :dm_policy,'
            . ' :show_boosts, :discoverable, :notify_follows, :notify_favourites, :notify_boosts, :notify_mentions, :updated_at)'
            . ' ON CONFLICT (account_id) DO UPDATE SET'
            . ' default_visibility = excluded.default_visibility,'
            . ' default_quote_policy = excluded.default_quote_policy,'
            . ' dm_policy = excluded.dm_policy,'
            . ' show_boosts = excluded.show_boosts,'
            . ' discoverable = excluded.discoverable,'
            . ' notify_follows = excluded.notify_follows,'
            . ' notify_favourites = excluded.notify_favourites,'
            . ' notify_boosts = excluded.notify_boosts,'
            . ' notify_mentions = excluded.notify_mentions,'
            . ' updated_at = excluded.updated_at',
            [
                'account_id' => $accountId,
                'default_visibility' => $next['default_visibility'],
                'default_quote_policy' => $next['default_quote_policy'],
                'dm_policy' => $next['dm_policy'],
                'show_boosts' => $next['show_boosts'] ? 1 : 0,
                'discoverable' => $next['discoverable'] ? 1 : 0,
                'notify_follows' => $next['notify_follows'] ? 1 : 0,
                'notify_favourites' => $next['notify_favourites'] ? 1 : 0,
                'notify_boosts' => $next['notify_boosts'] ? 1 : 0,
                'notify_mentions' => $next['notify_mentions'] ? 1 : 0,
                'updated_at' => $now,
            ],
        );

        return $this->forAccount($accountId);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(string $accountId): array
    {
        return [
            'account_id' => $accountId,
            'default_visibility' => self::DEFAULT_VISIBILITY,
            'default_quote_policy' => self::DEFAULT_QUOTE_POLICY,
            'dm_policy' => self::DEFAULT_DM_POLICY,
            'show_boosts' => true,
            'discoverable' => true,
            'notify_follows' => true,
            'notify_favourites' => true,
            'notify_boosts' => true,
            'notify_mentions' => true,
            'updated_at' => null,
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function enum(mixed $value, array $allowed, string $field, string $message): string
    {
        $candidate = is_string($value) ? trim($value) : '';

        if (!in_array($candidate, $allowed, true)) {
            throw CommunityException::invalid('Diese Einstellung ist ungültig.', [$field => $message]);
        }

        return $candidate;
    }
}
