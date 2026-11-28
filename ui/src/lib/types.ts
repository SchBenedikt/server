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

export interface EmojiReaction {
	emoji: string;
	count: number;
}

export interface PostInteraction {
	favourites_count: number;
	favourited: boolean;
	reblogs_count: number;
	reblogged: boolean;
	replies_count: number;
	bookmarked?: boolean;
	emoji_reactions?: EmojiReaction[];
	atproto_mirror?: AtprotoMirrorStatus;
}

export interface AtprotoMirrorStatus {
	state: 'published' | 'removed' | 'failed' | 'unavailable';
	retryable: boolean;
	reason?: string;
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
	language?: string | null;
	published_at: string;
	edited_at?: string | null;
	revision?: number;
	deleted_at?: string | null;
	in_reply_to_id?: string | null;
	recipient_ids?: string[];
	conversation_id?: string;
	unread?: boolean;
	dismissed?: boolean;
	quoted_object_id?: string | null;
	quote_state?: 'pending' | 'accepted' | 'rejected' | 'revoked' | null;
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
	requested?: boolean;
}

export interface FollowRequest {
	id: string;
	target_account_id: string;
	actor_id: string;
	actor_handle: string;
	is_local: boolean;
	created_at: string;
}

export interface FollowRequestData {
	requests: FollowRequest[];
	locked: boolean;
}

export interface BlueskyAccountStatus {
	connected: boolean;
	did?: string;
	handle?: string;
	pds_url?: string;
	connected_at?: string;
	updated_at?: string;
}

