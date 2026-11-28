/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import { ApiError, getApi, hydrateTimelineBoosts } from './api';
import { onEngagement, onPostCreated, onPostDeleted, onPostUpdated } from './events';
import { primeAuthorNames } from './account';
import type { FeedInterest, TimelineItem } from './types';

export type FeedKind = 'home' | 'for-you' | 'trending' | 'public' | 'federated' | 'tag' | 'followed';

export interface TimelineSource {
	kind: FeedKind;
	tag?: string;
}

const PAGE_SIZE = 20;

type TimelineCursor = { before: string; beforeId: string } | { ranked: string };

type TimelineBatch = {
	posts: TimelineItem[];
	consumed: number;
	nextCursor?: TimelineCursor | null;
	hasMore?: boolean;
};

function sourceKey(source: TimelineSource): string {
	return `${source.kind}:${source.tag ?? ''}`;
}

function messageFor(error: unknown): string {
	if (error instanceof ApiError) {
		if (error.status === 401) {
			return 'Sign in to see this feed.';
		}
		if (error.status === 404) {
			return 'This feed is unavailable.';
		}

		return error.message;
	}

	return error instanceof Error
		? error.message
		: 'The feed could not be loaded.';
}

export function useTimeline(source: () => TimelineSource) {
	const posts = ref<TimelineItem[]>([]);
	const loading = ref(false);
	const loadingMore = ref(false);
	const error = ref('');
	const hasMore = ref(true);
	const loaded = ref(false);
	const interests = ref<FeedInterest[]>([]);

	let cursor: TimelineCursor | null = null;
	let generation = 0;

	const key = computed(() => sourceKey(source()));

	const include = (post: TimelineItem): boolean => {
		const current = source();
		if (current.kind === 'public') {
			return post.visibility === 'public' && post.is_remote !== true;
		}
		if (current.kind === 'federated') {
			return post.is_remote === true;
		}
		if (current.kind === 'tag') {
			const tag = (current.tag ?? '').toLowerCase();
			const escapedTag = tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			return tag !== '' && new RegExp(`(?:^|[^\\p{L}\\p{N}_])#${escapedTag}(?![\\p{L}\\p{N}_])`, 'u').test(post.content.toLowerCase());
		}

		return true;
	};

	const merge = (current: TimelineItem[], incoming: TimelineItem[]): TimelineItem[] => {
		const seen = new Set(current.map((post) => post.id));
		const merged = [...current];
		for (const post of incoming) {
			if (!seen.has(post.id)) {
				seen.add(post.id);
				merged.push(post);
			}
		}

		return merged;
	};

	async function fetchPage(
		before: TimelineCursor | null,
	): Promise<TimelineBatch> {
		const current = source();
		const api = getApi();
		const beforeTime = before !== null && 'before' in before ? before.before : null;
		const beforeId = before !== null && 'beforeId' in before ? before.beforeId : null;

		switch (current.kind) {
			case 'home':
				return api.homeTimelinePage(PAGE_SIZE, beforeTime, beforeId).then((page) => ({
					posts: page.posts,
					consumed: page.posts.length,
					nextCursor: page.nextCursor,
					hasMore: page.hasMore,
				}));
			case 'for-you':
				return api.forYou(PAGE_SIZE, before !== null && 'ranked' in before ? before.ranked : null).then(async (data) => {
					const loaded = await api.posts(data.posts);
					const posts = data.posts
						.map((id) => loaded[id])
						.filter((post): post is TimelineItem => post !== undefined);
					if (data.interests.length > 0) {
						interests.value = data.interests;
					}
					return {
						posts,
						consumed: data.posts.length,
						nextCursor: typeof data.next === 'string' ? { ranked: data.next } : null,
						hasMore: data.next !== null,
					};
				});
			case 'trending':
				return api.trending(PAGE_SIZE, before !== null && 'ranked' in before ? before.ranked : null).then(async (data) => {
					const loaded = await api.posts(data.posts);
					const posts = data.posts
						.map((id) => loaded[id])
						.filter((post): post is TimelineItem => post !== undefined);
					return {
						posts,
						consumed: data.posts.length,
						nextCursor: typeof data.next === 'string' ? { ranked: data.next } : null,
						hasMore: data.next !== null,
					};
				});
			case 'tag':
				return api.tagTimelinePage(current.tag ?? '', PAGE_SIZE, beforeTime, beforeId).then((page) => ({
					posts: page.posts,
					consumed: page.posts.length,
					nextCursor: page.nextCursor,
					hasMore: page.hasMore,
				}));
			case 'federated':
				return api.federatedTimelinePage(PAGE_SIZE, beforeTime, beforeId).then((page) => ({
					posts: page.posts,
					consumed: page.posts.length,
					nextCursor: page.nextCursor,
					hasMore: page.hasMore,
				}));
			case 'followed':
				return api.followedTimelinePage(PAGE_SIZE, beforeTime, beforeId).then((page) => ({
					posts: page.posts,
					consumed: page.posts.length,
					nextCursor: page.nextCursor,
					hasMore: page.hasMore,
				}));
			default:
				return api.publicTimelinePage(PAGE_SIZE, beforeTime, beforeId).then((page) => ({
					posts: page.posts,
					consumed: page.posts.length,
					nextCursor: page.nextCursor,
					hasMore: page.hasMore,
				}));
		}
	}

	async function load(reset: boolean): Promise<void> {
		const currentGeneration = ++generation;
		const before = reset ? null : cursor;

		if (reset) {
			loading.value = true;
			interests.value = [];
		} else {
			loadingMore.value = true;
		}
		error.value = '';

		try {
			const page = await fetchPage(before);
			const batch = await hydrateTimelineBoosts(page.posts);
			primeAuthorNames(batch.flatMap((post) => [post.author_id, post.object_author_id ?? '']));
			if (currentGeneration !== generation) {
				return;
			}

			posts.value = reset ? merge([], batch) : merge(posts.value, batch);
			if (page.hasMore !== undefined) {
				cursor = page.nextCursor ?? null;
				hasMore.value = page.hasMore;
			} else {
				const last = batch.at(-1);
				cursor = last === undefined ? cursor : { before: last.published_at, beforeId: last.id };
				hasMore.value = batch.length >= PAGE_SIZE;
			}
			loaded.value = true;
		} catch (caught) {
			if (currentGeneration === generation) {
				error.value = messageFor(caught);
			}
		} finally {
			if (currentGeneration === generation) {
				loading.value = false;
				loadingMore.value = false;
			}
		}
	}

	function refresh(): void {
		void load(true);
	}

	function loadMore(): void {
		if (!loading.value && !loadingMore.value && hasMore.value) {
			void load(false);
		}
	}

	const stopCreated = onPostCreated((post, live) => {
		if (posts.value.some((item) => item.id === post.id)) {
			return;
		}

		const current = source();
		if (live) {
			// A polled post is already filtered by the server for this viewer.
			// Put it on top of a chronological feed instead of reloading the
			// first page, which would throw away the pages already scrolled.
			if ((current.kind === 'home' || current.kind === 'public' || current.kind === 'federated') && include(post)) {
				posts.value = [post, ...posts.value];
			}

			return;
		}

		// The server decides whether a post belongs in a filtered, followed,
		// ranked or private timeline. Refresh the first page instead of guessing
		// from the post body and leaking it into the wrong feed.
		void load(true);
	});

	const stopUpdated = onPostUpdated((post) => {
		posts.value = posts.value.filter((item) => {
			if (item.id === post.id || item.object_id === post.id) {
				return include(post);
			}

			return true;
		}).map((item) => {
			if (item.id === post.id) {
				return { ...item, ...post, interaction: item.interaction ?? post.interaction };
			}
			if (item.object_id === post.id) {
				return {
					...item,
					...post,
					kind: 'boost',
					object_id: item.object_id,
					boosted_by: item.boosted_by,
					interaction: item.interaction ?? post.interaction,
				};
			}

			return item;
		});
	});

	const stopDeleted = onPostDeleted((postId, _live) => {
		posts.value = posts.value.filter((post) => post.id !== postId && post.object_id !== postId);
	});

	// A live counter refresh names the activity id; the same post is also
	// addressable by its object id (and as the original of a boost).
	const stopEngagement = onEngagement((update) => {
		posts.value = posts.value.map((item) => {
			const matches = item.id === update.id
				|| item.id === update.object_id
				|| item.object_id === update.object_id;
			if (!matches) {
				return item;
			}

			return {
				...item,
				likes_count: update.likes_count,
				boosts_count: update.boosts_count,
				replies_count: update.replies_count,
			};
		});
	});

	watch(key, () => {
		posts.value = [];
		cursor = null;
		hasMore.value = true;
		loaded.value = false;
		void load(true);
	}, { immediate: true });

	onUnmounted(() => {
		generation += 1;
		stopCreated();
		stopUpdated();
		stopDeleted();
		stopEngagement();
	});

	return {
		posts,
		loading,
		loadingMore,
		error,
		hasMore,
		loaded,
		interests,
		refresh,
		loadMore,
	};
}
