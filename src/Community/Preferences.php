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

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
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

    public const DENSITIES = ['compact', 'comfortable'];

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
     *     recommendations_opt_out: bool,
     *     mirror_atproto_account_did: string|null,
     *     onboarding_dismissed: bool,
     *     onboarding_dismissed_at: string|null,
     *     dismissed_announcements: list<string>,
     *     ui_locale: string|null,
     *     ui_density: string|null,
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
            'recommendations_opt_out' => (bool) ($row['recommendations_opt_out'] ?? false),
            'mirror_atproto_account_did' => is_string($row['mirror_atproto_account_did'] ?? null)
                && $row['mirror_atproto_account_did'] !== '' ? (string) $row['mirror_atproto_account_did'] : null,
            'onboarding_dismissed' => is_string($row['onboarding_dismissed_at'] ?? null)
                && $row['onboarding_dismissed_at'] !== '',
            'onboarding_dismissed_at' => is_string($row['onboarding_dismissed_at'] ?? null)
                && $row['onboarding_dismissed_at'] !== '' ? (string) $row['onboarding_dismissed_at'] : null,
            'dismissed_announcements' => $this->decodeAnnouncementIds($row['dismissed_announcements'] ?? null),
            'ui_locale' => is_string($row['ui_locale'] ?? null) && $row['ui_locale'] !== ''
                ? (string) $row['ui_locale']
                : null,
            'ui_density' => is_string($row['ui_density'] ?? null) && $row['ui_density'] !== ''
                ? (string) $row['ui_density']
                : null,
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

        foreach (['show_boosts', 'discoverable', 'locked', 'notify_follows', 'notify_favourites', 'notify_boosts', 'notify_mentions', 'mirror_likes_to_atproto', 'mirror_boosts_to_atproto', 'recommendations_opt_out'] as $flag) {
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

        if (array_key_exists('ui_locale', $payload)) {
            $locale = $payload['ui_locale'];
            if ($locale !== null && $locale !== ''
                && (!is_string($locale)
                    || strlen($locale) > 35
                    || preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $locale) !== 1)
            ) {
                throw CommunityException::invalid(
                    'This setting is invalid.',
                    ['ui_locale' => 'Choose a valid language tag or reset to the browser default.'],
                );
            }
            $next['ui_locale'] = $locale === '' ? null : $locale;
        }

        if (array_key_exists('ui_density', $payload)) {
            $density = $payload['ui_density'];
            if ($density !== null && (!is_string($density) || !in_array($density, self::DENSITIES, true))) {
                throw CommunityException::invalid(
                    'This setting is invalid.',
                    ['ui_density' => 'Choose compact or comfortable.'],
                );
            }
            $next['ui_density'] = $density;
        }

        $dismissedIds = $current['dismissed_announcements'];
        if (array_key_exists('dismissed_announcements', $payload)) {
            $dismissedIds = $this->announcementIds($payload['dismissed_announcements']);
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
            . ' show_boosts, discoverable, locked, notify_follows, notify_favourites, notify_boosts, notify_mentions, mirror_likes_to_atproto, mirror_boosts_to_atproto, recommendations_opt_out, mirror_atproto_account_did, onboarding_dismissed_at, dismissed_announcements, ui_locale, ui_density, updated_at)'
            . ' VALUES (:account_id, :default_visibility, :default_quote_policy, :dm_policy,'
            . ' :show_boosts, :discoverable, :locked, :notify_follows, :notify_favourites, :notify_boosts, :notify_mentions, :mirror_likes_to_atproto, :mirror_boosts_to_atproto, :recommendations_opt_out, :mirror_atproto_account_did, :onboarding_dismissed_at, :dismissed_announcements, :ui_locale, :ui_density, :updated_at)'
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
            . ' recommendations_opt_out = excluded.recommendations_opt_out,'
            . ' mirror_atproto_account_did = excluded.mirror_atproto_account_did,'
            . ' onboarding_dismissed_at = excluded.onboarding_dismissed_at,'
            . ' dismissed_announcements = excluded.dismissed_announcements,'
            . ' ui_locale = excluded.ui_locale,'
            . ' ui_density = excluded.ui_density,'
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
                'recommendations_opt_out' => $next['recommendations_opt_out'] ? 1 : 0,
                'mirror_atproto_account_did' => $next['mirror_atproto_account_did'],
                'onboarding_dismissed_at' => $dismissedAt,
                'dismissed_announcements' => $dismissedIds === [] ? null : json_encode($dismissedIds),
                'ui_locale' => $next['ui_locale'],
                'ui_density' => $next['ui_density'],
                'updated_at' => $now,
            ],
        );

        // Opting out must not leave a derived interest profile behind: the
        // snapshot, explicit feedback and dwell impressions are all deleted.
        if ($next['recommendations_opt_out']) {
            foreach (['recommendation_snapshot', 'recommendation_feedback', 'recommendation_impression'] as $table) {
                $this->db->execute(
                    'DELETE FROM ' . $table . ' WHERE account_id = :account_id',
                    ['account_id' => $accountId],
                );
            }
        }

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
            'recommendations_opt_out' => false,
            'mirror_atproto_account_did' => null,
            'onboarding_dismissed' => false,
            'onboarding_dismissed_at' => null,
            'dismissed_announcements' => [],
            'ui_locale' => null,
            'ui_density' => null,
            'updated_at' => null,
        ];
    }

    /**
     * Announcement ids are 24 lowercase hex chars; anything else is rejected
     * so the column can never accumulate arbitrary client strings.
     *
     * @return list<string>
     */
    private function announcementIds(mixed $value): array
    {
        if (!is_array($value) || count($value) > 200) {
            throw CommunityException::invalid(
                'This setting is invalid.',
                ['dismissed_announcements' => 'Please send a list of announcement ids.'],
            );
        }

        $ids = [];
        foreach ($value as $id) {
            if (!is_string($id) || preg_match('/\A[0-9a-f]{24}\z/', $id) !== 1) {
                throw CommunityException::invalid(
                    'This setting is invalid.',
                    ['dismissed_announcements' => 'Announcement ids must be 24 hex characters.'],
                );
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private function decodeAnnouncementIds(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn ($id): bool => is_string($id) && preg_match('/\A[0-9a-f]{24}\z/', $id) === 1,
        ));
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
