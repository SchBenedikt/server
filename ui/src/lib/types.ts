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
	options: PollOption[];
	multiple: boolean;
	hide_totals: boolean;
	expires_at: string;
	votes_count: number;
	voters_count: number;
}

/**
 * A post as returned by the public API, or a home-timeline entry which can
 * additionally describe a boost and a remote object.
 */
export interface TimelineItem {
	id: string;
	author_id: string;
	content: string;
	content_warning: string | null;
	visibility: string | null;
	published_at: string;
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
	followers_count: number;
	following_count: number;
	relationship: Relationship | null;
}

export interface NotificationItem {
	id: string;
	type: 'favourite' | 'follow' | 'mention' | 'reblog';
	actor_id: string;
	post_id: string | null;
	created_at: string;
	read: boolean;
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

export type ThemeName = 'dark' | 'light';
