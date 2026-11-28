<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Community;

use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * Dry-run analysis of an `aloha-account-export` payload before any import
 * writes happen. The report classifies every section as `local` (row data a
 * future apply can take without identity resolution) or `deferred` (the rows
 * reference source-instance accounts, posts, attachments or external
 * identity and need the canonical-identity mapping a later slice provides).
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
        'following' => 'Actor ids are source-instance identities; follows must be re-resolved canonically.',
        'followers' => 'Incoming follows are private graph data and cannot be claimed by an import.',
        'mutes' => 'Actor ids are source-instance identities and need canonical mapping.',
        'blocks' => 'Actor ids are source-instance identities and need canonical mapping.',
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
     * @param array<string, mixed> $payload decoded export document
     *
     * @return array<string, mixed>
     */
    public function preview(array $payload): array
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
            'format' => self::FORMAT,
            'version' => self::SUPPORTED_VERSION,
            'source' => [
                'instance_url' => is_string($instance['url'] ?? null) ? $instance['url'] : null,
                'instance_name' => is_string($instance['name'] ?? null) ? $instance['name'] : null,
                'exported_at' => is_string($payload['exported_at'] ?? null) ? $payload['exported_at'] : null,
                'handle' => is_string($profile['handle'] ?? null) ? $profile['handle'] : null,
            ],
            'sections' => $sections,
            'not_transferred' => $notTransferred,
            'totals' => $totals,
            'apply' => [
                'available' => false,
                'note' => 'Import apply is not available yet; this preview reports what a future import would cover.',
            ],
        ];
    }
}
