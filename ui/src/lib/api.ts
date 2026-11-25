/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge, UiMethod } from '@alohasocial/ui';
import type {
	Account,
	AccountList,
	Attachment,
	BlockEntry,
	CreatePostPayload,
	CreateStatusPayload,
	FilterPayload,
	FilterRule,
	FollowedTag,
	HashtagResult,
	InstanceInfo,
	NotificationItem,
	NotificationPageData,
	NotificationPolicy,
	NotificationPolicyAction,
	NotificationSenderCategory,
	NotificationSenderRequest,
	Poll,
	PostInteraction,
	Relationship,
	AccountConnectionsData,
	RemoteConnectionsData,
	RemoteFollowingEntry,
	AtprotoFollowEntry,
	RemoteEngagementData,
	RemoteEngagementUpdate,
	RemotePostData,
	RemoteProfileData,
	RemoteSearchData,
	RemoteSearchNetwork,
	RemoteSearchType,
	ScheduledPost,
	ScheduledPostPayload,
	SearchResults,
	TimelineItem,
	UpdatePostPayload,
	Announcement,
	FeaturedTag,
	TrendsData,
	MemoriesData,
	StoriesData,
	Story,
	Collection,
	PostRevisionData,
	Draft,
	ArchivedPost,
	ForYouData,
	AccountPreferences,
	FollowRequest,
	FollowRequestData,
	BlueskyData,
	BlueskyAccountStatus,
	AtprotoMirrorStatus,
	KokuaCategory,
	KokuaCreatePayload,
	KokuaEntry,
	KokuaPage,
	KokuaStatus,
} from './types';

type JsonRecord = Record<string, unknown>;

export class ApiError extends Error {
	public constructor(
		public readonly status: number,
		public readonly code: string,
		message: string,
		public readonly fields: Record<string, string> = {},
	) {
		super(message);
		this.name = 'ApiError';
	}
}

export type MediaUploader = (file: File, altText: string) => Promise<Attachment>;

export interface AccountStatusesData {
	posts: TimelineItem[];
	next_before: string | null;
}

export interface TimelinePageData {
	posts: TimelineItem[];
	nextCursor: { before: string; beforeId: string } | null;
	hasMore: boolean;
}

export interface AtprotoIdentityData {
	enabled: boolean;
	publish: boolean;
	handle_domain: string;
	pds_url: string;
	identity: {
		did: string;
		handle: string;
		pds_url: string;
		created_at: string;
		verification: {
			dns_name: string;
			dns_value: string;
			well_known_url: string;
		} | null;
	} | null;
	external: BlueskyAccountStatus;
}

export interface AtprotoPublicationStatus {
	postId: string;
	accountDid: string;
	rkey: string;
	uri: string;
	publishedAt: string;
}

export interface AtprotoPublicationLookup {
	state: 'published' | 'pending' | 'failed' | 'not_queued';
	publication: AtprotoPublicationStatus | null;
	attempts: number;
	retryable: boolean;
}

export interface ActivityPubDeliveryLookup {
	state: 'clear' | 'pending' | 'failed';
	pending: number;
	failed: number;
	attempts: number;
	retryable: boolean;
}

export interface AccountMoveStatus {
	event_id: string;
	target_actor_id: string;
	direction: 'inbound' | 'outbound';
	state: 'pending' | 'completed';
	processed_outgoing: number;
	processed_followers: number;
	created_at: string;
	updated_at: string;
}