export interface BlueskyData {
	account: BlueskyAccountStatus;
	publish_enabled: boolean;
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

export interface AccountConnectionsData {
	profiles: Account[];
	cursor: string | null;
}

export interface RemoteFollowingEntry {
	id: string;
	actor: string;
	inbox: string;
	state: 'pending' | 'accepted' | 'rejected' | string;
	followed_at: string;
}

export interface AtprotoFollowEntry {
	did: string;
	uri: string;
	cid: string;
	rkey: string;
	state: 'pending' | 'active';
	followed_at: string;
}

export interface RemoteEngagementData {
	network: 'activitypub' | 'bluesky';
	type: 'likes' | 'reposts';
	profiles: Account[];
	cursor: string | null;
}

/** A refreshed origin counter snapshot delivered by the live cursor. */
export interface RemoteEngagementUpdate {
	id: string;
	object_id: string;
	likes_count: number;
	boosts_count: number;
	replies_count: number;
}

export type RemoteSearchType = 'all' | 'accounts' | 'posts';
export type RemoteSearchNetwork = 'all' | 'activitypub' | 'bluesky';

export interface RemoteSearchData {
	query: string;
	accounts: Account[];
	posts: TimelineItem[];
	cursor: string | null;
	notes: string[];
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

export type NotificationSenderCategory = 'mutual' | 'following' | 'follower' | 'new' | 'stranger';
export type NotificationPolicyAction = 'allow' | 'hold' | 'drop';

export interface NotificationPolicy {
	actions: Record<NotificationSenderCategory, NotificationPolicyAction>;
	new_account_days: number;
}

export interface NotificationSenderRequest {
	id: string;
	actor_id: string;
	status: 'held' | 'allowed' | 'dismissed';
	category: NotificationSenderCategory;
	created_at: string;
	reviewed_at: string | null;
	note: string | null;
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
	language?: string | null;
	in_reply_to_id?: string | null;
	quoted_post_id?: string | null;
	attachment_ids?: string[];
	recipient_ids?: string[];
}

export interface UpdatePostPayload {
	content: string;
	visibility: 'public' | 'unlisted' | 'followers';
	content_warning: string | null;
	language?: string | null;
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

export interface ScheduledPostPoll {
	options: string[];
	multiple: boolean;
	hide_totals: boolean;
	expires_in: number | null;
	expires_at: string | null;
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
	language?: string | null;
	poll?: ScheduledPostPoll | null;
	created_at: string;
	state: 'scheduled' | 'published' | 'failed' | 'cancelled';
	published_at: string | null;
	failure_reason: string | null;
}

export interface ScheduledPostUpdate {
	content?: string;
	content_warning?: string | null;
	language?: string | null;
	scheduled_at?: string;
	poll?: ScheduledPostPoll | null;
}

export interface ScheduledPostPayload {
	content: string;
	visibility: 'public' | 'unlisted' | 'followers' | 'direct';
	content_warning?: string | null;
	attachment_ids?: string[];
	recipient_ids?: string[];
	in_reply_to_id?: string | null;
	scheduled_at: string;
	language?: string | null;
	poll?: {
		options: string[];
		multiple?: boolean;
		hide_totals?: boolean;
		expires_in?: number;
	} | null;
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
	language?: string | null;
	media_ids?: string[];
	in_reply_to_id?: string | null;
	recipient_ids?: string[];
	poll?: PollInput | null;
}

export interface Announcement {
	id: string;
	author_id: string;
	content: string;
	active: boolean;
	created_at: string;
	updated_at: string;
	expires_at: string | null;
	expired: boolean;
}

export interface InstancePageSummary {
	slug: string;
	title: string;
	updated_at: string;
}

export interface InstancePage extends InstancePageSummary {
	body: string;
}

export interface KokuaHelper {
	account_id: string;
	message: string;
	created_at: string;
}

export type KokuaKind = 'request' | 'offer';
export type KokuaCategory = 'everyday' | 'skills' | 'company' | 'goods' | 'other';
export type KokuaStatus = 'open' | 'done';

export interface KokuaEntry {
	id: string;
	account_id: string;
	kind: KokuaKind;
	title: string;
	details: string;
	category: KokuaCategory;
	place: string;
	needed_by: string | null;
	status: KokuaStatus;
	created_at: string;
	updated_at: string;
	done_at: string | null;
	helpers: KokuaHelper[];
	help_count: number;
	viewer_helping: boolean;
	is_owner: boolean;
}

export interface KokuaCounts {
	open_requests: number;
	open_offers: number;
	done: number;
}

export interface KokuaPage {
	items: KokuaEntry[];
	total: number;
	limit: number;
	offset: number;
	counts: KokuaCounts;
}

export interface KokuaCreatePayload {
	kind: KokuaKind;
	title: string;
	details: string;
	category: KokuaCategory;
	place: string;
	needed_by?: string | null;
}

export interface FeaturedTag {
	tag: string;
	position: number;
}

export interface TrendTag {
	tag: string;
	posts: number;
	accounts: number;
}

export interface TrendLink {
	url: string;
	host: string;
	posts: number;
}

export interface TrendsData {
	tags: TrendTag[];
	links: TrendLink[];
}

export interface MemoryPost {
	id: string;
	published_at: string;
}

export interface MemoryYear {
	year: number;
	posts: MemoryPost[];
}

export interface MemoriesData {
	date: string;
	total: number;
	years: MemoryYear[];
}

export interface Story {
	id: string;
	account_id: string;
	author_handle?: string;
	author_name?: string;
	seen?: boolean;
	content: string;
	background: string;
	created_at: string;
	expires_at: string;
}

export interface StoryGroup {
	account_id: string;
	handle?: string;
	name?: string;
	unseen?: boolean;
	stories: Story[];
}

export interface StoriesData {
	stories: Story[];
	groups: StoryGroup[];
}

export interface Collection {
	id: string;
	account_id: string;
	title: string;
	description: string;
	is_public: boolean;
	item_count: number;
	created_at: string;
	updated_at: string;
	post_ids?: string[];
}

export interface StarterPackMember {
	id: string;
	member_id: string;
	network: 'local' | 'activitypub' | 'bluesky';
	handle: string;
	display_name: string | null;
	avatar_url: string | null;
	inbox_url: string | null;
	position: number;
}

export interface StarterPack {
	id: string;
	owner_id: string;
	owner_handle: string;
	owner_name: string;
	title: string;
	description: string;
	member_count: number;
	created_at: string;
	updated_at: string;
	members: StarterPackMember[];
}

export interface PostRevision {
	post_id: string;
	revision: number;
	content: string;
	content_warning: string | null;
	visibility: string;
	attachment_ids: string[];
	edited_at: string;
}

export interface PostRevisionData {
	post_id: string;
	current_revision: number;
	current: {
		content: string;
		content_warning: string | null;
		visibility: string;
		edited_at: string | null;
	};
	revisions: PostRevision[];
}

export interface PostSource {
	id: string;
	text: string;
	spoiler_text: string | null;
}

export type PostDeliveryState = 'waiting' | 'sending' | 'failing' | 'delivered' | 'abandoned';

export interface PostDeliveryInstance {
	host: string;
	state: PostDeliveryState | string;
	tries: number;
	last: string;
}

export interface PostDelivery {
	delivered: number;
	sending: number;
	waiting: number;
	failing: number;
	abandoned: number;
	total: number;
	instances: PostDeliveryInstance[];
}


export interface DraftPoll {
	options: string[];
	multiple: boolean;
	hide_totals: boolean;
	expires_in: number;
}

export interface Draft {
	id: string;
	account_id: string;
	context: string;
	content: string;
	content_warning: string | null;
	visibility: string;
	language?: string | null;
	attachment_ids: string[];
	in_reply_to_id: string | null;
	quoted_post_id: string | null;
	poll: DraftPoll | null;
	created_at: string;
	updated_at: string;
}

export interface ArchivedPost {
	post_id: string;
	archived_at: string;
}

export interface FeedInterest {
	kind: 'tag' | 'author';
	key: string;
	weight: number;
}

export interface ForYouData {
	posts: string[];
	reasons: Record<string, string>;
	next: string | number | null;
	total: number;
	interests: FeedInterest[];
}

export interface AccountPreferences {
	account_id: string;
	default_visibility: string;
	default_quote_policy: string;
	dm_policy: string;
	show_boosts: boolean;
	discoverable: boolean;
	locked: boolean;
	notify_follows: boolean;
	notify_favourites: boolean;
	notify_boosts: boolean;
	notify_mentions: boolean;
	mirror_likes_to_atproto: boolean;
	mirror_boosts_to_atproto: boolean;
	mirror_atproto_account_did: string | null;
	onboarding_dismissed: boolean;
	recommendations_opt_out: boolean;
	dismissed_announcements: string[];
	ui_locale: string | null;
	updated_at: string | null;
}

export type ThemeName = 'dark' | 'light';
