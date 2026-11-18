import type { HostBridge } from './host-bridge.js';
import type { Account, CreatePostInput, NotificationPage, Post, Relationship } from './types.js';
export declare const DEFAULT_TIMELINE_LIMIT = 20;
export declare const MAX_TIMELINE_LIMIT = 40;
export declare const DEFAULT_NOTIFICATION_LIMIT = 20;
export declare const MAX_NOTIFICATION_LIMIT = 40;
/** Mirrors PostService::MAX_CONTENT_LENGTH for an early, friendly error. */
export declare const MAX_POST_LENGTH = 5000;
/** Mirrors PostService::MAX_CONTENT_WARNING_LENGTH. */
export declare const MAX_CONTENT_WARNING_LENGTH = 200;
export interface PublicTimelineOptions {
    limit?: number;
    before?: string;
}
/**
 * Clamp like the core route does. The backend remains authoritative; this
 * keeps a component's default and the API contract aligned without creating
 * a second validation model.
 */
export declare function normalizeTimelineLimit(limit: number | undefined): number;
export declare function normalizeNotificationLimit(limit: number | undefined): number;
/**
 * Typed reads over the core routes. The bridge is the only transport; this
 * class never calls fetch() and never reads a host global.
 */
export declare class CoreApi {
    private readonly bridge;
    constructor(bridge: HostBridge);
    publicTimeline(options?: PublicTimelineOptions): Promise<Post[]>;
    hashtagTimeline(tag: string, options?: PublicTimelineOptions): Promise<Post[]>;
    getPost(id: string): Promise<Post>;
    getAccount(id: string): Promise<Account>;
    followAccount(id: string): Promise<Relationship>;
    unfollowAccount(id: string): Promise<Relationship>;
    notifications(options?: {
        limit?: number;
        before?: string;
    }): Promise<NotificationPage>;
    dismissNotification(id: string): Promise<void>;
    clearNotifications(): Promise<number>;
    notificationUnreadCount(): Promise<number>;
    createPost(input: CreatePostInput): Promise<Post>;
    private timeline;
    private request;
}
