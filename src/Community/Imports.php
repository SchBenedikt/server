<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use AlohaSocial\Core\Domain\Federation\PdoMuteRepository;
use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterKeyword;
use AlohaSocial\Core\Domain\Filter\PdoFilterRuleRepository;
use AlohaSocial\Core\Domain\Timeline\PdoFollowedHashtagRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Service\BlockActor;
use AlohaSocial\Core\Service\BlockService;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\FollowedHashtagService;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\MuteService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Accounts\Account;

use function array_key_exists;
use function array_fill_keys;
use function array_map;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Dry-run analysis and sectioned apply for an `aloha-account-export`
 * payload. Sections are classified `local` when their rows carry no
 * source-instance object references and `deferred` when they do; only local
 * sections are ever written, each independently so a partial failure is
 * reported per section instead of aborting the whole import.
 */
final class Imports
{
    public const FORMAT = 'aloha-account-export';

    public const SUPPORTED_VERSION = 1;

    /**
     * Sections whose rows carry no source-instance object references.
     *
     * @var list<string>
     */
    private const LOCAL_SECTIONS = [
        'preferences',
        'followed_hashtags',
        'filters',
        'featured_tags',
        'mutes',
        'blocks',
        'following',
    ];

    /**
     * Why a section cannot be applied verbatim on the target instance.
     *
     * @var array<string, string>
     */
    private const DEFERRED_REASONS = [
        'posts' => 'Post content needs the core archive/Move semantics, including media and revision history.',
        'favourites' => 'Rows reference source-instance post ids that do not exist on the target.',
        'bookmarks' => 'Rows reference source-instance post ids that do not exist on the target.',
        'followers' => 'Incoming follows are private graph data and cannot be claimed by an import.',
        'follow_requests' => 'Incoming requests are private graph data and cannot be claimed by an import.',
        'lists' => 'Lists reference source-instance accounts and posts.',
        'collections' => 'Collections reference source-instance posts.',
        'starter_packs' => 'Packs reference source-instance accounts.',
        'drafts' => 'Drafts reference source-instance attachments and posts.',
        'pinned_posts' => 'Rows reference source-instance post ids.',
        'archived_posts' => 'Rows reference source-instance post ids.',
        'recommendation_feedback' => 'Rows reference source-instance post ids and behavioral signals.',
        'recommendation_impressions' => 'Rows reference source-instance post ids and behavioral signals.',
        'kokua_posts' => 'Kokua entries need re-creation semantics on the target.',
        'kokua_responses' => 'Kokua entries need re-creation semantics on the target.',
        'scheduled_posts' => 'Schedules reference source-instance content and attachments.',
        'announcements' => 'Announcements are authored content and need re-creation semantics.',
        'stories' => 'Stories are ephemeral and viewer-scoped.',
        'story_views' => 'Story views are private receipt data.',
    ];

    /**
     * Top-level payload members that are never section rows: they describe
     * the export or carry data owned outside the portable format.
     *
     * @var array<string, string>
     */
    private const NOT_TRANSFERRED = [
        'core_archive' => 'Owned by the core account-data port, not the host export format.',
        'linked_bluesky_account' => 'An external Bluesky identity is never transferable.',
    ];

    /**
     * Top-level keys that describe the export rather than carry section rows.
     *
     * @var list<string>
     */
    private const META_KEYS = [
        'format',
        'version',
        'exported_at',
        'instance',
        'profile',
    ];

    /**
     * Preference keys safe to copy: display, notification and discovery
     * choices. Mirror and onboarding state describe the source instance or
     * this browser's progress and must not be claimed by an import.
     *
     * @var list<string>
     */
    private const PREFERENCE_KEYS = [
        'default_visibility',
        'default_quote_policy',
        'dm_policy',
        'show_boosts',
        'discoverable',
        'locked',
        'notify_follows',
        'notify_favourites',
        'notify_boosts',
        'notify_mentions',
    ];

    private readonly FollowedHashtagService $hashtags;

    private readonly FilterService $filters;

