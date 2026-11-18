/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export interface UiUser {
    id: string;
    handle: string;
    displayName?: string;
}
export type UiMethod = 'GET' | 'POST' | 'DELETE';
export interface UiRequest {
    method: UiMethod;
    path: string;
    query?: Record<string, string>;
    body?: unknown;
}
export interface UiResponse {
    status: number;
    headers: Record<string, string>;
    body: string;
}
export interface UiCapabilities {
    media: boolean;
    atproto: boolean;
}
export type PostVisibility = 'public' | 'unlisted' | 'followers' | 'direct';
export interface Relationship {
    following: boolean;
    followed_by: boolean;
    blocking: boolean;
    muting: boolean;
}
export interface Account {
    id: string;
    handle: string;
    display_name: string;
    url: string;
    followers_count: number;
    following_count: number;
    relationship: Relationship | null;
}
export type NotificationType = 'favourite' | 'follow' | 'mention' | 'reblog';
export interface Notification {
    id: string;
    type: NotificationType;
    actor_id: string;
    post_id: string | null;
    created_at: string;
    read: boolean;
}
export interface NotificationPage {
    notifications: Notification[];
    unreadCount: number;
}
export interface CreatePostInput {
    content: string;
    visibility?: PostVisibility;
    contentWarning?: string | null;
    inReplyToId?: string | null;
}
export interface Attachment {
    id: string;
    media_type: string;
    mime_type: string;
    width: number;
    height: number;
    byte_size: number;
    alt_text: string | null;
    created_at: string;
    url: string;
    thumbnail_url: string | null;
}
export interface PollOption {
    title: string;
    votes_count: number;
}
export interface Poll {
    options: PollOption[];
    multiple: boolean;
    hide_totals: boolean;
    expires_at: string;
    votes_count: number;
    voters_count: number;
}
/**
 * The core post shape, including the optional presentation fields a host
 * media service adds. The UI intentionally does not invent fields the core
 * does not return.
 */
export interface Post {
    id: string;
    author_id: string;
    in_reply_to_id?: string | null;
    content: string;
    content_warning: string | null;
    visibility: string | null;
    published_at: string;
    deleted_at: string | null;
    attachments?: Attachment[];
    poll?: Poll | null;
}
