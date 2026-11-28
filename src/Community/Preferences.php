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
     *     locked: bool,
     *     notify_follows: bool,
     *     notify_favourites: bool,
     *     notify_boosts: bool,
     *     notify_mentions: bool,
     *     mirror_likes_to_atproto: bool,
     *     mirror_boosts_to_atproto: bool,
     *     mirror_atproto_account_did: string|null,
     *     onboarding_dismissed: bool,
     *     onboarding_dismissed_at: string|null,
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
            'locked' => (bool) ($row['locked'] ?? false),
            'notify_follows' => (bool) ($row['notify_follows'] ?? true),
            'notify_favourites' => (bool) ($row['notify_favourites'] ?? true),
            'notify_boosts' => (bool) ($row['notify_boosts'] ?? true),
            'notify_mentions' => (bool) ($row['notify_mentions'] ?? true),
            'mirror_likes_to_atproto' => (bool) ($row['mirror_likes_to_atproto'] ?? false),
            'mirror_boosts_to_atproto' => (bool) ($row['mirror_boosts_to_atproto'] ?? false),
            'mirror_atproto_account_did' => is_string($row['mirror_atproto_account_did'] ?? null)
                && $row['mirror_atproto_account_did'] !== '' ? (string) $row['mirror_atproto_account_did'] : null,
            'onboarding_dismissed' => is_string($row['onboarding_dismissed_at'] ?? null)
                && $row['onboarding_dismissed_at'] !== '',
            'onboarding_dismissed_at' => is_string($row['onboarding_dismissed_at'] ?? null)
                && $row['onboarding_dismissed_at'] !== '' ? (string) $row['onboarding_dismissed_at'] : null,
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
                'Please choose public, unlisted or followers.',
            );
        }
        if (isset($payload['default_quote_policy'])) {
            $next['default_quote_policy'] = $this->enum(
                $payload['default_quote_policy'],
                self::QUOTE_POLICIES,
                'default_quote_policy',
                'Please choose public, followers or nobody.',
            );
        }
        if (isset($payload['dm_policy'])) {
            $next['dm_policy'] = $this->enum(
                $payload['dm_policy'],
                self::DM_POLICIES,
                'dm_policy',
                'Please choose everyone, followers or nobody.',
            );
        }

        foreach (['show_boosts', 'discoverable', 'locked', 'notify_follows', 'notify_favourites', 'notify_boosts', 'notify_mentions', 'mirror_likes_to_atproto', 'mirror_boosts_to_atproto'] as $flag) {
            if (array_key_exists($flag, $payload)) {
                if (!is_bool($payload[$flag])) {
                    throw CommunityException::invalid(
                        'This setting is invalid.',
                        [$flag => 'Please choose on or off.'],
                    );
                }
                $next[$flag] = $payload[$flag];
            }
        }

        if (array_key_exists('mirror_atproto_account_did', $payload)) {
            $did = $payload['mirror_atproto_account_did'];
            if ($did !== null && (!is_string($did) || preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $did) !== 1)) {
                throw CommunityException::invalid(
                    'This setting is invalid.',
                    ['mirror_atproto_account_did' => 'Choose a connected AT Protocol identity.'],
                );
            }
            $next['mirror_atproto_account_did'] = $did;
        }

        $dismissedAt = $current['onboarding_dismissed_at'];
        if (array_key_exists('onboarding_dismissed', $payload)) {
            if (!is_bool($payload['onboarding_dismissed'])) {
                throw CommunityException::invalid(
                    'This setting is invalid.',
                    ['onboarding_dismissed' => 'Please choose on or off.'],
                );
            }
            if ($payload['onboarding_dismissed']) {
                $dismissedAt ??= Time::format($this->clock->now());
            } else {
                $dismissedAt = null;
            }
        }

        $now = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO account_preference (account_id, default_visibility, default_quote_policy, dm_policy,'
            . ' show_boosts, discoverable, locked, notify_follows, notify_favourites, notify_boosts, notify_mentions, mirror_likes_to_atproto, mirror_boosts_to_atproto, mirror_atproto_account_did, onboarding_dismissed_at, updated_at)'
            . ' VALUES (:account_id, :default_visibility, :default_quote_policy, :dm_policy,'
            . ' :show_boosts, :discoverable, :locked, :notify_follows, :notify_favourites, :notify_boosts, :notify_mentions, :mirror_likes_to_atproto, :mirror_boosts_to_atproto, :mirror_atproto_account_did, :onboarding_dismissed_at, :updated_at)'
            . ' ON CONFLICT (account_id) DO UPDATE SET'
            . ' default_visibility = excluded.default_visibility,'
            . ' default_quote_policy = excluded.default_quote_policy,'
            . ' dm_policy = excluded.dm_policy,'
            . ' show_boosts = excluded.show_boosts,'
            . ' discoverable = excluded.discoverable,'
            . ' locked = excluded.locked,'
            . ' notify_follows = excluded.notify_follows,'
            . ' notify_favourites = excluded.notify_favourites,'
            . ' notify_boosts = excluded.notify_boosts,'
            . ' notify_mentions = excluded.notify_mentions,'
            . ' mirror_likes_to_atproto = excluded.mirror_likes_to_atproto,'
            . ' mirror_boosts_to_atproto = excluded.mirror_boosts_to_atproto,'
            . ' mirror_atproto_account_did = excluded.mirror_atproto_account_did,'
            . ' onboarding_dismissed_at = excluded.onboarding_dismissed_at,'
            . ' updated_at = excluded.updated_at',
            [
                'account_id' => $accountId,
                'default_visibility' => $next['default_visibility'],
                'default_quote_policy' => $next['default_quote_policy'],
                'dm_policy' => $next['dm_policy'],
                'show_boosts' => $next['show_boosts'] ? 1 : 0,
                'discoverable' => $next['discoverable'] ? 1 : 0,
                'locked' => $next['locked'] ? 1 : 0,
                'notify_follows' => $next['notify_follows'] ? 1 : 0,
                'notify_favourites' => $next['notify_favourites'] ? 1 : 0,
                'notify_boosts' => $next['notify_boosts'] ? 1 : 0,
                'notify_mentions' => $next['notify_mentions'] ? 1 : 0,
                'mirror_likes_to_atproto' => $next['mirror_likes_to_atproto'] ? 1 : 0,
                'mirror_boosts_to_atproto' => $next['mirror_boosts_to_atproto'] ? 1 : 0,
                'mirror_atproto_account_did' => $next['mirror_atproto_account_did'],
                'onboarding_dismissed_at' => $dismissedAt,
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
            'locked' => false,
            'notify_follows' => true,
            'notify_favourites' => true,
            'notify_boosts' => true,
            'notify_mentions' => true,
            'mirror_likes_to_atproto' => false,
            'mirror_boosts_to_atproto' => false,
            'mirror_atproto_account_did' => null,
            'onboarding_dismissed' => false,
            'onboarding_dismissed_at' => null,
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
            throw CommunityException::invalid('This setting is invalid.', [$field => $message]);
        }

        return $candidate;
    }
}