export interface Api {
	instance(): Promise<InstanceInfo>;
	publicTimeline(limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelineItem[]>;
	publicTimelinePage(limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelinePageData>;
	homeTimeline(limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelineItem[]>;
	homeTimelinePage(limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelinePageData>;
	directTimelinePage(limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelinePageData>;
	markConversationRead(postId: string): Promise<void>;
	dismissConversation(postId: string): Promise<void>;
	tagTimeline(tag: string, limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelineItem[]>;
	tagTimelinePage(tag: string, limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelinePageData>;
	followedTimeline(limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelineItem[]>;
	followedTimelinePage(limit?: number, before?: string | null, beforeId?: string | null): Promise<TimelinePageData>;
	listTimeline(id: string, limit?: number, maxId?: string | null): Promise<TimelineItem[]>;
	post(id: string): Promise<TimelineItem>;
	posts(ids: string[]): Promise<Record<string, TimelineItem>>;
	quotedPosts(ids: string[]): Promise<Record<string, TimelineItem>>;
	account(id: string): Promise<Account>;
	accountStatuses(id: string, limit?: number, before?: string | null): Promise<AccountStatusesData>;
	remoteProfile(handle: string, cursor?: string | null): Promise<RemoteProfileData>;
	remotePost(id: string): Promise<RemotePostData>;
	remoteConnections(handle: string, type: 'followers' | 'following', cursor?: string | null): Promise<RemoteConnectionsData>;
	accountConnections(account: string, type: 'followers' | 'following', maxId?: string | null): Promise<AccountConnectionsData>;
	remoteEngagement(id: string, type: 'likes' | 'reposts', cursor?: string | null): Promise<RemoteEngagementData>;
	remoteSearch(query: string, type?: RemoteSearchType, network?: RemoteSearchNetwork, cursor?: string | null): Promise<RemoteSearchData>;
	followRemote(actor: string, inbox: string): Promise<RemoteFollowingEntry>;
	unfollowRemote(id: string): Promise<void>;
	remoteFollowing(): Promise<RemoteFollowingEntry[]>;
	atprotoFollowStatus(did: string): Promise<{ follow: AtprotoFollowEntry | null; available: boolean }>;
	followAtproto(did: string): Promise<AtprotoFollowEntry>;
	unfollowAtproto(did: string): Promise<void>;
	hostProfiles(): Promise<Record<string, string>>;
	followAccount(id: string): Promise<Relationship>;
	unfollowAccount(id: string): Promise<Relationship>;
	notifications(limit?: number, before?: string | null): Promise<NotificationPageData>;
	notificationRequests(): Promise<NotificationSenderRequest[]>;
	acceptNotificationSender(id: string): Promise<number>;
	dismissNotificationSender(id: string): Promise<number>;
	notificationPolicy(): Promise<NotificationPolicy>;
	updateNotificationPolicy(payload: { actions?: Partial<Record<NotificationSenderCategory, NotificationPolicyAction>>; new_account_days?: number }): Promise<NotificationPolicy>;
	toggleConversationMute(postId: string): Promise<boolean>;
	mastodonNotifications(limit?: number, maxId?: string | null): Promise<NotificationItem[]>;
	unreadCount(): Promise<number>;
	dismissNotification(id: string): Promise<void>;
	clearNotifications(): Promise<number>;
	createPost(payload: CreatePostPayload): Promise<TimelineItem>;
	createStatus(payload: CreateStatusPayload): Promise<TimelineItem>;
	updatePost(id: string, payload: UpdatePostPayload): Promise<TimelineItem>;
	deletePost(id: string): Promise<void>;
	boostPost(id: string): Promise<AtprotoMirrorStatus | null>;
	unboostPost(id: string): Promise<AtprotoMirrorStatus | null>;
	interactions(ids: string[]): Promise<Record<string, PostInteraction>>;
	likePost(id: string): Promise<PostInteraction>;
	unlikePost(id: string): Promise<PostInteraction>;
	updateProfile(displayName: string): Promise<string>;
	announcements(all?: boolean): Promise<Announcement[]>;
	createAnnouncement(content: string, expiresAt?: string | null): Promise<Announcement>;
	updateAnnouncement(id: string, payload: { content?: string; active?: boolean; expires_at?: string | null }): Promise<Announcement>;
	deleteAnnouncement(id: string): Promise<void>;
	featuredTags(account?: string): Promise<FeaturedTag[]>;
	setFeaturedTags(tags: string[]): Promise<FeaturedTag[]>;
	trends(days?: number, limit?: number): Promise<TrendsData>;
	memories(): Promise<MemoriesData>;
	stories(): Promise<StoriesData>;
	createStory(content: string, background?: string): Promise<Story>;
	deleteStory(id: string): Promise<void>;
	markStoriesSeen(ids: string[]): Promise<number>;
	collections(account?: string): Promise<Collection[]>;
	collection(id: string): Promise<Collection>;
	createCollection(title: string, description?: string, isPublic?: boolean): Promise<Collection>;
	updateCollection(id: string, payload: { title?: string; description?: string; is_public?: boolean }): Promise<Collection>;
	deleteCollection(id: string): Promise<void>;
	addCollectionItem(id: string, postId: string): Promise<Collection>;
	removeCollectionItem(id: string, postId: string): Promise<Collection>;
	kokua(params?: { kind?: string; category?: string; state?: string; limit?: number; offset?: number }): Promise<KokuaPage>;
	createKokua(payload: KokuaCreatePayload): Promise<KokuaEntry>;
	setKokuaStatus(id: string, status: KokuaStatus): Promise<KokuaEntry>;
	deleteKokua(id: string): Promise<void>;
	helpKokua(id: string, message: string): Promise<KokuaEntry>;
	withdrawKokuaHelp(id: string): Promise<KokuaEntry>;
	postRevisions(id: string): Promise<PostRevisionData>;
	deleteAccount(): Promise<{ state: string }>;
	accountMove(): Promise<AccountMoveStatus | null>;
	startAccountMove(targetActorId: string): Promise<AccountMoveStatus>;
	drafts(): Promise<Draft[]>;
	saveDraft(payload: {
		context?: string;
		content: string;
		content_warning?: string | null;
		visibility?: string;
		attachment_ids?: string[];
		in_reply_to_id?: string | null;
		quoted_post_id?: string | null;
		poll?: { options: string[]; multiple?: boolean; hide_totals?: boolean; expires_in?: number } | null;
	}): Promise<Draft>;
	deleteDraft(id: string): Promise<void>;
	archivedPosts(): Promise<ArchivedPost[]>;
	archivePost(postId: string): Promise<ArchivedPost>;
	unarchivePost(postId: string): Promise<void>;
	forYou(limit?: number, cursorOrOffset?: string | number | null): Promise<ForYouData>;
	trending(limit?: number, cursorOrOffset?: string | number | null): Promise<ForYouData>;
	followedTags(): Promise<FollowedTag[]>;
	followTag(tag: string): Promise<void>;
	unfollowTag(tag: string): Promise<void>;
	bookmarks(limit?: number, maxId?: string | null): Promise<TimelineItem[]>;
	bookmarkPost(id: string): Promise<void>;
	unbookmarkPost(id: string): Promise<void>;
	search(query: string, limit?: number): Promise<SearchResults>;
	lists(): Promise<AccountList[]>;
	createList(title: string): Promise<AccountList>;
	updateList(id: string, title: string): Promise<AccountList>;
	deleteList(id: string): Promise<void>;
	listAccounts(id: string): Promise<Account[]>;
	addListAccounts(id: string, accountIds: string[]): Promise<void>;
	removeListAccounts(id: string, accountIds: string[]): Promise<void>;
	mutes(): Promise<Account[]>;
	muteAccount(id: string, notifications?: boolean): Promise<void>;
	unmuteAccount(id: string): Promise<void>;
	blocks(): Promise<BlockEntry[]>;
	blockActor(actor: string): Promise<void>;
	unblockActor(id: string): Promise<void>;
	filters(): Promise<FilterRule[]>;
	createFilter(payload: FilterPayload): Promise<FilterRule>;
	updateFilter(id: string, payload: FilterPayload): Promise<FilterRule>;
	deleteFilter(id: string): Promise<void>;
	scheduledPosts(): Promise<ScheduledPost[]>;
	createScheduledPost(payload: ScheduledPostPayload): Promise<ScheduledPost>;
	updateScheduledPost(id: string, scheduledAt: string): Promise<ScheduledPost>;
	deleteScheduledPost(id: string): Promise<void>;
	poll(postId: string): Promise<Poll | null>;
	votePoll(pollId: string, choices: number[]): Promise<Poll | null>;
	uploadMedia(file: File, altText: string): Promise<Attachment>;
	blueskyAccount(): Promise<BlueskyData>;
	connectBluesky(handle: string, appPassword: string): Promise<BlueskyAccountStatus>;
	startBlueskyOAuth(handle: string): Promise<string>;
	disconnectBluesky(): Promise<boolean>;
	publishToBluesky(postId: string, accountDid?: string): Promise<AtprotoPublicationStatus>;
	atprotoPublication(postId: string, accountDid: string): Promise<AtprotoPublicationLookup>;
	activityPubDelivery(postId: string): Promise<ActivityPubDeliveryLookup>;
	retryActivityPubDelivery(postId: string): Promise<ActivityPubDeliveryLookup & { retried: number }>;
	atprotoIdentity(): Promise<AtprotoIdentityData>;
	createAtprotoIdentity(handle?: string): Promise<{ did: string; handle: string }>;
	rotateAtprotoKeys(): Promise<void>;
	forYouImpressions(items: { post_id: string; dwell_ms: number }[]): Promise<void>;
	updates(since: string): Promise<{ cursor: string; created: string[]; deleted: string[]; engagement: RemoteEngagementUpdate[] }>;
	logout(): Promise<void>;
}

function isRecord(value: unknown): value is JsonRecord {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function normalizeActivityPubDelivery(value: unknown): ActivityPubDeliveryLookup {
	if (!isRecord(value)
		|| (value.state !== 'clear' && value.state !== 'pending' && value.state !== 'failed')
		|| typeof value.pending !== 'number'
		|| typeof value.failed !== 'number'
		|| typeof value.attempts !== 'number'
		|| typeof value.retryable !== 'boolean'
	) {
		throw new ApiError(502, 'invalid_response', 'The ActivityPub delivery status is invalid.');
	}

	return {
		state: value.state,
		pending: Math.max(0, value.pending),
		failed: Math.max(0, value.failed),
		attempts: Math.max(0, value.attempts),
		retryable: value.retryable,
	};
}

function errorMessage(body: string, status: number): { code: string; message: string; fields: Record<string, string> } {
	try {
		const parsed: unknown = JSON.parse(body);
		if (isRecord(parsed) && isRecord(parsed.error)) {
			const code = typeof parsed.error.code === 'string' ? parsed.error.code : 'http_error';
			const message = typeof parsed.error.message === 'string' && parsed.error.message !== ''
				? parsed.error.message
				: `The request failed (${status}).`;
			const fields: Record<string, string> = {};
			if (isRecord(parsed.error.fields)) {
				for (const [key, value] of Object.entries(parsed.error.fields)) {
					if (typeof value === 'string') {
						fields[key] = value;
					}
				}
			}

			return { code, message, fields };
		}
	} catch {
		// A non-JSON error body falls through to the generic message.
	}

	return {
		code: 'http_error',
		message: status === 401
			? 'You are not signed in.'
			: `The request failed (${status}).`,
		fields: {},
	};
}

function normalizeAttachment(value: unknown): Attachment | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.url !== 'string') {
		return null;
	}

	return {
		id: value.id,
		media_type: typeof value.media_type === 'string' ? value.media_type : 'image',
		mime_type: typeof value.mime_type === 'string' ? value.mime_type : 'image/*',
		width: typeof value.width === 'number' ? value.width : 0,
		height: typeof value.height === 'number' ? value.height : 0,
		byte_size: typeof value.byte_size === 'number' ? value.byte_size : 0,
		alt_text: typeof value.alt_text === 'string' ? value.alt_text : null,
		created_at: typeof value.created_at === 'string' ? value.created_at : '',
		url: value.url,
		thumbnail_url: typeof value.thumbnail_url === 'string' ? value.thumbnail_url : null,
	};
}

export function normalizeTimelineItem(value: unknown): TimelineItem | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.author_id !== 'string') {
		return null;
	}

	const attachments = Array.isArray(value.attachments)
		? value.attachments.map(normalizeAttachment).filter((item): item is Attachment => item !== null)
		: [];

	return {
		id: value.id,
		author_id: value.author_id,
		author_handle: typeof value.author_handle === 'string' ? value.author_handle : null,
		author_display_name: typeof value.author_display_name === 'string' ? value.author_display_name : null,
		author_avatar: typeof value.author_avatar === 'string' ? value.author_avatar : null,
		is_remote: value.is_remote === true,
		content: typeof value.content === 'string' ? value.content : '',
		content_warning: typeof value.content_warning === 'string' ? value.content_warning : null,
		visibility: typeof value.visibility === 'string' ? value.visibility : null,
		published_at: typeof value.published_at === 'string' ? value.published_at : '',
		edited_at: typeof value.edited_at === 'string' ? value.edited_at : null,
		revision: typeof value.revision === 'number' ? value.revision : undefined,
		deleted_at: typeof value.deleted_at === 'string' ? value.deleted_at : null,
		in_reply_to_id: typeof value.in_reply_to_id === 'string' ? value.in_reply_to_id : null,
		recipient_ids: Array.isArray(value.recipient_ids)
			? value.recipient_ids.filter((id): id is string => typeof id === 'string')
			: [],
		conversation_id: typeof value.conversation_id === 'string' ? value.conversation_id : undefined,
		unread: value.unread === true,
		dismissed: value.dismissed === true,
		quoted_object_id: typeof value.quoted_object_id === 'string' ? value.quoted_object_id : null,
		quote_state: value.quote_state === 'pending' || value.quote_state === 'accepted'
			|| value.quote_state === 'rejected' || value.quote_state === 'revoked'
			? value.quote_state
			: null,
		attachments,
		poll: isRecord(value.poll) ? (value.poll as unknown as TimelineItem['poll']) : null,
		kind: value.kind === 'boost' ? 'boost' : 'post',
		object_id: typeof value.object_id === 'string' ? value.object_id : null,
		object_author_id: typeof value.object_author_id === 'string' ? value.object_author_id : null,
		boosted_by: typeof value.boosted_by === 'string' ? value.boosted_by : null,
		likes_count: typeof value.likes_count === 'number' ? value.likes_count : null,
		replies_count: typeof value.replies_count === 'number' ? value.replies_count : null,
		boosts_count: typeof value.boosts_count === 'number' ? value.boosts_count : null,
		external_url: typeof value.external_url === 'string' ? value.external_url : null,
	};
}

function normalizePosts(value: unknown): TimelineItem[] {
	if (!Array.isArray(value)) {
		return [];
	}

	return value
		.map(normalizeTimelineItem)
		.filter((item): item is TimelineItem => item !== null);
}

/**
 * Home timeline entries for boosts carry the boost wrapper, not the original
 * post's attachments. Resolve those originals in one batch so media survives
 * a reload instead of disappearing until the post is opened.
 */
export async function hydrateTimelineBoosts(posts: TimelineItem[]): Promise<TimelineItem[]> {
	const ids = [...new Set(posts
		.filter((post) => post.kind === 'boost' && post.object_id !== null && (post.attachments?.length ?? 0) === 0)
		.map((post) => post.object_id as string))];

	if (ids.length === 0) {
		return posts;
	}

	try {
		const originals = await getApi().posts(ids);
		return posts.map((post) => {
			if (post.kind !== 'boost' || post.object_id === null) {
				return post;
			}
			const original = originals[post.object_id];
			if (original === undefined) {
				return post;
			}

			return {
				...post,
				attachments: original.attachments ?? [],
				content_warning: post.content_warning ?? original.content_warning,
				in_reply_to_id: post.in_reply_to_id ?? original.in_reply_to_id,
				quoted_object_id: post.quoted_object_id ?? original.quoted_object_id,
				quote_state: post.quote_state ?? original.quote_state,
				poll: post.poll ?? original.poll,
			};
		});
	} catch {
		return posts;
	}
}

function normalizeAccount(value: unknown): Account | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.handle !== 'string') {
		return null;
	}

	const relationship = isRecord(value.relationship)
		? {
			following: value.relationship.following === true,
			followed_by: value.relationship.followed_by === true,
			blocking: value.relationship.blocking === true,
			muting: value.relationship.muting === true,
			requested: value.relationship.requested === true,
		}
		: null;

	return {
		id: value.id,
		handle: value.handle,
		display_name: typeof value.display_name === 'string' ? value.display_name : value.handle,
		url: typeof value.url === 'string' ? value.url : '',
		followers_count: typeof value.followers_count === 'number' ? value.followers_count : null,
		following_count: typeof value.following_count === 'number' ? value.following_count : null,
		posts_count: typeof value.posts_count === 'number' ? value.posts_count : null,
		avatar: typeof value.avatar === 'string' ? value.avatar : null,
		banner: typeof value.banner === 'string' ? value.banner : null,
		bio: typeof value.bio === 'string' ? value.bio : null,
		network: value.network === 'bluesky' ? 'bluesky' : (value.network === 'activitypub' ? 'activitypub' : undefined),
		can_follow: value.can_follow === true,
		inbox: typeof value.inbox === 'string' ? value.inbox : null,
		did: typeof value.did === 'string' ? value.did : null,
		is_remote: value.is_remote === true,
		relationship,
		published_at: typeof value.published_at === 'string' ? value.published_at : null,
	};
}

function normalizeRemoteFollowing(value: unknown): RemoteFollowingEntry | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.actor !== 'string') {
		return null;
	}

	return {
		id: value.id,
		actor: value.actor,
		inbox: typeof value.inbox === 'string' ? value.inbox : '',
		state: typeof value.state === 'string' ? value.state : 'pending',
		followed_at: typeof value.followed_at === 'string' ? value.followed_at : '',
	};
}

function normalizeInteraction(value: unknown): PostInteraction | null {
	if (!isRecord(value)) {
		return null;
	}
	const mirrorValue = isRecord(value.atproto_mirror) ? value.atproto_mirror : null;
	const mirror = mirrorValue && ['published', 'removed', 'failed', 'unavailable'].includes(String(mirrorValue.state))
		? { state: mirrorValue.state as AtprotoMirrorStatus['state'], retryable: mirrorValue.retryable === true,
			...(typeof mirrorValue.reason === 'string' ? { reason: mirrorValue.reason } : {}) }
		: undefined;

	return {
		favourites_count: typeof value.favourites_count === 'number' ? value.favourites_count : 0,
		favourited: value.favourited === true,
		reblogs_count: typeof value.reblogs_count === 'number' ? value.reblogs_count : 0,
		reblogged: value.reblogged === true,
		replies_count: typeof value.replies_count === 'number' ? value.replies_count : 0,
		emoji_reactions: Array.isArray(value.emoji_reactions)
			? value.emoji_reactions
				.filter((entry): entry is { emoji: string; count: number } => (
					isRecord(entry) && typeof entry.emoji === 'string' && typeof entry.count === 'number'
				))
				.map((entry) => ({ emoji: entry.emoji, count: entry.count }))
			: [],
		...(mirror ? { atproto_mirror: mirror } : {}),
	};
}

function normalizeRelationship(value: unknown): Relationship {
	if (!isRecord(value)) {
		return { following: false, followed_by: false, blocking: false, muting: false, requested: false };
	}

	return {
		following: value.following === true,
		followed_by: value.followed_by === true,
		blocking: value.blocking === true,
		muting: value.muting === true,
		requested: value.requested === true,
	};
}

function decodeEntities(value: string): string {
	const named: Record<string, string> = {
		amp: '&',
		lt: '<',
		gt: '>',
		quot: '"',
		apos: "'",
		nbsp: ' ',
	};

	return value.replace(/&(#x?[0-9a-f]+|[a-z]+);/gi, (match, entity: string) => {
		if (entity.startsWith('#x') || entity.startsWith('#X')) {
			const code = Number.parseInt(entity.slice(2), 16);
			return Number.isFinite(code) ? String.fromCodePoint(code) : match;
		}
		if (entity.startsWith('#')) {
			const code = Number.parseInt(entity.slice(1), 10);
			return Number.isFinite(code) ? String.fromCodePoint(code) : match;
		}

		return named[entity.toLowerCase()] ?? match;
	});
}

/**
 * Mastodon returns HTML; the shared UI renders plain text with its own link,
 * tag and mention detection, so block boundaries are kept as newlines and the
 * markup itself is removed.
 */
function htmlToText(value: unknown): string {
	if (typeof value !== 'string') {
		return '';
	}

	const text = value
		.replace(/<br\s*\/?>/gi, '\n')
		.replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
		.replace(/<\/p>/gi, '\n\n')
		.replace(/<[^>]+>/g, '');

	return decodeEntities(text).replace(/\n{3,}/g, '\n\n').trim();
}

function isRemoteAccountId(id: string): boolean {
	return /^https?:\/\//i.test(id);
}

export function normalizeMastodonAccount(value: unknown): Account | null {
	if (!isRecord(value)) {
		return null;
	}

	const id = typeof value.id === 'string' ? value.id : '';
	const acct = typeof value.acct === 'string' && value.acct !== ''
		? value.acct
		: (typeof value.username === 'string' ? value.username : '');
	if (id === '' || acct === '') {
		return null;
	}

	const remote = acct.includes('@') || isRemoteAccountId(id);

	return {
		id,
		handle: acct,
		display_name: typeof value.display_name === 'string' && value.display_name !== ''
			? value.display_name
			: acct,
		url: typeof value.url === 'string' ? value.url : id,
		followers_count: typeof value.followers_count === 'number' ? value.followers_count : null,
		following_count: typeof value.following_count === 'number' ? value.following_count : null,
		posts_count: typeof value.statuses_count === 'number' ? value.statuses_count : null,
		avatar: typeof value.avatar === 'string' && value.avatar !== '' ? value.avatar : null,
		banner: typeof value.header === 'string' && value.header !== '' ? value.header : null,
		bio: typeof value.note === 'string' && value.note !== '' ? htmlToText(value.note) : null,
		network: remote ? 'activitypub' : undefined,
		is_remote: remote,
		relationship: { following: false, followed_by: false, blocking: false, muting: false },
		published_at: typeof value.created_at === 'string' ? value.created_at : null,
	};
}

export function normalizeMastodonPoll(value: unknown): Poll | null {
	if (!isRecord(value)) {
		return null;
	}

	const options = Array.isArray(value.options)
		? value.options
			.filter(isRecord)
			.map((option) => ({
				title: typeof option.title === 'string' ? option.title : '',
				votes_count: typeof option.votes_count === 'number' ? option.votes_count : 0,
			}))
		: [];

	return {
		id: typeof value.id === 'string' ? value.id : undefined,
		options,
		multiple: value.multiple === true,
		hide_totals: value.hide_totals === true,
		expires_at: typeof value.expires_at === 'string' ? value.expires_at : '',
		votes_count: typeof value.votes_count === 'number' ? value.votes_count : 0,
		voters_count: typeof value.voters_count === 'number' ? value.voters_count : 0,
		voted: value.voted === true,
		own_votes: Array.isArray(value.own_votes)
			? value.own_votes.filter((choice): choice is number => typeof choice === 'number')
			: [],
		expired: value.expired === true,
	};
}

function normalizeMastodonAttachment(value: unknown): Attachment | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.url !== 'string') {
		return null;
	}

	const meta = isRecord(value.meta) && isRecord(value.meta.original) ? value.meta.original : {};
	const type = typeof value.type === 'string' ? value.type : 'image';

	return {
		id: value.id,
		media_type: type === 'video' || type === 'gifv' || type === 'audio' ? type : 'image',
		mime_type: typeof value.mime_type === 'string' ? value.mime_type : 'image/*',
		width: typeof meta.width === 'number' ? meta.width : 0,
		height: typeof meta.height === 'number' ? meta.height : 0,
		byte_size: 0,
		alt_text: typeof value.description === 'string' ? value.description : null,
		created_at: '',
		url: value.url,
		thumbnail_url: typeof value.preview_url === 'string' ? value.preview_url : null,
	};
}

