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
	Poll,
	PostInteraction,
	Relationship,
	RemoteConnectionsData,
	RemoteEngagementData,
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

export interface Api {
	instance(): Promise<InstanceInfo>;
	publicTimeline(limit?: number, before?: string | null): Promise<TimelineItem[]>;
	homeTimeline(limit?: number, before?: string | null): Promise<TimelineItem[]>;
	tagTimeline(tag: string, limit?: number, before?: string | null): Promise<TimelineItem[]>;
	followedTimeline(limit?: number, before?: string | null): Promise<TimelineItem[]>;
	listTimeline(id: string, limit?: number, maxId?: string | null): Promise<TimelineItem[]>;
	post(id: string): Promise<TimelineItem>;
	posts(ids: string[]): Promise<Record<string, TimelineItem>>;
	quotedPosts(ids: string[]): Promise<Record<string, TimelineItem>>;
	account(id: string): Promise<Account>;
	remoteProfile(handle: string, cursor?: string | null): Promise<RemoteProfileData>;
	remotePost(id: string): Promise<RemotePostData>;
	remoteConnections(handle: string, type: 'followers' | 'following', cursor?: string | null): Promise<RemoteConnectionsData>;
	remoteEngagement(id: string, type: 'likes' | 'reposts', cursor?: string | null): Promise<RemoteEngagementData>;
	remoteSearch(query: string, type?: RemoteSearchType, network?: RemoteSearchNetwork, cursor?: string | null): Promise<RemoteSearchData>;
	followRemote(actor: string, inbox: string): Promise<void>;
	hostProfiles(): Promise<Record<string, string>>;
	followAccount(id: string): Promise<Relationship>;
	unfollowAccount(id: string): Promise<Relationship>;
	notifications(limit?: number, before?: string | null): Promise<NotificationPageData>;
	mastodonNotifications(limit?: number, maxId?: string | null): Promise<NotificationItem[]>;
	unreadCount(): Promise<number>;
	dismissNotification(id: string): Promise<void>;
	clearNotifications(): Promise<number>;
	createPost(payload: CreatePostPayload): Promise<TimelineItem>;
	createStatus(payload: CreateStatusPayload): Promise<TimelineItem>;
	updatePost(id: string, payload: UpdatePostPayload): Promise<TimelineItem>;
	deletePost(id: string): Promise<void>;
	boostPost(id: string): Promise<void>;
	unboostPost(id: string): Promise<void>;
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
	collections(account?: string): Promise<Collection[]>;
	collection(id: string): Promise<Collection>;
	createCollection(title: string, description?: string, isPublic?: boolean): Promise<Collection>;
	updateCollection(id: string, payload: { title?: string; description?: string; is_public?: boolean }): Promise<Collection>;
	deleteCollection(id: string): Promise<void>;
	addCollectionItem(id: string, postId: string): Promise<Collection>;
	removeCollectionItem(id: string, postId: string): Promise<Collection>;
	postRevisions(id: string): Promise<PostRevisionData>;
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
	forYou(limit?: number, offset?: number): Promise<ForYouData>;
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
	logout(): Promise<void>;
}

