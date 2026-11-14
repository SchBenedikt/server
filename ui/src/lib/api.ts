/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge, UiMethod } from '@alohasocial/ui';
import type {
	Account,
	Attachment,
	CreatePostPayload,
	FollowedTag,
	InstanceInfo,
	NotificationPageData,
	Relationship,
	TimelineItem,
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
	post(id: string): Promise<TimelineItem>;
	account(id: string): Promise<Account>;
	hostProfiles(): Promise<Record<string, string>>;
	followAccount(id: string): Promise<Relationship>;
	unfollowAccount(id: string): Promise<Relationship>;
	notifications(limit?: number, before?: string | null): Promise<NotificationPageData>;
	unreadCount(): Promise<number>;
	dismissNotification(id: string): Promise<void>;
	clearNotifications(): Promise<number>;
	createPost(payload: CreatePostPayload): Promise<TimelineItem>;
	deletePost(id: string): Promise<void>;
	boostPost(id: string): Promise<void>;
	unboostPost(id: string): Promise<void>;
	followedTags(): Promise<FollowedTag[]>;
	followTag(tag: string): Promise<void>;
	unfollowTag(tag: string): Promise<void>;
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
		content: typeof value.content === 'string' ? value.content : '',
		content_warning: typeof value.content_warning === 'string' ? value.content_warning : null,
		visibility: typeof value.visibility === 'string' ? value.visibility : null,
		published_at: typeof value.published_at === 'string' ? value.published_at : '',
		deleted_at: typeof value.deleted_at === 'string' ? value.deleted_at : null,
		in_reply_to_id: typeof value.in_reply_to_id === 'string' ? value.in_reply_to_id : null,
		recipient_ids: Array.isArray(value.recipient_ids)
			? value.recipient_ids.filter((id): id is string => typeof id === 'string')
			: [],
		attachments,
		poll: isRecord(value.poll) ? (value.poll as unknown as TimelineItem['poll']) : null,
		kind: value.kind === 'boost' ? 'boost' : 'post',
		object_id: typeof value.object_id === 'string' ? value.object_id : null,
		object_author_id: typeof value.object_author_id === 'string' ? value.object_author_id : null,
		boosted_by: typeof value.boosted_by === 'string' ? value.boosted_by : null,
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
		followers_count: typeof value.followers_count === 'number' ? value.followers_count : 0,
		following_count: typeof value.following_count === 'number' ? value.following_count : 0,
		relationship,
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

		async deletePost(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}`);
		},

		async boostPost(id: string): Promise<void> {
			await request<unknown>('POST', `/api/v1/posts/${encodeURIComponent(id)}/boost`);
		},

		async unboostPost(id: string): Promise<void> {
			await request<unknown>('DELETE', `/api/v1/posts/${encodeURIComponent(id)}/boost`);
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