function visibilityFromMastodon(value: unknown): string {
	switch (value) {
		case 'private':
			return 'followers';
		case 'direct':
			return 'direct';
		case 'unlisted':
			return 'unlisted';
		default:
			return 'public';
	}
}

export function normalizeMastodonStatus(value: unknown): TimelineItem | null {
	if (!isRecord(value)) {
		return null;
	}

	if (isRecord(value.reblog)) {
		const original = normalizeMastodonStatus(value.reblog);
		if (original === null) {
			return null;
		}

		return {
			...original,
			id: typeof value.id === 'string' ? value.id : original.id,
			kind: 'boost',
			object_id: original.id,
			object_author_id: original.author_id,
			boosted_by: isRecord(value.account) && typeof value.account.id === 'string'
				? value.account.id
				: null,
			published_at: typeof value.created_at === 'string' ? value.created_at : original.published_at,
			likes_count: original.likes_count,
			replies_count: original.replies_count,
			boosts_count: original.boosts_count,
			external_url: original.external_url,
		};
	}

	const account = normalizeMastodonAccount(value.account);
	const id = typeof value.id === 'string' ? value.id : '';
	if (account === null || id === '') {
		return null;
	}

	const attachments = Array.isArray(value.media_attachments)
		? value.media_attachments
			.map(normalizeMastodonAttachment)
			.filter((attachment): attachment is Attachment => attachment !== null)
		: [];
	const warning = typeof value.spoiler_text === 'string' && value.spoiler_text.trim() !== ''
		? value.spoiler_text
		: null;

	return {
		id,
		author_id: account.id,
		author_handle: account.handle,
		author_display_name: account.display_name,
		author_avatar: account.avatar,
		is_remote: account.is_remote === true,
		content: htmlToText(value.content),
		content_warning: warning,
		visibility: visibilityFromMastodon(value.visibility),
		published_at: typeof value.created_at === 'string' ? value.created_at : '',
		edited_at: typeof value.edited_at === 'string' ? value.edited_at : null,
		deleted_at: null,
		in_reply_to_id: typeof value.in_reply_to_id === 'string' ? value.in_reply_to_id : null,
		recipient_ids: [],
		attachments,
		poll: normalizeMastodonPoll(value.poll),
		kind: 'post',
		object_id: null,
		object_author_id: null,
		boosted_by: null,
		likes_count: typeof value.favourites_count === 'number' ? value.favourites_count : null,
		replies_count: typeof value.replies_count === 'number' ? value.replies_count : null,
		boosts_count: typeof value.reblogs_count === 'number' ? value.reblogs_count : null,
		bookmarked: value.bookmarked === true,
		external_url: typeof value.url === 'string' ? value.url : null,
	};
}

function normalizeMastodonStatuses(value: unknown): TimelineItem[] {
	if (!Array.isArray(value)) {
		return [];
	}

	return value
		.map(normalizeMastodonStatus)
		.filter((item): item is TimelineItem => item !== null);
}

function normalizeAccountList(value: unknown): AccountList | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.title !== 'string') {
		return null;
	}

	const policy = value.replies_policy === 'list' || value.replies_policy === 'none'
		? value.replies_policy
		: 'followed';

	return {
		id: value.id,
		title: value.title,
		replies_policy: policy,
		exclusive: value.exclusive === true,
	};
}

function normalizeFilter(value: unknown): FilterRule | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.name !== 'string') {
		return null;
	}

	const keywords = Array.isArray(value.keywords)
		? value.keywords
			.filter(isRecord)
			.map((keyword) => ({
				text: typeof keyword.text === 'string' ? keyword.text : '',
				whole_word: keyword.whole_word === true,
			}))
			.filter((keyword) => keyword.text !== '')
		: [];
	const contexts = Array.isArray(value.contexts)
		? value.contexts.filter((context): context is FilterRule['contexts'][number] => (
			context === 'home'
			|| context === 'public'
			|| context === 'notifications'
			|| context === 'thread'
			|| context === 'account'
		))
		: [];

	return {
		id: value.id,
		name: value.name,
		action: value.action === 'hide' ? 'hide' : 'warn',
		contexts,
		keywords,
		created_at: typeof value.created_at === 'string' ? value.created_at : '',
		expires_at: typeof value.expires_at === 'string' ? value.expires_at : null,
	};
}

function normalizeScheduledPost(value: unknown): ScheduledPost | null {
	if (!isRecord(value) || typeof value.id !== 'string') {
		return null;
	}

	const visibility = value.visibility === 'unlisted' || value.visibility === 'followers' || value.visibility === 'direct'
		? value.visibility
		: 'public';
	const state = value.state === 'published' || value.state === 'failed' || value.state === 'cancelled'
		? value.state
		: 'scheduled';

	return {
		id: value.id,
		author_id: typeof value.author_id === 'string' ? value.author_id : '',
		content: typeof value.content === 'string' ? value.content : '',
		content_warning: typeof value.content_warning === 'string' ? value.content_warning : null,
		visibility,
		attachment_ids: Array.isArray(value.attachment_ids)
			? value.attachment_ids.filter((id): id is string => typeof id === 'string')
			: [],
		recipient_ids: Array.isArray(value.recipient_ids)
			? value.recipient_ids.filter((id): id is string => typeof id === 'string')
			: [],
		in_reply_to_id: typeof value.in_reply_to_id === 'string' ? value.in_reply_to_id : null,
		scheduled_at: typeof value.scheduled_at === 'string' ? value.scheduled_at : '',
		created_at: typeof value.created_at === 'string' ? value.created_at : '',
		state,
		published_at: typeof value.published_at === 'string' ? value.published_at : null,
		failure_reason: typeof value.failure_reason === 'string' ? value.failure_reason : null,
	};
}

function normalizeBlock(value: unknown): BlockEntry | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.actor !== 'string') {
		return null;
	}

	return {
		id: value.id,
		actor: value.actor,
		blocked_at: typeof value.blocked_at === 'string' ? value.blocked_at : '',
	};
}

function normalizeKokua(value: unknown): KokuaEntry | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.account_id !== 'string') {
		return null;
	}

	const kind = value.kind === 'offer' ? 'offer' : 'request';
	const categories: KokuaCategory[] = ['everyday', 'skills', 'company', 'goods', 'other'];
	const category = typeof value.category === 'string' && categories.includes(value.category as KokuaCategory)
		? value.category as KokuaCategory
		: 'other';
	const status: KokuaStatus = value.status === 'done' ? 'done' : 'open';
	const helpers = Array.isArray(value.helpers)
		? value.helpers
			.filter(isRecord)
			.map((helper) => ({
				account_id: typeof helper.account_id === 'string' ? helper.account_id : '',
				message: typeof helper.message === 'string' ? helper.message : '',
				created_at: typeof helper.created_at === 'string' ? helper.created_at : '',
			}))
			.filter((helper) => helper.account_id !== '')
		: [];

	return {
		id: value.id,
		account_id: value.account_id,
		kind,
		title: typeof value.title === 'string' ? value.title : '',
		details: typeof value.details === 'string' ? value.details : '',
		category,
		place: typeof value.place === 'string' ? value.place : '',
		needed_by: typeof value.needed_by === 'string' ? value.needed_by : null,
		status,
		created_at: typeof value.created_at === 'string' ? value.created_at : '',
		updated_at: typeof value.updated_at === 'string' ? value.updated_at : '',
		done_at: typeof value.done_at === 'string' ? value.done_at : null,
		helpers,
		help_count: typeof value.help_count === 'number' ? value.help_count : helpers.length,
		viewer_helping: value.viewer_helping === true,
		is_owner: value.is_owner === true,
	};
}

function normalizeCollection(value: unknown): Collection | null {
	if (!isRecord(value) || typeof value.id !== 'string' || typeof value.title !== 'string') {
		return null;
	}

	return {
		id: value.id,
		account_id: typeof value.account_id === 'string' ? value.account_id : '',
		title: value.title,
		description: typeof value.description === 'string' ? value.description : '',
		is_public: value.is_public === true,
		item_count: typeof value.item_count === 'number' ? value.item_count : 0,
		created_at: typeof value.created_at === 'string' ? value.created_at : '',
		updated_at: typeof value.updated_at === 'string' ? value.updated_at : '',
		post_ids: Array.isArray(value.post_ids)
			? value.post_ids.filter((id): id is string => typeof id === 'string')
			: undefined,
	};
}

function normalizeBlueskyAccount(value: unknown): BlueskyAccountStatus {
	if (!isRecord(value)) {
		return { connected: false };
	}

	return {
		connected: value.connected === true,
		did: typeof value.did === 'string' ? value.did : undefined,
		handle: typeof value.handle === 'string' ? value.handle : undefined,
		pds_url: typeof value.pds_url === 'string' ? value.pds_url : undefined,
		connected_at: typeof value.connected_at === 'string' ? value.connected_at : undefined,
		updated_at: typeof value.updated_at === 'string' ? value.updated_at : undefined,
	};
}

function normalizeAtprotoPublication(value: unknown): AtprotoPublicationStatus | null {
	if (!isRecord(value)
		|| typeof value.post_id !== 'string'
		|| typeof value.account_did !== 'string'
		|| typeof value.rkey !== 'string'
		|| typeof value.uri !== 'string'
		|| typeof value.published_at !== 'string'
	) {
		return null;
	}

	return {
		postId: value.post_id,
		accountDid: value.account_did,
		rkey: value.rkey,
		uri: value.uri,
		publishedAt: value.published_at,
	};
}

function normalizePreferences(value: unknown): AccountPreferences {
	if (!isRecord(value)) {
		throw new ApiError(502, 'invalid_response', 'The response does not contain settings.');
	}

	return {
		account_id: typeof value.account_id === 'string' ? value.account_id : '',
		default_visibility: typeof value.default_visibility === 'string' ? value.default_visibility : 'public',
		default_quote_policy: typeof value.default_quote_policy === 'string' ? value.default_quote_policy : 'public',
		dm_policy: typeof value.dm_policy === 'string' ? value.dm_policy : 'everyone',
		show_boosts: value.show_boosts !== false,
		discoverable: value.discoverable !== false,
		locked: value.locked === true,
		notify_follows: value.notify_follows !== false,
		notify_favourites: value.notify_favourites !== false,
		notify_boosts: value.notify_boosts !== false,
		notify_mentions: value.notify_mentions !== false,
		mirror_likes_to_atproto: value.mirror_likes_to_atproto === true,
		mirror_boosts_to_atproto: value.mirror_boosts_to_atproto === true,
		mirror_atproto_account_did: typeof value.mirror_atproto_account_did === 'string'
			? value.mirror_atproto_account_did
			: null,
		updated_at: typeof value.updated_at === 'string' ? value.updated_at : null,
	};
}

function normalizeDraft(value: unknown): Draft | null {
	if (!isRecord(value) || typeof value.id !== 'string') {
		return null;
	}

	const poll = isRecord(value.poll) && Array.isArray(value.poll.options)
		? {
			options: value.poll.options.filter((option): option is string => typeof option === 'string'),
			multiple: value.poll.multiple === true,
			hide_totals: value.poll.hide_totals === true,
			expires_in: typeof value.poll.expires_in === 'number' ? value.poll.expires_in : 86400,
		}
		: null;

	return {
		id: value.id,
		account_id: typeof value.account_id === 'string' ? value.account_id : '',
		context: typeof value.context === 'string' ? value.context : '',
		content: typeof value.content === 'string' ? value.content : '',
		content_warning: typeof value.content_warning === 'string' ? value.content_warning : null,
		visibility: typeof value.visibility === 'string' ? value.visibility : 'public',
		attachment_ids: Array.isArray(value.attachment_ids)
			? value.attachment_ids.filter((id): id is string => typeof id === 'string')
			: [],
		in_reply_to_id: typeof value.in_reply_to_id === 'string' ? value.in_reply_to_id : null,
		quoted_post_id: typeof value.quoted_post_id === 'string' ? value.quoted_post_id : null,
		poll,
		created_at: typeof value.created_at === 'string' ? value.created_at : '',
		updated_at: typeof value.updated_at === 'string' ? value.updated_at : '',
	};
}