function isRecord(value: unknown): value is JsonRecord {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function errorMessage(body: string, status: number): { code: string; message: string; fields: Record<string, string> } {
	try {
		const parsed: unknown = JSON.parse(body);
		if (isRecord(parsed) && isRecord(parsed.error)) {
			const code = typeof parsed.error.code === 'string' ? parsed.error.code : 'http_error';
			const message = typeof parsed.error.message === 'string' && parsed.error.message !== ''
				? parsed.error.message
				: `Die Anfrage ist fehlgeschlagen (${status}).`;
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
			? 'Du bist nicht angemeldet.'
			: `Die Anfrage ist fehlgeschlagen (${status}).`,
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

function normalizeInteraction(value: unknown): PostInteraction | null {
	if (!isRecord(value)) {
		return null;
	}

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
	};
}

function normalizeRelationship(value: unknown): Relationship {
	if (!isRecord(value)) {
		return { following: false, followed_by: false, blocking: false, muting: false };
	}

	return {
		following: value.following === true,
		followed_by: value.followed_by === true,
		blocking: value.blocking === true,
		muting: value.muting === true,
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

function normalizePreferences(value: unknown): AccountPreferences {
	if (!isRecord(value)) {
		throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Einstellungen.');
	}

	return {
		account_id: typeof value.account_id === 'string' ? value.account_id : '',
		default_visibility: typeof value.default_visibility === 'string' ? value.default_visibility : 'public',
		default_quote_policy: typeof value.default_quote_policy === 'string' ? value.default_quote_policy : 'public',
		dm_policy: typeof value.dm_policy === 'string' ? value.dm_policy : 'everyone',
		show_boosts: value.show_boosts !== false,
		discoverable: value.discoverable !== false,
		notify_follows: value.notify_follows !== false,
		notify_favourites: value.notify_favourites !== false,
		notify_boosts: value.notify_boosts !== false,
		notify_mentions: value.notify_mentions !== false,
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

	async function timeline(
		path: string,
		limit: number,
		before?: string | null,
	): Promise<TimelineItem[]> {
		const query: Record<string, string> = { limit: String(limit) };
		if (before !== undefined && before !== null && before !== '') {
			query.before = before;
		}

		const data = await request<{ posts?: unknown }>('GET', path, query);
		return normalizePosts(data?.posts);
	}

	return {
		async instance(): Promise<InstanceInfo> {
			const data = await request<{ instance?: unknown }>('GET', '/api/v1/instance');
			if (!isRecord(data?.instance) || typeof data.instance.name !== 'string') {
				throw new ApiError(502, 'invalid_response', 'Die Instanzbeschreibung ist unvollständig.');
			}

			return data.instance as unknown as InstanceInfo;
		},

		publicTimeline(limit = 20, before = null): Promise<TimelineItem[]> {
			return timeline('/api/v1/timelines/public', limit, before);
		},

		homeTimeline(limit = 20, before = null): Promise<TimelineItem[]> {
			return timeline('/api/v1/timelines/home', limit, before);
		},

		tagTimeline(tag: string, limit = 20, before = null): Promise<TimelineItem[]> {
			return timeline(`/api/v1/timelines/tag/${encodeURIComponent(tag)}`, limit, before);
		},

		followedTimeline(limit = 20, before = null): Promise<TimelineItem[]> {
			return timeline('/api/v1/timelines/followed_tags', limit, before);
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Beitrag.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält kein Profil.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält kein entferntes Profil.');
			}

			profile.relationship = { following: false, followed_by: false, blocking: false, muting: false };

			return {
				kind: data?.kind === 'bluesky' ? 'bluesky' : 'activitypub',
				profile,
				posts: normalizePosts(data?.posts),
				cursor: typeof data?.cursor === 'string' && data.cursor !== '' ? data.cursor : null,
			};
		},

		async followRemote(actor: string, inbox: string): Promise<void> {
			await request<unknown>('POST', '/api/v1/following', undefined, { actor, inbox });
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Beitrag.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Beitrag.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Beitrag.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Liste.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Liste.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Filter.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Filter.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen geplanten Beitrag.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen geplanten Beitrag.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Beitrag.');
			}

			return post;
		},

		async deletePost(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}`);
		},

		async boostPost(id: string): Promise<void> {
			await request<unknown>('POST', `/api/v1/posts/${encodeURIComponent(id)}/boost`);
		},

		async unboostPost(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}/boost`);
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Like-Status.');
			}

			return interaction;
		},

		async unlikePost(id: string): Promise<PostInteraction> {
			const data = await request<{ interaction?: unknown }>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}/like`);
			const interaction = normalizeInteraction(data?.interaction);
			if (interaction === null) {
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Like-Status.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält kein aktualisiertes Profil.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Ankündigung.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Ankündigung.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Story.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Sammlung.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Sammlung.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Sammlung.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Sammlung.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keine Sammlung.');
			}

			return collection;
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Entwurf.');
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
				throw new ApiError(502, 'invalid_response', 'Die Antwort enthält keinen Archiv-Eintrag.');
			}

			return {
				post_id: String(row.post_id ?? postId),
				archived_at: String(row.archived_at ?? ''),
			};
		},

		async unarchivePost(postId: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/host/archived/${encodeURIComponent(postId)}`);
		},

		async forYou(limit = 20, offset = 0): Promise<ForYouData> {
			const data = await request<{
				posts?: unknown;
				reasons?: unknown;
				next?: unknown;
				total?: unknown;
			}>('GET', '/api/v1/host/for-you', {
				limit: String(limit),
				offset: String(offset),
			});

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
				next: typeof data?.next === 'number' ? data.next : null,
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

		async exportAccount(): Promise<Blob> {
			const response = await fetch('/api/v1/host/export', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			});
			if (!response.ok) {
				throw new ApiError(response.status, 'export_failed', 'Der Export konnte nicht erstellt werden.');
			}

			return response.blob();
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
