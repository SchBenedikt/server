<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Migrations;

/**
 * The ordered migration list.
 *
 * Adding a migration means adding a class and appending it here; never edit an
 * applied migration, the runner rejects a changed checksum.
 */
final class Migrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [
            new Version202611140001CoreSchema(),
            new Version202611140002HostSchema(),
            new Version202611140003RateLimitSchema(),
            new Version202611140004PasswordResetSchema(),
            new Version202611140005CoreMainSchema(),
            new Version202611150001AdminSchema(),
            new Version202611160001CommunitySchema(),
            new Version202611160002EngagementSchema(),
            new Version202611160003PreferencesSchema(),
            new Version202611160004FollowRequestSchema(),
            new Version202611160005AtprotoAccountSchema(),
            new Version202611160006PinnedPostSchema(),
            new Version202611160007CoreUpdateSchema(),
            new Version202611170001KokuaSchema(),
            new Version202611170002RuntimeErrorSchema(),
            new Version202611180001CoreSchemaFingerprint(),
            new Version202611180002RemoteReplySchema(),
            new Version202611180003AtprotoSessionSchema(),
            new Version202611180004StorySeenSchema(),
            new Version202611230001AtprotoFollowSchema(),
            new Version202611230002InboundBlocks(),
            new Version202611230003RemotePostReplyEdge(),
            new Version202611240001AtprotoInteractionPreferences(),
            new Version202611240002AtprotoPublicationPdsUrl(),
            new Version202611240003AtprotoInteractionLedger(),
            new Version202611240004AtprotoOAuthSchema(),
            new Version202611250001AtprotoPublicationCid(),
            new Version202611250002PostLanguage(),
            new Version202611250003MastodonOAuthSchema(),
            new Version202611260001AtprotoPublicationIdentity(),
            new Version202611260002RecommendationSnapshots(),
            new Version202611270001AtprotoInteractionAccount(),
            new Version202611280001MastodonStreamEvents(),
            new Version202611280002ConversationMarkers(),
            new Version202611280003RecommendationImpressions(),
            new Version202611280004RemoteEngagementCache(),
            new Version202611280005RemoteActorProfileCache(),
            new Version202611280006RemoteReplyTargetWidth(),
            new Version202611280007ConsolidatePinsAndArchive(),
            new Version202611280008ConsolidateCollectionsAndStories(),
            new Version202611280009RepairStoryViews(),
            new Version202611280010StarterPacks(),
        ];
    }
}