function normalizeMastodonNotification(value: unknown): NotificationItem | null {
	if (!isRecord(value) || typeof value.id !== 'string') {
		return null;
	}

	const account = normalizeMastodonAccount(value.account);
	const status = normalizeMastodonStatus(value.status);

	return {
		id: value.id,
		type: typeof value.type === 'string' ? value.type : 'unknown',
		actor_id: account?.id ?? '',
		post_id: status?.id ?? null,
		created_at: typeof value.created_at === 'string' ? value.created_at : '',
		read: value.unread !== true,
		account,
		status,
	};
}

export function createApi(bridge: HostBridge, uploadMedia: MediaUploader): Api {
	async function request<T>(
		method: UiMethod,
		path: string,
		query?: Record<string, string>,
		body?: unknown,
	): Promise<T> {
		const response = await bridge.request({ method, path, query, body });
		let parsed: unknown = undefined;

		if (response.body.trim() !== '') {
			try {
				parsed = JSON.parse(response.body);
			} catch {
				parsed = response.body;
			}
		}

		if (response.status < 200 || response.status >= 300) {
			const error = errorMessage(response.body, response.status);
			throw new ApiError(response.status, error.code, error.message, error.fields);
		}

		return parsed as T;
	}

	async function pagedTimeline(
		path: string,
		limit: number,
		before?: string | null,
		beforeId?: string | null,
	): Promise<TimelinePageData> {
		const query: Record<string, string> = { limit: String(limit) };
		if (before !== undefined && before !== null && before !== '') {
			query.before = before;
		}
		if (beforeId !== undefined && beforeId !== null && beforeId !== '') {
			query.before_id = beforeId;
		}

		const data = await request<{
			posts?: unknown;
			has_more?: unknown;
			next_before?: unknown;
			next_before_id?: unknown;
		}>('GET', path, query);
		const nextCursor = typeof data?.next_before === 'string'
			&& data.next_before !== ''
			&& typeof data.next_before_id === 'string'
			&& data.next_before_id !== ''
			? { before: data.next_before, beforeId: data.next_before_id }
			: null;

		return {
			posts: normalizePosts(data?.posts),
			nextCursor,
			hasMore: data?.has_more === true && nextCursor !== null,
		};
	}

	function homeTimelinePage(limit: number, before?: string | null, beforeId?: string | null): Promise<TimelinePageData> {
		return pagedTimeline('/api/v1/timelines/home', limit, before, beforeId);
	}

	function directTimelinePage(limit: number, before?: string | null, beforeId?: string | null): Promise<TimelinePageData> {
		return pagedTimeline('/api/v1/timelines/direct', limit, before, beforeId);
	}

	function updateConversation(postId: string, action: 'read' | 'dismiss'): Promise<void> {
		return request<unknown>('POST', `/api/v1/conversations/${encodeURIComponent(postId)}/${action}`)
			.then(() => undefined);
	}

	return {
		async instance(): Promise<InstanceInfo> {
			const data = await request<{ instance?: unknown }>('GET', '/api/v1/instance');
			if (!isRecord(data?.instance) || typeof data.instance.name !== 'string') {
				throw new ApiError(502, 'invalid_response', 'The instance description is incomplete.');
			}

			return data.instance as unknown as InstanceInfo;
		},

		publicTimeline(limit = 20, before = null, beforeId = null): Promise<TimelineItem[]> {
			return pagedTimeline('/api/v1/timelines/public', limit, before, beforeId).then((page) => page.posts);
		},

		publicTimelinePage(limit = 20, before = null, beforeId = null): Promise<TimelinePageData> {
			return pagedTimeline('/api/v1/timelines/public', limit, before, beforeId);
		},

		async accountStatuses(id: string, limit = 20, before: string | null = null): Promise<AccountStatusesData> {
			const query: Record<string, string> = { limit: String(limit) };
			if (before !== null && before !== '') {
				query.before = before;
			}

			const data = await request<{ posts?: unknown; next_before?: unknown }>(
				'GET',
				`/api/v1/accounts/${encodeURIComponent(id)}/statuses`,
				query,
			);

			return {
				posts: normalizePosts(data?.posts),
				next_before: typeof data?.next_before === 'string' && data.next_before !== '' ? data.next_before : null,
			};
		},

		homeTimeline(limit = 20, before = null, beforeId = null): Promise<TimelineItem[]> {
			return homeTimelinePage(limit, before, beforeId).then((page) => page.posts);
		},

		homeTimelinePage(limit = 20, before = null, beforeId = null): Promise<TimelinePageData> {
			return homeTimelinePage(limit, before, beforeId);
		},

		directTimelinePage(limit = 20, before = null, beforeId = null): Promise<TimelinePageData> {
			return directTimelinePage(limit, before, beforeId);
		},

		markConversationRead(postId: string): Promise<void> {
			return updateConversation(postId, 'read');
		},

		dismissConversation(postId: string): Promise<void> {
			return updateConversation(postId, 'dismiss');
		},

		tagTimeline(tag: string, limit = 20, before = null, beforeId = null): Promise<TimelineItem[]> {
			return pagedTimeline(`/api/v1/timelines/tag/${encodeURIComponent(tag)}`, limit, before, beforeId).then((page) => page.posts);
		},

		tagTimelinePage(tag: string, limit = 20, before = null, beforeId = null): Promise<TimelinePageData> {
			return pagedTimeline(`/api/v1/timelines/tag/${encodeURIComponent(tag)}`, limit, before, beforeId);
		},

		followedTimeline(limit = 20, before = null, beforeId = null): Promise<TimelineItem[]> {
			return pagedTimeline('/api/v1/timelines/followed_tags', limit, before, beforeId).then((page) => page.posts);
		},

		followedTimelinePage(limit = 20, before = null, beforeId = null): Promise<TimelinePageData> {
			return pagedTimeline('/api/v1/timelines/followed_tags', limit, before, beforeId);
		},

		async listTimeline(id: string, limit = 20, maxId: string | null = null): Promise<TimelineItem[]> {
			const query: Record<string, string> = { limit: String(limit) };
			if (maxId !== null && maxId !== '') {
				query.max_id = maxId;
			}

			const data = await request<unknown>(
				'GET',
				`/api/mastodon/v1/timelines/list/${encodeURIComponent(id)}`,
				query,
			);

			return normalizeMastodonStatuses(data);
		},

		async hostProfiles(): Promise<Record<string, string>> {
			const data = await request<{ profiles?: unknown }>('GET', '/api/v1/host/profiles');
			const profiles: Record<string, string> = {};
			if (isRecord(data?.profiles)) {
				for (const [id, name] of Object.entries(data.profiles)) {
					if (typeof name === 'string' && name !== '') {
						profiles[id] = name;
					}
				}
			}

			return profiles;
		},

		async post(id: string): Promise<TimelineItem> {
			const data = await request<{ post?: unknown }>('GET', `/api/v1/posts/${encodeURIComponent(id)}`);
			const post = normalizeTimelineItem(data?.post);
			if (post === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a post.');
			}

			return post;
		},

		async posts(ids: string[]): Promise<Record<string, TimelineItem>> {
			const unique = [...new Set(ids.filter((id) => id !== ''))].slice(0, 80);
			if (unique.length === 0) {
				return {};
			}

			const data = await request<{ posts?: unknown }>(
				'GET',
				'/api/v1/host/posts',
				{ ids: unique.join(',') },
			);
			const result: Record<string, TimelineItem> = {};
			if (isRecord(data?.posts)) {
				for (const [id, value] of Object.entries(data.posts)) {
					const post = normalizeTimelineItem(value);
					if (post !== null) {
						result[id] = post;
					}
				}
			}

			return result;
		},

		async quotedPosts(ids: string[]): Promise<Record<string, TimelineItem>> {
			const unique = [...new Set(ids.filter((id) => id !== ''))].slice(0, 40);
			if (unique.length === 0) {
				return {};
			}

			const data = await request<{ quoted?: unknown }>(
				'GET',
				'/api/v1/host/quoted',
				{ ids: unique.join(',') },
			);
			const result: Record<string, TimelineItem> = {};
			if (isRecord(data?.quoted)) {
				for (const [id, value] of Object.entries(data.quoted)) {
					const post = normalizeTimelineItem(value);
					if (post !== null) {
						result[id] = post;
					}
				}
			}

			return result;
		},

		async account(id: string): Promise<Account> {
			const data = await request<{ account?: unknown }>(
				'GET',
				`/api/v1/accounts/${encodeURIComponent(id)}`,
			);
			const account = normalizeAccount(data?.account);
			if (account === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a profile.');
			}

			return account;
		},

		async remoteProfile(handle: string, cursor: string | null = null): Promise<RemoteProfileData> {
			const query: Record<string, string> = { handle };
			if (cursor !== null && cursor !== '') {
				query.cursor = cursor;
			}

			const data = await request<{
				kind?: unknown;
				profile?: unknown;
				posts?: unknown;
				cursor?: unknown;
			}>('GET', '/api/v1/host/lookup', query);

			const profile = normalizeAccount(data?.profile);
			if (profile === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a remote profile.');
			}

			profile.relationship = { following: false, followed_by: false, blocking: false, muting: false };

			return {
				kind: data?.kind === 'bluesky' ? 'bluesky' : 'activitypub',
				profile,
				posts: normalizePosts(data?.posts),
				cursor: typeof data?.cursor === 'string' && data.cursor !== '' ? data.cursor : null,
			};
		},

		async followRemote(actor: string, inbox: string): Promise<RemoteFollowingEntry> {
			const data = await request<{ following?: unknown }>(
				'POST',
				'/api/v1/following',
				undefined,
				{ actor, inbox },
			);
			const entry = normalizeRemoteFollowing(data?.following);
			if (entry === null) {
				throw new ApiError(502, 'invalid_response', 'The follow response is incomplete.');
			}

			return entry;
		},

		async unfollowRemote(id: string): Promise<void> {
			if (id === '') {
				return;
			}
			await request<unknown>('DELETE', `/api/v1/following/${encodeURIComponent(id)}`);
		},

		async remoteFollowing(): Promise<RemoteFollowingEntry[]> {
			const data = await request<{ following?: unknown }>('GET', '/api/v1/host/following');
			return Array.isArray(data?.following)
				? data.following
					.map(normalizeRemoteFollowing)
					.filter((entry): entry is RemoteFollowingEntry => entry !== null)
				: [];
		},

		async atprotoFollowStatus(did: string): Promise<{ follow: AtprotoFollowEntry | null; available: boolean }> {
			const data = await request<{ follow?: AtprotoFollowEntry | null; available?: boolean }>(
				'GET', '/api/v1/host/bluesky/follows', { did },
			);
			return { follow: data?.follow ?? null, available: data?.available === true };
		},

		async followAtproto(did: string): Promise<AtprotoFollowEntry> {
			const data = await request<{ follow?: AtprotoFollowEntry }>(
				'POST', '/api/v1/host/bluesky/follows', undefined, { subject_did: did },
			);
			if (!data?.follow) {
				throw new ApiError(502, 'invalid_response', 'The Bluesky follow response is incomplete.');
			}
			return data.follow;
		},

		async unfollowAtproto(did: string): Promise<void> {
			await request<unknown>('DELETE', '/api/v1/host/bluesky/follows', undefined, { subject_did: did });
		},

		async remotePost(id: string): Promise<RemotePostData> {
			const data = await request<{
				network?: unknown;
				post?: unknown;
				parent?: unknown;
				replies?: unknown;
				profile?: unknown;
			}>('GET', '/api/v1/host/remote-post', { id });

			const post = normalizeTimelineItem(data?.post);
			if (post === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a post.');
			}

			return {
				network: data?.network === 'bluesky' ? 'bluesky' : 'activitypub',
				post,
				parent: normalizeTimelineItem(data?.parent),
				replies: normalizePosts(data?.replies),
				profile: normalizeAccount(data?.profile),
			};
		},

		async remoteConnections(
			handle: string,
			type: 'followers' | 'following',
			cursor: string | null = null,
		): Promise<RemoteConnectionsData> {
			const query: Record<string, string> = { handle, type };
			if (cursor !== null && cursor !== '') {
				query.cursor = cursor;
			}

			const data = await request<{
				network?: unknown;
				profiles?: unknown;
				cursor?: unknown;
			}>('GET', '/api/v1/host/remote-connections', query);

			const profiles = Array.isArray(data?.profiles)
				? data.profiles
					.map(normalizeAccount)
					.filter((profile): profile is Account => profile !== null)
					.map((profile) => ({
						...profile,
						relationship: profile.relationship ?? {
							following: false,
							followed_by: false,
							blocking: false,
							muting: false,
						},
					}))
				: [];

			return {
				network: data?.network === 'bluesky' ? 'bluesky' : 'activitypub',
				profiles,
				cursor: typeof data?.cursor === 'string' && data.cursor !== '' ? data.cursor : null,
			};
		},

		async accountConnections(
			account: string,
			type: 'followers' | 'following',
			maxId: string | null = null,
		): Promise<AccountConnectionsData> {
			if (account === '') {
				return { profiles: [], cursor: null };
			}

			const query: Record<string, string> = { limit: '40' };
			if (maxId !== null && maxId !== '') {
				query.max_id = maxId;
			}

			const data = await request<unknown>(
				'GET',
				`/api/mastodon/v1/accounts/${encodeURIComponent(account)}/${type}`,
				query,
			);
			const rows = Array.isArray(data) ? data : [];
			const profiles = rows
				.map(normalizeMastodonAccount)
				.filter((profile): profile is Account => profile !== null);

			return {
				profiles,
				cursor: rows.length >= 40 && profiles.length > 0
					? profiles[profiles.length - 1].id
					: null,
			};
		},

		async remoteEngagement(
			id: string,
			type: 'likes' | 'reposts',
			cursor: string | null = null,
		): Promise<RemoteEngagementData> {
			if (id === '') {
				return { network: 'activitypub', type, profiles: [], cursor: null };
			}

			const query: Record<string, string> = { id, type };
			if (cursor !== null && cursor !== '') {
				query.cursor = cursor;
			}

			const data = await request<{
				network?: unknown;
				profiles?: unknown;
				cursor?: unknown;
			}>('GET', '/api/v1/host/remote-engagement', query);

			return {
				network: data?.network === 'bluesky' ? 'bluesky' : 'activitypub',
				type: data?.type === 'reposts' ? 'reposts' : type,
				profiles: Array.isArray(data?.profiles)
					? data.profiles
						.map(normalizeAccount)
						.filter((profile): profile is Account => profile !== null)
					: [],
				cursor: typeof data?.cursor === 'string' && data.cursor !== '' ? data.cursor : null,
			};
		},

		async remoteSearch(
			query: string,
			type: RemoteSearchType = 'all',
			network: RemoteSearchNetwork = 'all',
			cursor: string | null = null,
		): Promise<RemoteSearchData> {
			const parameters: Record<string, string> = { q: query, type, network };
			if (cursor !== null && cursor !== '') {
				parameters.cursor = cursor;
			}

			const data = await request<{
				query?: unknown;
				accounts?: unknown;
				posts?: unknown;
				cursor?: unknown;
				notes?: unknown;
			}>('GET', '/api/v1/host/search', parameters);

			const accounts = Array.isArray(data?.accounts)
				? data.accounts
					.map(normalizeAccount)
					.filter((account): account is Account => account !== null)
					.map((account) => ({
						...account,
						relationship: account.relationship ?? {
							following: false,
							followed_by: false,
							blocking: false,
							muting: false,
						},
					}))
				: [];
			const posts = Array.isArray(data?.posts)
				? data.posts
					.map(normalizeTimelineItem)
					.filter((post): post is TimelineItem => post !== null)
				: [];

			return {
				query: typeof data?.query === 'string' ? data.query : query,
				accounts,
				posts,
				cursor: typeof data?.cursor === 'string' && data.cursor !== '' ? data.cursor : null,
				notes: Array.isArray(data?.notes)
					? data.notes.filter((note): note is string => typeof note === 'string')
					: [],
			};
		},

		async followAccount(id: string): Promise<Relationship> {
			const data = await request<{ relationship?: unknown }>(
				'POST',
				`/api/v1/accounts/${encodeURIComponent(id)}/follow`,
			);
			return normalizeRelationship(data?.relationship);
		},

		async unfollowAccount(id: string): Promise<Relationship> {
			const data = await request<{ relationship?: unknown }>(
				'DELETE',
				`/api/v1/accounts/${encodeURIComponent(id)}/follow`,
			);
			return normalizeRelationship(data?.relationship);
		},

		async pinnedPosts(account?: string): Promise<string[]> {
			const query: Record<string, string> = {};
			if (account !== undefined && account !== '') {
				query.account = account;
			}

			const data = await request<{ pins?: unknown }>('GET', '/api/v1/host/pinned', query);

			return Array.isArray(data?.pins)
				? data.pins.filter((id): id is string => typeof id === 'string')
				: [];
		},

		async pinPost(postId: string): Promise<string[]> {
			const data = await request<{ pin?: unknown }>(
				'POST',
				'/api/v1/host/pinned',
				undefined,
				{ post_id: postId },
			);

			const pins = isRecord(data?.pin) && Array.isArray(data.pin.pins) ? data.pin.pins : [];

			return pins.filter((id): id is string => typeof id === 'string');
		},

		async unpinPost(postId: string): Promise<string[]> {
			const data = await request<{ pin?: unknown }>(
				'DELETE',
				`/api/v1/host/pinned/${encodeURIComponent(postId)}`,
			);

			const pins = isRecord(data?.pin) && Array.isArray(data.pin.pins) ? data.pin.pins : [];

			return pins.filter((id): id is string => typeof id === 'string');
		},

		async followRequests(): Promise<FollowRequestData> {
			const data = await request<{ requests?: unknown; locked?: unknown }>(
				'GET',
				'/api/v1/host/follow-requests',
			);

			const requests = Array.isArray(data?.requests)
				? data.requests.filter(isRecord).map((entry): FollowRequest => ({
					id: typeof entry.id === 'string' ? entry.id : '',
					target_account_id: typeof entry.target_account_id === 'string' ? entry.target_account_id : '',
					actor_id: typeof entry.actor_id === 'string' ? entry.actor_id : '',
					actor_handle: typeof entry.actor_handle === 'string' ? entry.actor_handle : '',
					is_local: entry.is_local !== false,
					created_at: typeof entry.created_at === 'string' ? entry.created_at : '',
				}))
				: [];

			return { requests, locked: data?.locked === true };
		},

		async acceptFollowRequest(id: string): Promise<FollowRequest> {
			const data = await request<{ request?: unknown }>(
				'POST',
				`/api/v1/host/follow-requests/${encodeURIComponent(id)}/accept`,
			);
			const entry = isRecord(data?.request) ? data.request : {};

			return {
				id: typeof entry.id === 'string' ? entry.id : id,
				target_account_id: typeof entry.target_account_id === 'string' ? entry.target_account_id : '',
				actor_id: typeof entry.actor_id === 'string' ? entry.actor_id : '',
				actor_handle: typeof entry.actor_handle === 'string' ? entry.actor_handle : '',
				is_local: entry.is_local !== false,
				created_at: typeof entry.created_at === 'string' ? entry.created_at : '',
			};
		},

		async rejectFollowRequest(id: string): Promise<FollowRequest> {
			const data = await request<{ request?: unknown }>(
				'POST',
				`/api/v1/host/follow-requests/${encodeURIComponent(id)}/reject`,
			);
			const entry = isRecord(data?.request) ? data.request : {};

			return {
				id: typeof entry.id === 'string' ? entry.id : id,
				target_account_id: typeof entry.target_account_id === 'string' ? entry.target_account_id : '',
				actor_id: typeof entry.actor_id === 'string' ? entry.actor_id : '',
				actor_handle: typeof entry.actor_handle === 'string' ? entry.actor_handle : '',
				is_local: entry.is_local !== false,
				created_at: typeof entry.created_at === 'string' ? entry.created_at : '',
			};
		},

		async notifications(limit = 20, before = null): Promise<NotificationPageData> {
			const query: Record<string, string> = { limit: String(limit) };
			if (before !== null && before !== '') {
				query.before = before;
			}

			const data = await request<{ notifications?: unknown; unread_count?: unknown }>(
				'GET',
				'/api/v1/notifications',
				query,
			);
			const notifications = Array.isArray(data?.notifications)
				? data.notifications
					.filter(isRecord)
					.map((item) => ({
						id: String(item.id ?? ''),
						type: item.type as NotificationPageData['notifications'][number]['type'],
						actor_id: String(item.actor_id ?? ''),
						post_id: typeof item.post_id === 'string' ? item.post_id : null,
						created_at: String(item.created_at ?? ''),
						read: item.read === true,
					}))
					.filter((item) => item.id !== '' && item.actor_id !== '')
				: [];

			return {
				notifications,
				unread_count: typeof data?.unread_count === 'number' ? data.unread_count : 0,
			};
		},

		async notificationRequests(): Promise<NotificationSenderRequest[]> {
			const data = await request<{ requests?: unknown }>('GET', '/api/v1/notifications/requests');
			return Array.isArray(data?.requests)
				? data.requests.filter(isRecord).map((entry) => ({
					id: String(entry.id ?? ''),
					actor_id: String(entry.actor_id ?? ''),
					status: String(entry.status ?? 'held') as NotificationSenderRequest['status'],
					category: String(entry.category ?? 'stranger') as NotificationSenderRequest['category'],
					created_at: String(entry.created_at ?? ''),
					reviewed_at: typeof entry.reviewed_at === 'string' ? entry.reviewed_at : null,
					note: typeof entry.note === 'string' ? entry.note : null,
				})).filter((entry) => entry.id !== '' && entry.actor_id !== '')
				: [];
		},

		async acceptNotificationSender(id: string): Promise<number> {
			const data = await request<{ released?: unknown }>(
				'POST',
				`/api/v1/notifications/requests/${encodeURIComponent(id)}/accept`,
			);
			return typeof data?.released === 'number' ? data.released : 0;
		},

		async dismissNotificationSender(id: string): Promise<number> {
			const data = await request<{ suppressed?: unknown }>(
				'POST',
				`/api/v1/notifications/requests/${encodeURIComponent(id)}/dismiss`,
			);
			return typeof data?.suppressed === 'number' ? data.suppressed : 0;
		},

		async notificationPolicy(): Promise<NotificationPolicy> {
			const data = await request<{ policy?: NotificationPolicy }>('GET', '/api/v1/notifications/policy');
			if (data?.policy === undefined) {
				throw new ApiError(502, 'invalid_response', 'Notification policy is not available.');
			}
			return data.policy;
		},

		async updateNotificationPolicy(payload): Promise<NotificationPolicy> {
			const data = await request<{ policy?: NotificationPolicy }>(
				'PATCH',
				'/api/v1/notifications/policy',
				undefined,
				payload,
			);
			if (data?.policy === undefined) {
				throw new ApiError(502, 'invalid_response', 'Notification policy could not be saved.');
			}
			return data.policy;
		},

		async toggleConversationMute(postId: string): Promise<boolean> {
			const path = `/api/v1/conversations/${encodeURIComponent(postId)}/mute`;
			const status = await request<{ muted?: unknown }>('GET', path);
			if (status?.muted === true) {
				await request<unknown>('DELETE', path);
				return false;
			}
			await request<unknown>('POST', path);
			return true;
		},

		async unreadCount(): Promise<number> {
			const data = await request<{ count?: unknown }>('GET', '/api/v1/notifications/unread_count');
			return typeof data?.count === 'number' ? data.count : 0;
		},

		async mastodonNotifications(limit = 20, maxId: string | null = null): Promise<NotificationItem[]> {
			const query: Record<string, string> = { limit: String(limit) };
			if (maxId !== null && maxId !== '') {
				query.max_id = maxId;
			}

			const data = await request<unknown>('GET', '/api/mastodon/v1/notifications', query);
			if (!Array.isArray(data)) {
				return [];
			}

			return data
				.map(normalizeMastodonNotification)
				.filter((item): item is NotificationItem => item !== null);
		},

		async dismissNotification(id: string): Promise<void> {
			await request<unknown>('POST', `/api/v1/notifications/${encodeURIComponent(id)}/dismiss`);
		},

		async clearNotifications(): Promise<number> {
			const data = await request<{ cleared?: unknown }>('POST', '/api/v1/notifications/clear');
			return typeof data?.cleared === 'number' ? data.cleared : 0;
		},

		async createPost(payload: CreatePostPayload): Promise<TimelineItem> {
			const body: JsonRecord = {
				content: payload.content,
				visibility: payload.visibility,
			};

			if (payload.content_warning !== undefined && payload.content_warning !== null && payload.content_warning.trim() !== '') {
				body.content_warning = payload.content_warning;
			}
			if (payload.in_reply_to_id !== undefined && payload.in_reply_to_id !== null && payload.in_reply_to_id !== '') {
				body.in_reply_to_id = payload.in_reply_to_id;
			}
			if (payload.quoted_post_id !== undefined && payload.quoted_post_id !== null && payload.quoted_post_id !== '') {
				body.quoted_post_id = payload.quoted_post_id;
			}
			if (payload.attachment_ids !== undefined && payload.attachment_ids.length > 0) {
				body.attachment_ids = payload.attachment_ids;
			}
			if (payload.recipient_ids !== undefined && payload.recipient_ids.length > 0) {
				body.recipient_ids = payload.recipient_ids;
			}

			const data = await request<{ post?: unknown }>('POST', '/api/v1/posts', undefined, body);
			const post = normalizeTimelineItem(data?.post);
			if (post === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a post.');
			}

			return post;
		},

		async createStatus(payload: CreateStatusPayload): Promise<TimelineItem> {
			const body: JsonRecord = {
				status: payload.status,
				visibility: payload.visibility === 'followers' ? 'private' : payload.visibility,
			};

			if (payload.spoiler_text !== undefined && payload.spoiler_text !== null && payload.spoiler_text.trim() !== '') {
				body.spoiler_text = payload.spoiler_text;
			}
			if (payload.media_ids !== undefined && payload.media_ids.length > 0) {
				body.media_ids = payload.media_ids;
			}
			if (payload.in_reply_to_id !== undefined && payload.in_reply_to_id !== null && payload.in_reply_to_id !== '') {
				body.in_reply_to_id = payload.in_reply_to_id;
			}
			if (payload.recipient_ids !== undefined && payload.recipient_ids.length > 0) {
				body.recipient_ids = payload.recipient_ids;
			}
			if (payload.poll !== undefined && payload.poll !== null) {
				body.poll = payload.poll;
			}

			const data = await request<unknown>('POST', '/api/mastodon/v1/statuses', undefined, body);
			const post = normalizeMastodonStatus(data);
			if (post === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a post.');
			}

			return post;
		},

		async bookmarks(limit = 20, maxId: string | null = null): Promise<TimelineItem[]> {
			const query: Record<string, string> = { limit: String(limit) };
			if (maxId !== null && maxId !== '') {
				query.max_id = maxId;
			}

			const data = await request<unknown>('GET', '/api/mastodon/v1/bookmarks', query);
			return normalizeMastodonStatuses(data);
		},

		async bookmarkPost(id: string): Promise<void> {
			await request<unknown>('POST', `/api/mastodon/v1/statuses/${encodeURIComponent(id)}/bookmark`);
		},

		async unbookmarkPost(id: string): Promise<void> {
			await request<unknown>('POST', `/api/mastodon/v1/statuses/${encodeURIComponent(id)}/unbookmark`);
		},

		async search(query: string, limit = 20): Promise<SearchResults> {
			const data = await request<{
				accounts?: unknown;
				statuses?: unknown;
				hashtags?: unknown;
			}>('GET', '/api/mastodon/v1/search', { q: query, limit: String(limit) });

			const hashtags: HashtagResult[] = Array.isArray(data?.hashtags)
				? data.hashtags
					.filter(isRecord)
					.map((tag) => ({
						name: typeof tag.name === 'string' ? tag.name : '',
						url: typeof tag.url === 'string' ? tag.url : '',
					}))
					.filter((tag) => tag.name !== '')
				: [];

			return {
				accounts: Array.isArray(data?.accounts)
					? data.accounts
						.map(normalizeMastodonAccount)
						.filter((account): account is Account => account !== null)
					: [],
				statuses: normalizeMastodonStatuses(data?.statuses),
				hashtags,
			};
		},

		async lists(): Promise<AccountList[]> {
			const data = await request<unknown>('GET', '/api/mastodon/v1/lists');
			if (!Array.isArray(data)) {
				return [];
			}

			return data
				.map(normalizeAccountList)
				.filter((list): list is AccountList => list !== null);
		},

		async createList(title: string): Promise<AccountList> {
			const data = await request<unknown>('POST', '/api/mastodon/v1/lists', undefined, { title });
			const list = normalizeAccountList(data);
			if (list === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a list.');
			}

			return list;
		},

		async updateList(id: string, title: string): Promise<AccountList> {
			const data = await request<unknown>(
				'PUT',
				`/api/mastodon/v1/lists/${encodeURIComponent(id)}`,
				undefined,
				{ title },
			);
			const list = normalizeAccountList(data);
			if (list === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a list.');
			}

			return list;
		},

		async deleteList(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/mastodon/v1/lists/${encodeURIComponent(id)}`);
		},

		async listAccounts(id: string): Promise<Account[]> {
			const data = await request<unknown>(
				'GET',
				`/api/mastodon/v1/lists/${encodeURIComponent(id)}/accounts`,
				{ limit: '80' },
			);
			if (!Array.isArray(data)) {
				return [];
			}

			return data
				.map(normalizeMastodonAccount)
				.filter((account): account is Account => account !== null);
		},

		async addListAccounts(id: string, accountIds: string[]): Promise<void> {
			await request<unknown>(
				'POST',
				`/api/mastodon/v1/lists/${encodeURIComponent(id)}/accounts`,
				undefined,
				{ account_ids: accountIds },
			);
		},

		async removeListAccounts(id: string, accountIds: string[]): Promise<void> {
			await request<unknown>(
				'DELETE',
				`/api/mastodon/v1/lists/${encodeURIComponent(id)}/accounts`,
				undefined,
				{ account_ids: accountIds },
			);
		},

		async mutes(): Promise<Account[]> {
			const data = await request<unknown>('GET', '/api/mastodon/v1/mutes', { limit: '80' });
			if (!Array.isArray(data)) {
				return [];
			}

			return data
				.map(normalizeMastodonAccount)
				.filter((account): account is Account => account !== null);
		},

		async muteAccount(id: string): Promise<void> {
			await request<unknown>('POST', `/api/mastodon/v1/accounts/${encodeURIComponent(id)}/mute`, undefined, {
				notifications: true,
			});
		},

		async unmuteAccount(id: string): Promise<void> {
			await request<unknown>('POST', `/api/mastodon/v1/accounts/${encodeURIComponent(id)}/unmute`);
		},

		async blocks(): Promise<BlockEntry[]> {
			const data = await request<{ blocks?: unknown }>('GET', '/api/v1/blocks', { limit: '80' });
			if (!Array.isArray(data?.blocks)) {
				return [];
			}

			return data.blocks
				.map(normalizeBlock)
				.filter((block): block is BlockEntry => block !== null);
		},

		async blockActor(actor: string): Promise<void> {
			await request<unknown>('POST', '/api/v1/blocks', undefined, { actor });
		},

		async unblockActor(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/blocks/${encodeURIComponent(id)}`);
		},

		async filters(): Promise<FilterRule[]> {
			const data = await request<{ filters?: unknown }>('GET', '/api/v1/filters');
			if (!Array.isArray(data?.filters)) {
				return [];
			}

			return data.filters
				.map(normalizeFilter)
				.filter((filter): filter is FilterRule => filter !== null);
		},

		async createFilter(payload: FilterPayload): Promise<FilterRule> {
			const data = await request<{ filter?: unknown }>('POST', '/api/v1/filters', undefined, payload);
			const filter = normalizeFilter(data?.filter);
			if (filter === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a filter.');
			}

			return filter;
		},

		async updateFilter(id: string, payload: FilterPayload): Promise<FilterRule> {
			const data = await request<{ filter?: unknown }>(
				'PATCH',
				`/api/v1/filters/${encodeURIComponent(id)}`,
				undefined,
				payload,
			);
			const filter = normalizeFilter(data?.filter);
			if (filter === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a filter.');
			}

			return filter;
		},

		async deleteFilter(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/filters/${encodeURIComponent(id)}`);
		},

		async scheduledPosts(): Promise<ScheduledPost[]> {
			const data = await request<{ scheduled_posts?: unknown }>('GET', '/api/v1/scheduled-posts', {
				limit: '80',
			});
			if (!Array.isArray(data?.scheduled_posts)) {
				return [];
			}

			return data.scheduled_posts
				.map(normalizeScheduledPost)
				.filter((post): post is ScheduledPost => post !== null);
		},

		async createScheduledPost(payload: ScheduledPostPayload): Promise<ScheduledPost> {
			const data = await request<{ scheduled_post?: unknown }>(
				'POST',
				'/api/v1/scheduled-posts',
				undefined,
				payload,
			);
			const scheduled = normalizeScheduledPost(data?.scheduled_post);
			if (scheduled === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a scheduled post.');
			}

			return scheduled;
		},

		async updateScheduledPost(id: string, scheduledAt: string): Promise<ScheduledPost> {
			const data = await request<{ scheduled_post?: unknown }>(
				'PATCH',
				`/api/v1/scheduled-posts/${encodeURIComponent(id)}`,
				undefined,
				{ scheduled_at: scheduledAt },
			);
			const scheduled = normalizeScheduledPost(data?.scheduled_post);
			if (scheduled === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a scheduled post.');
			}

			return scheduled;
		},

		async deleteScheduledPost(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/scheduled-posts/${encodeURIComponent(id)}`);
		},

		async poll(postId: string): Promise<Poll | null> {
			const data = await request<unknown>(
				'GET',
				`/api/mastodon/v1/polls/${encodeURIComponent(`poll-${postId}`)}`,
			);
			return normalizeMastodonPoll(data);
		},

		async votePoll(pollId: string, choices: number[]): Promise<Poll | null> {
			const data = await request<unknown>(
				'POST',
				`/api/mastodon/v1/polls/${encodeURIComponent(pollId)}/votes`,
				undefined,
				{ choices },
			);
			return normalizeMastodonPoll(data);
		},

		async updatePost(id: string, payload: UpdatePostPayload): Promise<TimelineItem> {
			const body: JsonRecord = {
				content: payload.content,
				visibility: payload.visibility,
				content_warning: payload.content_warning,
				attachment_ids: payload.attachment_ids ?? [],
			};
			if (payload.revision !== undefined) {
				body.revision = payload.revision;
			}

			const data = await request<{ post?: unknown }>(
				'PATCH',
				`/api/v1/posts/${encodeURIComponent(id)}`,
				undefined,
				body,
			);
			const post = normalizeTimelineItem(data?.post);
			if (post === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a post.');
			}

			return post;
		},

		async deletePost(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}`);
		},

		async boostPost(id: string): Promise<AtprotoMirrorStatus | null> {
			const data = await request<{ atproto_mirror?: unknown }>('POST', `/api/v1/posts/${encodeURIComponent(id)}/boost`);
			return normalizeInteraction({ atproto_mirror: data?.atproto_mirror })?.atproto_mirror ?? null;
		},

		async unboostPost(id: string): Promise<AtprotoMirrorStatus | null> {
			const data = await request<{ atproto_mirror?: unknown }>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}/boost`);
			return normalizeInteraction({ atproto_mirror: data?.atproto_mirror })?.atproto_mirror ?? null;
		},

		async interactions(ids: string[]): Promise<Record<string, PostInteraction>> {
			const unique = [...new Set(ids.filter((id) => id !== ''))].slice(0, 80);
			if (unique.length === 0) {
				return {};
			}

			const data = await request<{ interactions?: unknown }>(
				'GET',
				'/api/v1/host/interactions',
				{ ids: unique.join(',') },
			);
			const result: Record<string, PostInteraction> = {};
			if (isRecord(data?.interactions)) {
				for (const [id, value] of Object.entries(data.interactions)) {
					const interaction = normalizeInteraction(value);
					if (interaction !== null) {
						result[id] = interaction;
					}
				}
			}

			return result;
		},

		async likePost(id: string): Promise<PostInteraction> {
			const data = await request<{ interaction?: unknown }>('POST', `/api/v1/posts/${encodeURIComponent(id)}/like`);
			const interaction = normalizeInteraction(data?.interaction);
			if (interaction === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a like status.');
			}

			return interaction;
		},

		async unlikePost(id: string): Promise<PostInteraction> {
			const data = await request<{ interaction?: unknown }>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}/like`);
			const interaction = normalizeInteraction(data?.interaction);
			if (interaction === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a like status.');
			}

			return interaction;
		},

		async updateProfile(displayName: string): Promise<string> {
			const data = await request<{ account?: unknown }>(
				'PATCH',
				'/api/v1/host/profile',
				undefined,
				{ display_name: displayName },
			);
			if (!isRecord(data?.account) || typeof data.account.display_name !== 'string') {
				throw new ApiError(502, 'invalid_response', 'The response does not contain an updated profile.');
			}

			return data.account.display_name;
		},

		async announcements(all = false): Promise<Announcement[]> {
			const data = await request<{ announcements?: unknown }>(
				'GET',
				'/api/v1/host/announcements',
				all ? { all: '1' } : undefined,
			);

			return Array.isArray(data?.announcements)
				? data.announcements.filter(isRecord).map((row) => ({
					id: String(row.id ?? ''),
					author_id: String(row.author_id ?? ''),
					content: String(row.content ?? ''),
					active: row.active === true,
					created_at: String(row.created_at ?? ''),
					updated_at: String(row.updated_at ?? ''),
					expires_at: typeof row.expires_at === 'string' ? row.expires_at : null,
					expired: row.expired === true,
				})).filter((row) => row.id !== '')
				: [];
		},

		async createAnnouncement(content: string, expiresAt: string | null = null): Promise<Announcement> {
			const data = await request<{ announcement?: unknown }>(
				'POST',
				'/api/v1/host/announcements',
				undefined,
				{ content, expires_at: expiresAt },
			);
			const row = isRecord(data?.announcement) ? data.announcement : null;
			if (row === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain an announcement.');
			}

			return {
				id: String(row.id ?? ''),
				author_id: String(row.author_id ?? ''),
				content: String(row.content ?? ''),
				active: row.active === true,
				created_at: String(row.created_at ?? ''),
				updated_at: String(row.updated_at ?? ''),
				expires_at: typeof row.expires_at === 'string' ? row.expires_at : null,
				expired: row.expired === true,
			};
		},

		async updateAnnouncement(
			id: string,
			payload: { content?: string; active?: boolean; expires_at?: string | null },
		): Promise<Announcement> {
			const data = await request<{ announcement?: unknown }>(
				'PATCH',
				`/api/v1/host/announcements/${encodeURIComponent(id)}`,
				undefined,
				payload,
			);
			const row = isRecord(data?.announcement) ? data.announcement : null;
			if (row === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain an announcement.');
			}

			return {
				id: String(row.id ?? ''),
				author_id: String(row.author_id ?? ''),
				content: String(row.content ?? ''),
				active: row.active === true,
				created_at: String(row.created_at ?? ''),
				updated_at: String(row.updated_at ?? ''),
				expires_at: typeof row.expires_at === 'string' ? row.expires_at : null,
				expired: row.expired === true,
			};
		},

		async deleteAnnouncement(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/announcements/${encodeURIComponent(id)}`);
		},

		async featuredTags(account?: string): Promise<FeaturedTag[]> {
			const data = await request<{ tags?: unknown }>(
				'GET',
				'/api/v1/host/featured-tags',
				account === undefined ? undefined : { account },
			);

			return Array.isArray(data?.tags)
				? data.tags.filter(isRecord).map((tag) => ({
					tag: String(tag.tag ?? ''),
					position: typeof tag.position === 'number' ? tag.position : 0,
				})).filter((tag) => tag.tag !== '')
				: [];
		},

		async setFeaturedTags(tags: string[]): Promise<FeaturedTag[]> {
			const data = await request<{ tags?: unknown }>(
				'PUT',
				'/api/v1/host/featured-tags',
				undefined,
				{ tags },
			);

			return Array.isArray(data?.tags)
				? data.tags.filter(isRecord).map((tag) => ({
					tag: String(tag.tag ?? ''),
					position: typeof tag.position === 'number' ? tag.position : 0,
				})).filter((tag) => tag.tag !== '')
				: [];
		},

		async trends(days = 7, limit = 10): Promise<TrendsData> {
			const data = await request<{ tags?: unknown; links?: unknown }>(
				'GET',
				'/api/v1/host/trends',
				{ days: String(days), limit: String(limit) },
			);

			return {
				tags: Array.isArray(data?.tags)
					? data.tags.filter(isRecord).map((tag) => ({
						tag: String(tag.tag ?? ''),
						posts: typeof tag.posts === 'number' ? tag.posts : 0,
						accounts: typeof tag.accounts === 'number' ? tag.accounts : 0,
					})).filter((tag) => tag.tag !== '')
					: [],
				links: Array.isArray(data?.links)
					? data.links.filter(isRecord).map((link) => ({
						url: String(link.url ?? ''),
						host: String(link.host ?? ''),
						posts: typeof link.posts === 'number' ? link.posts : 0,
					})).filter((link) => link.url !== '')
					: [],
			};
		},

		async memories(): Promise<MemoriesData> {
			const data = await request<{ date?: unknown; total?: unknown; years?: unknown }>(
				'GET',
				'/api/v1/host/memories',
			);

			return {
				date: String(data?.date ?? ''),
				total: typeof data?.total === 'number' ? data.total : 0,
				years: Array.isArray(data?.years)
					? data.years.filter(isRecord).map((year) => ({
						year: typeof year.year === 'number' ? year.year : 0,
						posts: Array.isArray(year.posts)
							? year.posts.filter(isRecord).map((post) => ({
								id: String(post.id ?? ''),
								published_at: String(post.published_at ?? ''),
							})).filter((post) => post.id !== '')
							: [],
					}))
					: [],
			};
		},

		async stories(): Promise<StoriesData> {
			const data = await request<{ stories?: unknown; groups?: unknown }>('GET', '/api/v1/host/stories');
			const story = (row: Record<string, unknown>): Story => ({
				id: String(row.id ?? ''),
				account_id: String(row.account_id ?? ''),
				author_handle: String(row.author_handle ?? ''),
				author_name: String(row.author_name ?? ''),
				seen: row.seen === true,
				content: String(row.content ?? ''),
				background: String(row.background ?? 'ocean'),
				created_at: String(row.created_at ?? ''),
				expires_at: String(row.expires_at ?? ''),
			});

			return {
				stories: Array.isArray(data?.stories)
					? data.stories.filter(isRecord).map(story).filter((item) => item.id !== '')
					: [],
				groups: Array.isArray(data?.groups)
					? data.groups.filter(isRecord).map((group) => ({
						account_id: String(group.account_id ?? ''),
						handle: String(group.handle ?? ''),
						name: String(group.name ?? ''),
						unseen: group.unseen === true,
						stories: Array.isArray(group.stories)
							? group.stories.filter(isRecord).map(story).filter((item) => item.id !== '')
							: [],
					}))
					: [],
			};
		},

		async createStory(content: string, background = 'ocean'): Promise<Story> {
			const data = await request<{ story?: unknown }>(
				'POST',
				'/api/v1/host/stories',
				undefined,
				{ content, background },
			);
			const row = isRecord(data?.story) ? data.story : null;
			if (row === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a story.');
			}

			return {
				id: String(row.id ?? ''),
				account_id: String(row.account_id ?? ''),
				content: String(row.content ?? ''),
				background: String(row.background ?? 'ocean'),
				created_at: String(row.created_at ?? ''),
				expires_at: String(row.expires_at ?? ''),
			};
		},

		async deleteStory(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/stories/${encodeURIComponent(id)}`);
		},

		async markStoriesSeen(ids: string[]): Promise<number> {
			const unique = [...new Set(ids.filter((id) => id !== ''))].slice(0, 100);
			if (unique.length === 0) {
				return 0;
			}

			const data = await request<{ seen?: unknown }>(
				'POST',
				'/api/v1/host/stories/seen',
				undefined,
				{ ids: unique },
			);

			return typeof data?.seen === 'number' ? data.seen : 0;
		},

		async collections(account?: string): Promise<Collection[]> {
			const data = await request<{ collections?: unknown }>(
				'GET',
				'/api/v1/host/collections',
				account === undefined ? undefined : { account },
			);

			return Array.isArray(data?.collections)
				? data.collections.map(normalizeCollection).filter((item): item is Collection => item !== null)
				: [];
		},

		async collection(id: string): Promise<Collection> {
			const data = await request<{ collection?: unknown }>(
				'GET',
				'/api/v1/host/collection',
				{ id },
			);
			const collection = normalizeCollection(data?.collection);
			if (collection === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a collection.');
			}

			return collection;
		},

		async createCollection(title: string, description = '', isPublic = true): Promise<Collection> {
			const data = await request<{ collection?: unknown }>(
				'POST',
				'/api/v1/host/collections',
				undefined,
				{ title, description, is_public: isPublic },
			);
			const collection = normalizeCollection(data?.collection);
			if (collection === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a collection.');
			}

			return collection;
		},

		async updateCollection(
			id: string,
			payload: { title?: string; description?: string; is_public?: boolean },
		): Promise<Collection> {
			const data = await request<{ collection?: unknown }>(
				'PATCH',
				`/api/v1/host/collections/${encodeURIComponent(id)}`,
				undefined,
				payload,
			);
			const collection = normalizeCollection(data?.collection);
			if (collection === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a collection.');
			}

			return collection;
		},

		async deleteCollection(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/collections/${encodeURIComponent(id)}`);
		},

		async addCollectionItem(id: string, postId: string): Promise<Collection> {
			const data = await request<{ collection?: unknown }>(
				'POST',
				`/api/v1/host/collections/${encodeURIComponent(id)}/items`,
				undefined,
				{ post_id: postId },
			);
			const collection = normalizeCollection(data?.collection);
			if (collection === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a collection.');
			}

			return collection;
		},

		async removeCollectionItem(id: string, postId: string): Promise<Collection> {
			const data = await request<{ collection?: unknown }>(
				'DELETE',
				`/api/v1/host/collections/${encodeURIComponent(id)}/items/${encodeURIComponent(postId)}`,
			);
			const collection = normalizeCollection(data?.collection);
			if (collection === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a collection.');
			}

			return collection;
		},

		async kokua(params: { kind?: string; category?: string; state?: string; limit?: number; offset?: number } = {}): Promise<KokuaPage> {
			const query: Record<string, string> = {};
			if (params.kind !== undefined && params.kind !== '') {
				query.kind = params.kind;
			}
			if (params.category !== undefined && params.category !== '') {
				query.category = params.category;
			}
			if (params.state !== undefined && params.state !== '') {
				query.state = params.state;
			}
			if (params.limit !== undefined) {
				query.limit = String(params.limit);
			}
			if (params.offset !== undefined) {
				query.offset = String(params.offset);
			}

			const data = await request<{ items?: unknown; total?: unknown; limit?: unknown; offset?: unknown; counts?: unknown }>(
				'GET',
				'/api/v1/host/kokua',
				query,
			);
			const counts = isRecord(data?.counts) ? data.counts : {};

			return {
				items: Array.isArray(data?.items)
					? data.items.map(normalizeKokua).filter((item): item is KokuaEntry => item !== null)
					: [],
				total: typeof data?.total === 'number' ? data.total : 0,
				limit: typeof data?.limit === 'number' ? data.limit : 30,
				offset: typeof data?.offset === 'number' ? data.offset : 0,
				counts: {
					open_requests: typeof counts.open_requests === 'number' ? counts.open_requests : 0,
					open_offers: typeof counts.open_offers === 'number' ? counts.open_offers : 0,
					done: typeof counts.done === 'number' ? counts.done : 0,
				},
			};
		},

		async createKokua(payload: KokuaCreatePayload): Promise<KokuaEntry> {
			const data = await request<{ kokua?: unknown }>('POST', '/api/v1/host/kokua', undefined, payload);
			const entry = normalizeKokua(data?.kokua);
			if (entry === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a Kokua.');
			}

			return entry;
		},

		async setKokuaStatus(id: string, status: KokuaStatus): Promise<KokuaEntry> {
			const data = await request<{ kokua?: unknown }>(
				'PATCH',
				`/api/v1/host/kokua/${encodeURIComponent(id)}`,
				undefined,
				{ status },
			);
			const entry = normalizeKokua(data?.kokua);
			if (entry === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a Kokua.');
			}

			return entry;
		},

		async deleteKokua(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/kokua/${encodeURIComponent(id)}`);
		},

		async helpKokua(id: string, message: string): Promise<KokuaEntry> {
			const data = await request<{ kokua?: unknown }>(
				'POST',
				`/api/v1/host/kokua/${encodeURIComponent(id)}/help`,
				undefined,
				{ message },
			);
			const entry = normalizeKokua(data?.kokua);
			if (entry === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a Kokua.');
			}

			return entry;
		},

		async withdrawKokuaHelp(id: string): Promise<KokuaEntry> {
			const data = await request<{ kokua?: unknown }>(
				'DELETE',
				`/api/v1/host/kokua/${encodeURIComponent(id)}/help`,
			);
			const entry = normalizeKokua(data?.kokua);
			if (entry === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a Kokua.');
			}

			return entry;
		},

		async postRevisions(id: string): Promise<PostRevisionData> {
			const data = await request<{
				post_id?: unknown;
				current_revision?: unknown;
				current?: unknown;
				revisions?: unknown;
			}>('GET', '/api/v1/host/post-revisions', { id });

			const current = isRecord(data?.current) ? data.current : {};
			const revisions = Array.isArray(data?.revisions)
				? data.revisions.filter(isRecord).map((revision) => ({
					post_id: String(revision.post_id ?? id),
					revision: typeof revision.revision === 'number' ? revision.revision : 0,
					content: String(revision.content ?? ''),
					content_warning: typeof revision.content_warning === 'string' ? revision.content_warning : null,
					visibility: String(revision.visibility ?? 'public'),
					attachment_ids: Array.isArray(revision.attachment_ids)
						? revision.attachment_ids.filter((value): value is string => typeof value === 'string')
						: [],
					edited_at: String(revision.edited_at ?? ''),
				}))
				: [];

			return {
				post_id: String(data?.post_id ?? id),
				current_revision: typeof data?.current_revision === 'number' ? data.current_revision : revisions.length + 1,
				current: {
					content: String(current.content ?? ''),
					content_warning: typeof current.content_warning === 'string' ? current.content_warning : null,
					visibility: String(current.visibility ?? 'public'),
					edited_at: typeof current.edited_at === 'string' ? current.edited_at : null,
				},
				revisions,
			};
		},

		async drafts(): Promise<Draft[]> {
			const data = await request<{ drafts?: unknown }>('GET', '/api/v1/host/drafts');

			return Array.isArray(data?.drafts)
				? data.drafts.map(normalizeDraft).filter((draft): draft is Draft => draft !== null)
				: [];
		},

		async saveDraft(payload: {
			context?: string;
			content: string;
			content_warning?: string | null;
			visibility?: string;
			attachment_ids?: string[];
			in_reply_to_id?: string | null;
			quoted_post_id?: string | null;
			poll?: { options: string[]; multiple?: boolean; hide_totals?: boolean; expires_in?: number } | null;
		}): Promise<Draft> {
			const data = await request<{ draft?: unknown }>(
				'POST',
				'/api/v1/host/drafts',
				undefined,
				payload,
			);
			const draft = normalizeDraft(data?.draft);
			if (draft === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain a draft.');
			}

			return draft;
		},

		async deleteDraft(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/drafts/${encodeURIComponent(id)}`);
		},

		async archivedPosts(): Promise<ArchivedPost[]> {
			const data = await request<{ archived?: unknown }>('GET', '/api/v1/host/archived');

			return Array.isArray(data?.archived)
				? data.archived.filter(isRecord).map((row) => ({
					post_id: String(row.post_id ?? ''),
					archived_at: String(row.archived_at ?? ''),
				})).filter((row) => row.post_id !== '')
				: [];
		},

		async archivePost(postId: string): Promise<ArchivedPost> {
			const data = await request<{ archived?: unknown }>(
				'POST',
				'/api/v1/host/archived',
				undefined,
				{ post_id: postId },
			);
			const row = isRecord(data?.archived) ? data.archived : null;
			if (row === null) {
				throw new ApiError(502, 'invalid_response', 'The response does not contain an archive entry.');
			}

			return {
				post_id: String(row.post_id ?? postId),
				archived_at: String(row.archived_at ?? ''),
			};
		},

		async unarchivePost(postId: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/archived/${encodeURIComponent(postId)}`);
		},

		async forYou(limit = 20, cursorOrOffset: string | number | null = null): Promise<ForYouData> {
			const query: Record<string, string> = { limit: String(limit) };
			if (typeof cursorOrOffset === 'number') {
				query.offset = String(cursorOrOffset);
			} else if (cursorOrOffset !== null) {
				query.cursor = cursorOrOffset;
			}
			const data = await request<{
				posts?: unknown;
				reasons?: unknown;
				next?: unknown;
				total?: unknown;
				interests?: unknown;
			}>('GET', '/api/v1/host/for-you', query);

			const reasons: Record<string, string> = {};
			if (isRecord(data?.reasons)) {
				for (const [key, value] of Object.entries(data.reasons)) {
					if (typeof value === 'string') {
						reasons[key] = value;
					}
				}
			}

			const interests: ForYouData['interests'] = [];
			if (Array.isArray(data?.interests)) {
				for (const entry of data.interests) {
					if (!isRecord(entry) || typeof entry.key !== 'string' || entry.key === '') {
						continue;
					}
					if (entry.kind !== 'tag' && entry.kind !== 'author') {
						continue;
					}
					interests.push({
						kind: entry.kind,
						key: entry.key,
						weight: typeof entry.weight === 'number' ? entry.weight : 0,
					});
				}
			}

			return {
				posts: Array.isArray(data?.posts)
					? data.posts.filter((id): id is string => typeof id === 'string')
					: [],
				reasons,
				next: typeof data?.next === 'number' || typeof data?.next === 'string' ? data.next : null,
				total: typeof data?.total === 'number' ? data.total : 0,
				interests,
			};
		},

		async trending(limit = 20, cursorOrOffset: string | number | null = null): Promise<ForYouData> {
			const query: Record<string, string> = { limit: String(limit) };
			if (typeof cursorOrOffset === 'number') {
				query.offset = String(cursorOrOffset);
			} else if (cursorOrOffset !== null) {
				query.cursor = cursorOrOffset;
			}
			const data = await request<{
				posts?: unknown;
				reasons?: unknown;
				next?: unknown;
				total?: unknown;
			}>('GET', '/api/v1/host/trending', query);

			const reasons: Record<string, string> = {};
			if (isRecord(data?.reasons)) {
				for (const [key, value] of Object.entries(data.reasons)) {
					if (typeof value === 'string') {
						reasons[key] = value;
					}
				}
			}

			return {
				posts: Array.isArray(data?.posts)
					? data.posts.filter((id): id is string => typeof id === 'string')
					: [],
				reasons,
				next: typeof data?.next === 'number' || typeof data?.next === 'string' ? data.next : null,
				total: typeof data?.total === 'number' ? data.total : 0,
			};
		},

		async preferences(): Promise<AccountPreferences> {
			const data = await request<{ preferences?: unknown }>('GET', '/api/v1/host/preferences');

			return normalizePreferences(data?.preferences);
		},

		async updatePreferences(payload: Partial<Omit<AccountPreferences, 'account_id' | 'updated_at'>>): Promise<AccountPreferences> {
			const data = await request<{ preferences?: unknown }>(
				'PATCH',
				'/api/v1/host/preferences',
				undefined,
				payload,
			);

			return normalizePreferences(data?.preferences);
		},

		async forYouFeedback(postId: string, kind: 'less' | 'more'): Promise<void> {
			await request<unknown>(
				'POST',
				'/api/v1/host/for-you/feedback',
				undefined,
				{ post_id: postId, kind },
			);
		},

		async forYouImpressions(items: { post_id: string; dwell_ms: number }[]): Promise<void> {
			if (items.length === 0) {
				return;
			}

			await request<unknown>('POST', '/api/v1/host/for-you/impressions', undefined, {
				impressions: items.slice(0, 60),
			});
		},

		async updates(since: string): Promise<{ cursor: string; created: string[]; deleted: string[]; engagement: RemoteEngagementUpdate[] }> {
			const data = await request<{ cursor?: unknown; created?: unknown; deleted?: unknown; engagement?: unknown }>(
				'GET',
				'/api/v1/host/updates',
				{ since },
			);

			const ids = (value: unknown): string[] => Array.isArray(value)
				? value.filter((id): id is string => typeof id === 'string' && id !== '')
				: [];
			const engagement: RemoteEngagementUpdate[] = Array.isArray(data?.engagement)
				? data.engagement
					.filter(isRecord)
					.map((entry) => ({
						id: typeof entry.id === 'string' ? entry.id : '',
						object_id: typeof entry.object_id === 'string' ? entry.object_id : '',
						likes_count: typeof entry.likes_count === 'number' ? entry.likes_count : 0,
						boosts_count: typeof entry.boosts_count === 'number' ? entry.boosts_count : 0,
						replies_count: typeof entry.replies_count === 'number' ? entry.replies_count : 0,
					}))
					.filter((entry) => entry.id !== '' && entry.object_id !== '')
				: [];

			return {
				cursor: typeof data?.cursor === 'string' && data.cursor !== '' ? data.cursor : new Date().toISOString(),
				created: ids(data?.created),
				deleted: ids(data?.deleted),
				engagement,
			};
		},

		async exportAccount(): Promise<Blob> {
			const response = await fetch('/api/v1/host/export', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			});
			if (!response.ok) {
				throw new ApiError(response.status, 'export_failed', 'The export could not be created.');
			}

			return response.blob();
		},

		async deleteAccount(): Promise<{ state: string }> {
			const data = await request<{ deletion?: { state?: unknown } }>(
				'DELETE',
				'/api/v1/account/data',
			);
			return { state: typeof data?.deletion?.state === 'string' ? data.deletion.state : 'unknown' };
		},

		async accountMove(): Promise<AccountMoveStatus | null> {
			const data = await request<{ move?: AccountMoveStatus | null }>('GET', '/api/v1/accounts/me/move');
			return data?.move ?? null;
		},

		async startAccountMove(targetActorId: string): Promise<AccountMoveStatus> {
			const data = await request<{ move?: AccountMoveStatus }>(
				'POST',
				'/api/v1/accounts/me/move',
				undefined,
				{ target_actor_id: targetActorId },
			);
			if (data?.move === undefined) {
				throw new ApiError(502, 'invalid_response', 'The account move could not be confirmed.');
			}
			return data.move;
		},

		async atprotoIdentity(): Promise<AtprotoIdentityData> {
			const data = await request<Record<string, unknown>>('GET', '/api/v1/host/atproto');
			const identity = isRecord(data?.identity) ? data.identity : null;

			return {
				enabled: data?.enabled === true,
				publish: data?.publish === true,
				handle_domain: typeof data?.handle_domain === 'string' ? data.handle_domain : '',
				pds_url: typeof data?.pds_url === 'string' ? data.pds_url : '',
				identity: identity === null ? null : {
					did: typeof identity.did === 'string' ? identity.did : '',
					handle: typeof identity.handle === 'string' ? identity.handle : '',
					pds_url: typeof identity.pds_url === 'string' ? identity.pds_url : '',
					created_at: typeof identity.created_at === 'string' ? identity.created_at : '',
					verification: isRecord(identity.verification) ? {
						dns_name: typeof identity.verification.dns_name === 'string' ? identity.verification.dns_name : '',
						dns_value: typeof identity.verification.dns_value === 'string' ? identity.verification.dns_value : '',
						well_known_url: typeof identity.verification.well_known_url === 'string' ? identity.verification.well_known_url : '',
					} : null,
				},
				external: normalizeBlueskyAccount(data?.external),
			};
		},

		async createAtprotoIdentity(handle = ''): Promise<{ did: string; handle: string }> {
			const data = await request<{ identity?: unknown }>(
				'POST',
				'/api/v1/host/atproto/identity',
				undefined,
				handle === '' ? {} : { handle },
			);
			const identity = isRecord(data?.identity) ? data.identity : {};

			return {
				did: typeof identity.did === 'string' ? identity.did : '',
				handle: typeof identity.handle === 'string' ? identity.handle : '',
			};
		},

		async rotateAtprotoKeys(): Promise<void> {
			await request<unknown>('POST', '/api/v1/host/atproto/identity/rotate');
		},

		async blueskyAccount(): Promise<BlueskyData> {
			const data = await request<{ account?: unknown; publish_enabled?: unknown }>(
				'GET',
				'/api/v1/host/bluesky',
			);

			return {
				account: normalizeBlueskyAccount(data?.account),
				publish_enabled: data?.publish_enabled !== false,
			};
		},

		async connectBluesky(handle: string, appPassword: string): Promise<BlueskyAccountStatus> {
			const data = await request<{ account?: unknown }>(
				'POST',
				'/api/v1/host/bluesky/connect',
				undefined,
				{ handle, app_password: appPassword },
			);

			return normalizeBlueskyAccount(data?.account);
		},

		async startBlueskyOAuth(handle: string): Promise<string> {
			const data = await request<{ authorization_url?: unknown }>(
				'POST', '/api/v1/host/bluesky/oauth', undefined, { handle },
			);
			if (typeof data?.authorization_url !== 'string') throw new ApiError(502, 'invalid_response', 'Bluesky authorization URL is missing.');
			return data.authorization_url;
		},

		async disconnectBluesky(): Promise<boolean> {
			const data = await request<{ disconnected?: unknown }>('DELETE', '/api/v1/host/bluesky');

			return data?.disconnected === true;
		},

		async publishToBluesky(postId: string, accountDid?: string): Promise<AtprotoPublicationStatus> {
			const data = await request<{ publication?: unknown }>(
				'POST',
				'/api/v1/host/bluesky/publish',
				undefined,
				{ post_id: postId, ...(accountDid === undefined ? {} : { account_did: accountDid }) },
			);

			const publication = normalizeAtprotoPublication(data?.publication);
			if (publication === null) {
				throw new ApiError(502, 'invalid_response', 'The Bluesky publication was not confirmed.');
			}

			return publication;
		},

		async atprotoPublication(postId: string, accountDid: string): Promise<AtprotoPublicationLookup> {
			const data = await request<Record<string, unknown>>(
				'GET',
				'/api/v1/host/bluesky/publication-status',
				{ post_id: postId, account_did: accountDid },
			);

			const state = data.state;
			if (state !== 'published' && state !== 'pending' && state !== 'failed' && state !== 'not_queued') {
				throw new ApiError(502, 'invalid_response', 'The Bluesky delivery status is invalid.');
			}

			const publication = data.publication === null ? null : normalizeAtprotoPublication(data.publication);
			if (data.publication !== null && publication === null) {
				throw new ApiError(502, 'invalid_response', 'The Bluesky publication was not confirmed.');
			}

			return {
				state,
				publication,
				attempts: typeof data.attempts === 'number' ? data.attempts : 0,
				retryable: data.retryable === true,
			};
		},

		async activityPubDelivery(postId: string): Promise<ActivityPubDeliveryLookup> {
			const data = await request<Record<string, unknown>>(
				'GET', `/api/v1/host/posts/${encodeURIComponent(postId)}/activitypub-delivery`,
			);
			return normalizeActivityPubDelivery(data);
		},

		async retryActivityPubDelivery(postId: string): Promise<ActivityPubDeliveryLookup & { retried: number }> {
			const data = await request<Record<string, unknown>>(
				'POST', `/api/v1/host/posts/${encodeURIComponent(postId)}/activitypub-delivery/retry`, undefined, {},
			);
			return { ...normalizeActivityPubDelivery(data), retried: typeof data.retried === 'number' ? data.retried : 0 };
		},

		async followedTags(): Promise<FollowedTag[]> {
			const data = await request<{ tags?: unknown }>('GET', '/api/v1/followed_tags');
			if (!Array.isArray(data?.tags)) {
				return [];
			}

			return data.tags
				.filter(isRecord)
				.map((tag) => ({
					tag: String(tag.tag ?? ''),
					created_at: String(tag.created_at ?? ''),
				}))
				.filter((tag) => tag.tag !== '');
		},

		async followTag(tag: string): Promise<void> {
			await request<unknown>('POST', '/api/v1/followed_tags', undefined, { tag });
		},

		async unfollowTag(tag: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/followed_tags/${encodeURIComponent(tag)}`);
		},

		uploadMedia,

		async logout(): Promise<void> {
			await bridge.logout();
		},
	};
}

let currentApi: Api | null = null;

export function configureApi(api: Api): void {
	currentApi = api;
}

export function getApi(): Api {
	if (currentApi === null) {
		throw new Error('The Aloha API has not been configured yet.');
	}

	return currentApi;
}
