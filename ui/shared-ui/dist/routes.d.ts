/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export declare const coreRoutes: {
    readonly publicTimeline: "/api/v1/timelines/public";
    readonly hashtagTimelinePattern: "/api/v1/timelines/tag/{tag}";
    readonly hashtagTimeline: (tag: string) => string;
    readonly posts: "/api/v1/posts";
    readonly postPattern: "/api/v1/posts/{id}";
    readonly post: (id: string) => string;
    readonly accountPattern: "/api/v1/accounts/{id}";
    readonly account: (id: string) => string;
    readonly accountFollowPattern: "/api/v1/accounts/{id}/follow";
    readonly accountFollow: (id: string) => string;
    readonly notifications: "/api/v1/notifications";
    readonly notificationDismissPattern: "/api/v1/notifications/{id}/dismiss";
    readonly notificationDismiss: (id: string) => string;
    readonly notificationsClear: "/api/v1/notifications/clear";
    readonly notificationsUnreadCount: "/api/v1/notifications/unread_count";
};
