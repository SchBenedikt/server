/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export { hostBridgeKey, createAlohaUi, useHostBridge, type AlohaUiOptions } from './plugin.js';
export { assertHostBridgeCompatible } from './compatibility.js';
export { CORE_API_VERSION, HOST_BRIDGE_CONTRACT_VERSION, UI_VERSION } from './version.js';
export type { HostBridge } from './host-bridge.js';
export type { Account, Attachment, CreatePostInput, Notification, NotificationPage, NotificationType, Poll, PollOption, Post, PostVisibility, Relationship, UiCapabilities, UiMethod, UiRequest, UiResponse, UiUser, } from './types.js';
export { coreRoutes } from './routes.js';
export { CoreApi, DEFAULT_NOTIFICATION_LIMIT, DEFAULT_TIMELINE_LIMIT, MAX_CONTENT_WARNING_LENGTH, MAX_NOTIFICATION_LIMIT, MAX_POST_LENGTH, MAX_TIMELINE_LIMIT, normalizeNotificationLimit, normalizeTimelineLimit, type PublicTimelineOptions, } from './core-api.js';
export { UiApiError, UiTransportError, isUiFailure, type UiErrorFields, type UiFailure } from './api-error.js';
export { useCoreApi } from './composables/use-core-api.js';
export { useActorProfile, type ActorProfileState } from './composables/use-actor-profile.js';
export { useComposer, type ComposerState } from './composables/use-composer.js';
export { useNotifications, type NotificationsState } from './composables/use-notifications.js';
export { usePostDetail, type PostDetailState } from './composables/use-post-detail.js';
export { usePublicTimeline, type PublicTimelineState } from './composables/use-public-timeline.js';
export { default as ActorHeader } from './components/ActorHeader.vue';
export { default as NotificationList } from './components/NotificationList.vue';
export { default as PostCard } from './components/PostCard.vue';
export { default as PostComposer } from './components/PostComposer.vue';
export { default as PostDetail } from './components/PostDetail.vue';
export { default as PublicTimeline } from './components/PublicTimeline.vue';
export { FakeHostBridge, fakeRequestKey, type FakeHostBridgeOptions, type FakeResponder, type FakeResponse, } from './testing/fake-host-bridge.js';