    private readonly MuteService $mutes;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Preferences $preferences,
        private readonly FeaturedTags $featuredTags,
        private readonly BlockService $blockService,
        private readonly FollowingService $followingService,
    ) {
        $this->hashtags = new FollowedHashtagService(
            new PdoFollowedHashtagRepository($db),
            $clock,
        );
        $this->filters = new FilterService(
            new PdoFilterRuleRepository($db),
            $clock,
            new RandomIdGenerator(),
        );
        $this->mutes = new MuteService(new PdoMuteRepository($db), $clock);
    }

    /**
     * @param array<string, mixed> $payload decoded export document
     *
     * @return array<string, mixed>
     */
    public function preview(array $payload): array
    {
        $analysis = $this->analyze($payload);

        return [
            'format' => self::FORMAT,
            'version' => self::SUPPORTED_VERSION,
            'source' => $analysis['source'],
            'sections' => $analysis['sections'],
            'not_transferred' => $analysis['not_transferred'],
            'totals' => $analysis['totals'],
            'apply' => [
                'available' => true,
                'note' => 'Only local sections are applied; deferred and unknown sections are reported and skipped.',
            ],
        ];
    }

    /**
     * Applies the `local` sections of an export to the signed-in account.
     * Each section is independent: a failure is reported on that section and
     * the remaining sections still run. Re-running is safe — hashtags dedupe,
     * filters skip existing names, featured tags merge, preferences overwrite
     * only the whitelisted keys.
     *
     * @param array<string, mixed> $payload decoded export document
     * @param list<string>|null $sections restrict the apply to these section names
     *
     * @return array<string, mixed>
     */
    public function apply(Account $account, array $payload, ?array $sections = null): array
    {
        $analysis = $this->analyze($payload);
        $wanted = $sections === null ? null : array_fill_keys($sections, true);

        $results = [];
        foreach ($analysis['sections'] as $section) {
            $name = $section['name'];
            if ($wanted !== null && !isset($wanted[$name])) {
                $results[] = [
                    'name' => $name,
                    'disposition' => $section['disposition'],
                    'status' => 'skipped',
                    'reason' => 'Not selected for this import.',
                ];

                continue;
            }
            if ($section['disposition'] !== 'local') {
                $results[] = [
                    'name' => $name,
                    'disposition' => $section['disposition'],
                    'status' => 'skipped',
                    'reason' => $section['reason'],
                ];

                continue;
            }

            try {
                $outcome = $this->applySection($account->id, $name, $payload[$name]);
                $results[] = [
                    'name' => $name,
                    'disposition' => 'local',
                    'status' => 'applied',
                    'rows' => $outcome['rows'],
                    'skipped_rows' => $outcome['skipped'],
                    'detail' => $outcome['detail'],
                ];
            } catch (\Throwable $caught) {
                $results[] = [
                    'name' => $name,
                    'disposition' => 'local',
                    'status' => 'failed',
                    'reason' => $caught->getMessage(),
                ];
            }
        }

        return [
            'format' => self::FORMAT,
            'version' => self::SUPPORTED_VERSION,
            'source' => $analysis['source'],
            'results' => $results,
            'not_transferred' => $analysis['not_transferred'],
        ];
    }

    /**
     * Validates the envelope and classifies every top-level member.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{source: array<string, mixed>, sections: list<array<string, mixed>>, not_transferred: list<array<string, mixed>>, totals: array<string, int>}
     */
    private function analyze(array $payload): array
    {
        $format = $payload['format'] ?? null;
        if ($format !== self::FORMAT) {
            throw CommunityException::invalid('This is not an Aloha account export.', [
                'format' => 'Expected ' . self::FORMAT . '.',
            ]);
        }

        $version = $payload['version'] ?? null;
        if ($version !== self::SUPPORTED_VERSION) {
            throw CommunityException::invalid('This export version is not supported.', [
                'version' => 'Expected ' . self::SUPPORTED_VERSION . '.',
            ]);
        }

        $sections = [];
        $totals = ['rows' => 0, 'local_rows' => 0, 'deferred_rows' => 0, 'unknown_rows' => 0];
        foreach ($payload as $name => $value) {
            if (!is_string($name) || in_array($name, self::META_KEYS, true) || isset(self::NOT_TRANSFERRED[$name])) {
                continue;
            }
            if (!is_array($value)) {
                continue;
            }

            $rows = count($value);
            $local = in_array($name, self::LOCAL_SECTIONS, true);
            $deferred = isset(self::DEFERRED_REASONS[$name]);
            $disposition = $local ? 'local' : ($deferred ? 'deferred' : 'unknown');
            $reason = $local ? null : (self::DEFERRED_REASONS[$name] ?? 'This section is not recognized by this importer.');

            $sections[] = [
                'name' => $name,
                'rows' => $rows,
                'disposition' => $disposition,
                'reason' => $reason,
            ];

            $totals['rows'] += $rows;
            if ($local) {
                $totals['local_rows'] += $rows;
            } elseif ($deferred) {
                $totals['deferred_rows'] += $rows;
            } else {
                $totals['unknown_rows'] += $rows;
            }
        }

        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        $instance = is_array($payload['instance'] ?? null) ? $payload['instance'] : [];

        $notTransferred = [];
        foreach (self::NOT_TRANSFERRED as $name => $reason) {
            if (array_key_exists($name, $payload)) {
                $notTransferred[] = ['name' => $name, 'reason' => $reason];
            }
        }

        return [
            'source' => [
                'instance_url' => is_string($instance['url'] ?? null) ? $instance['url'] : null,
                'instance_name' => is_string($instance['name'] ?? null) ? $instance['name'] : null,
                'exported_at' => is_string($payload['exported_at'] ?? null) ? $payload['exported_at'] : null,
                'handle' => is_string($profile['handle'] ?? null) ? $profile['handle'] : null,
            ],
            'sections' => $sections,
            'not_transferred' => $notTransferred,
            'totals' => $totals,
        ];
    }

    /**
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applySection(string $accountId, string $name, mixed $value): array
    {
        return match ($name) {
            'preferences' => $this->applyPreferences($accountId, is_array($value) ? $value : []),
            'followed_hashtags' => $this->applyHashtags($accountId, is_array($value) ? $value : []),
            'filters' => $this->applyFilters($accountId, is_array($value) ? $value : []),
            'featured_tags' => $this->applyFeaturedTags($accountId, is_array($value) ? $value : []),
            'mutes' => $this->applyMutes($accountId, is_array($value) ? $value : []),
            'blocks' => $this->applyBlocks($accountId, is_array($value) ? $value : []),
            'following' => $this->applyFollows($accountId, is_array($value) ? $value : []),
            default => throw CommunityException::invalid('This section cannot be imported.', [
                'section' => 'Only local sections are applicable.',
            ]),
        };
    }

    /**
     * @param array<string, mixed> $preferences
     *
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applyPreferences(string $accountId, array $preferences): array
    {
        $payload = [];
        $applied = 0;
        foreach (self::PREFERENCE_KEYS as $key) {
            if (array_key_exists($key, $preferences)) {
                $payload[$key] = $preferences[$key];
                ++$applied;
            }
        }
        if ($applied === 0) {
            return ['rows' => 0, 'skipped' => 0, 'detail' => 'No importable preference keys found.'];
        }

        $this->preferences->update($accountId, $payload);

        return ['rows' => $applied, 'skipped' => 0, 'detail' => 'Preferences updated.'];
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applyHashtags(string $accountId, array $rows): array
    {
        $added = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $tag = is_array($row) ? ($row['hashtag'] ?? $row['tag'] ?? null) : $row;
            if (!is_string($tag) || trim($tag) === '') {
                ++$skipped;

                continue;
            }
            try {
                [, $isNew] = $this->hashtags->follow($accountId, $tag);
                if ($isNew) {
                    ++$added;
                }
            } catch (\Throwable) {
                ++$skipped;
            }
        }

        return [
            'rows' => $added,
            'skipped' => $skipped,
            'detail' => sprintf('%d hashtag(s) followed, %d skipped.', $added, $skipped),
        ];
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applyFilters(string $accountId, array $rows): array
    {
        $existing = [];
        foreach ($this->filters->forUser($accountId) as $rule) {
            $existing[$rule->name] = true;
        }

        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                ++$skipped;

                continue;
            }
            try {
                $name = is_string($row['name'] ?? null) ? $row['name'] : '';
                $action = FilterAction::fromString(is_string($row['action'] ?? null) ? $row['action'] : '');
                $contexts = $this->filterContexts($row['contexts'] ?? []);
                $keywords = $this->filterKeywords($row['keywords'] ?? []);
                $expiresAt = $this->filterExpiry($row['expires_at'] ?? null);

                if ($name === '' || $contexts === [] || $keywords === [] || isset($existing[$name])) {
                    ++$skipped;

                    continue;
                }
                if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
                    ++$skipped;

                    continue;
                }

                $this->filters->create($accountId, $name, $action, $contexts, $keywords, $expiresAt);
                $existing[$name] = true;
                ++$created;
            } catch (\Throwable) {
                ++$skipped;
            }
        }

        return [
            'rows' => $created,
            'skipped' => $skipped,
            'detail' => sprintf('%d filter(s) created, %d skipped.', $created, $skipped),
        ];
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applyFeaturedTags(string $accountId, array $rows): array
    {
        $current = array_map(
            static fn (array $entry): string => (string) ($entry['tag'] ?? ''),
            $this->featuredTags->forAccount($accountId),
        );
        $merged = $current;
        $seen = array_fill_keys($current, true);
        $skipped = 0;

        foreach ($rows as $row) {
            $tag = is_array($row) ? ($row['tag'] ?? null) : $row;
            if (!is_string($tag)) {
                ++$skipped;

                continue;
            }
            $normalized = $this->featuredTags->normalize($tag);
            if ($normalized === '' || isset($seen[$normalized])) {
                ++$skipped;

                continue;
            }
            $seen[$normalized] = true;
            $merged[] = $normalized;
        }

        $this->featuredTags->replace($accountId, $merged);

        return [
            'rows' => count($merged) - count($current),
            'skipped' => $skipped,
            'detail' => sprintf('%d tag(s) added to %d total.', count($merged) - count($current), count($merged)),
        ];
    }

    /**
     * @return list<FilterContext>
     */
    private function filterContexts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $contexts = [];
        $seen = [];
        foreach ($value as $context) {
            if (is_string($context)) {
                try {
                    $parsed = FilterContext::fromString($context);
                } catch (\Throwable) {
                    continue;
                }
                if (!isset($seen[$parsed->value])) {
                    $seen[$parsed->value] = true;
                    $contexts[] = $parsed;
                }
            }
        }

        return $contexts;
    }

    /**
     * @return list<FilterKeyword>
     */
    private function filterKeywords(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $keywords = [];
        foreach ($value as $keyword) {
            try {
                if (is_string($keyword)) {
                    $keywords[] = new FilterKeyword($keyword);
                } elseif (is_array($keyword) && is_string($keyword['text'] ?? null)) {
                    $keywords[] = new FilterKeyword(
                        $keyword['text'],
                        ($keyword['whole_word'] ?? false) === true,
                    );
                }
            } catch (\Throwable) {
                // Invalid keywords are dropped individually.
            }
        }

        return $keywords;
    }

    private function filterExpiry(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Actor ids in an export are canonical ActivityPub URLs, so a mute can be
     * recreated verbatim. An expiry that already passed is skipped instead of
     * importing a dead mute; a live one keeps only its remaining duration.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applyMutes(string $accountId, array $rows): array
    {
        $now = $this->clock->now();
        $added = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $actorId = is_array($row) && is_string($row['actor_id'] ?? null) ? trim($row['actor_id']) : '';
            if ($actorId === '') {
                ++$skipped;

                continue;
            }

            $duration = null;
            $expiresAt = is_array($row) && is_string($row['expires_at'] ?? null) ? trim($row['expires_at']) : '';
            if ($expiresAt !== '') {
                try {
                    $remaining = Time::parse($expiresAt)->getTimestamp() - $now->getTimestamp();
                } catch (\Throwable) {
                    ++$skipped;

                    continue;
                }
                if ($remaining <= 0) {
                    ++$skipped;

                    continue;
                }
                $duration = $remaining;
            }

            try {
                $this->mutes->mute(
                    $accountId,
                    $actorId,
                    is_array($row) && (bool) ($row['notifications'] ?? false),
                    $duration,
                );
                ++$added;
            } catch (\Throwable) {
                ++$skipped;
            }
        }

        return [
            'rows' => $added,
            'skipped' => $skipped,
            'detail' => sprintf('%d mute(s) restored, %d skipped.', $added, $skipped),
        ];
    }

    /**
     * Blocks carry only the canonical actor id; the core service queues the
     * federated Block/Undo pair like a manual block would.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applyBlocks(string $accountId, array $rows): array
    {
        $added = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $actorId = is_array($row) && is_string($row['actor_id'] ?? null) ? trim($row['actor_id']) : '';
            if ($actorId === '') {
                ++$skipped;

                continue;
            }

            try {
                $result = $this->blockService->block(new BlockActor($accountId, $actorId));
                if ($result->created) {
                    ++$added;
                }
            } catch (\Throwable) {
                ++$skipped;
            }
        }

        return [
            'rows' => $added,
            'skipped' => $skipped,
            'detail' => sprintf('%d block(s) restored, %d skipped.', $added, $skipped),
        ];
    }

    /**
     * Follows are re-requested, never claimed: the exported inbox URL is the
     * actor's canonical inbox, and the remote side still has to accept. Rows
     * whose state was already terminal on the source are requested the same
     * way — the target learns the real state from the peer's answer.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return array{rows: int, skipped: int, detail: string}
     */
    private function applyFollows(string $accountId, array $rows): array
    {
        $added = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $actorId = is_array($row) && is_string($row['actor_id'] ?? null) ? trim($row['actor_id']) : '';
            $inboxUrl = is_array($row) && is_string($row['inbox_url'] ?? null) ? trim($row['inbox_url']) : '';
            if ($actorId === '' || $inboxUrl === '') {
                ++$skipped;

                continue;
            }

            try {
                $this->followingService->follow($accountId, $actorId, $inboxUrl);
                ++$added;
            } catch (\Throwable) {
                ++$skipped;
            }
        }

        return [
            'rows' => $added,
            'skipped' => $skipped,
            'detail' => sprintf('%d follow(s) requested, %d skipped.', $added, $skipped),
        ];
    }
}
