/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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

export interface PostInteraction {
	favourites_count: number;
	favourited: boolean;
	reblogs_count: number;
	reblogged: boolean;
	replies_count: number;
}

export interface Poll {
	id?: string;
	options: PollOption[];
	multiple: boolean;
	hide_totals: boolean;
	expires_at: string;
	votes_count: number;
	voters_count: number;
	voted?: boolean;
	own_votes?: number[];
	expired?: boolean;
}

/**
 * A post as returned by the public API, or a home-timeline entry which can
 * additionally describe a boost and a remote object.
 */
export interface TimelineItem {
	id: string;
	author_id: string;
	author_handle?: string | null;
	author_display_name?: string | null;
	author_avatar?: string | null;
	is_remote?: boolean;
	content: string;
	content_warning: string | null;
	visibility: string | null;
	published_at: string;
	edited_at?: string | null;
	revision?: number;
	deleted_at?: string | null;
	in_reply_to_id?: string | null;
	recipient_ids?: string[];
	attachments?: Attachment[];
	poll?: Poll | null;
	kind?: 'post' | 'boost';
	object_id?: string | null;
	object_author_id?: string | null;
	boosted_by?: string | null;
	interaction?: PostInteraction;
	likes_count?: number | null;
	replies_count?: number | null;
	boosts_count?: number | null;
	bookmarked?: boolean;
	external_url?: string | null;
}

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
	followers_count: number | null;
	following_count: number | null;
	posts_count?: number | null;
	avatar?: string | null;
	banner?: string | null;
	bio?: string | null;
	network?: 'activitypub' | 'bluesky';
	can_follow?: boolean;
	inbox?: string | null;
	did?: string | null;
	is_remote?: boolean;
	relationship: Relationship | null;
	published_at?: string | null;
}

export interface RemoteProfileData {
	kind: 'activitypub' | 'bluesky';
	profile: Account;
	posts: TimelineItem[];
	cursor: string | null;
}

export interface RemotePostData {
	network: 'activitypub' | 'bluesky';
	post: TimelineItem;
	parent: TimelineItem | null;
	replies: TimelineItem[];
	profile: Account | null;
}

export interface RemoteConnectionsData {
	network: 'activitypub' | 'bluesky';
	profiles: Account[];
	cursor: string | null;
}

export interface NotificationItem {
	id: string;
	type: string;
	actor_id: string;
	post_id: string | null;
	created_at: string;
	read: boolean;
	account?: Account | null;
	status?: TimelineItem | null;
}

export interface NotificationPageData {
	notifications: NotificationItem[];
	unread_count: number;
}

export interface InstanceInfo {
	name: string;
	description: string;
	version: string;
	api: string;
	federation: boolean;
	email: string;
	contact_account: string;
	registrations: boolean;
	approval_required: boolean;
	invites_enabled: boolean;
	languages: string[];
	thumbnail: string;
	stats: {
		user_count: number;
		status_count: number;
		domain_count: number;
	};
}

export interface FollowedTag {
	tag: string;
	created_at: string;
}

export interface SessionUser {
	id: string;
	handle: string;
	displayName?: string;
}

export type ToastTone = 'info' | 'success' | 'error';

export interface ToastMessage {
	id: number;
	message: string;
	tone: ToastTone;
}

export interface CreatePostPayload {
	content: string;
	visibility: 'public' | 'unlisted' | 'followers' | 'direct';
	content_warning?: string | null;
	in_reply_to_id?: string | null;
	attachment_ids?: string[];
	recipient_ids?: string[];
}

export interface UpdatePostPayload {
	content: string;
	visibility: 'public' | 'unlisted' | 'followers';
	content_warning: string | null;
	attachment_ids?: string[];
	revision?: number;
}

export interface AccountList {
	id: string;
	title: string;
	replies_policy: 'followed' | 'list' | 'none';
	exclusive: boolean;
}

export interface FilterKeyword {
	text: string;
	whole_word: boolean;
}

export type FilterAction = 'warn' | 'hide';

export type FilterContext = 'home' | 'public' | 'notifications' | 'thread' | 'account';

export interface FilterRule {
	id: string;
	name: string;
	action: FilterAction;
	contexts: FilterContext[];
	keywords: FilterKeyword[];
	created_at: string;
	expires_at: string | null;
}

export interface FilterPayload {
	name: string;
	action: FilterAction;
	contexts: FilterContext[];
	keywords: FilterKeyword[];
	expires_at?: string | null;
}

export interface ScheduledPost {
	id: string;
	author_id: string;
	content: string;
	content_warning: string | null;
	visibility: 'public' | 'unlisted' | 'followers' | 'direct';
	attachment_ids: string[];
	recipient_ids: string[];
	in_reply_to_id: string | null;
	scheduled_at: string;
	created_at: string;
	state: 'scheduled' | 'published' | 'failed' | 'cancelled';
	published_at: string | null;
	failure_reason: string | null;
}

export interface ScheduledPostPayload {
	content: string;
	visibility: 'public' | 'unlisted' | 'followers' | 'direct';
	content_warning?: string | null;
	attachment_ids?: string[];
	recipient_ids?: string[];
	in_reply_to_id?: string | null;
	scheduled_at: string;
}

export interface BlockEntry {
	id: string;
	actor: string;
	blocked_at: string;
}

export interface HashtagResult {
	name: string;
	url: string;
}

export interface SearchResults {
	accounts: Account[];
	statuses: TimelineItem[];
	hashtags: HashtagResult[];
}

export interface PollInput {
	options: string[];
	expires_in: number;
	multiple: boolean;
	hide_totals?: boolean;
}

export interface CreateStatusPayload {
	status: string;
	visibility: 'public' | 'unlisted' | 'followers' | 'direct';
	spoiler_text?: string | null;
	media_ids?: string[];
	in_reply_to_id?: string | null;
	recipient_ids?: string[];
	poll?: PollInput | null;
}

export type ThemeName = 'dark' | 'light';
