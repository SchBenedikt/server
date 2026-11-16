/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import { ApiError, getApi, hydrateTimelineBoosts } from './api';
import { onPostCreated, onPostDeleted, onPostUpdated } from './events';
import type { TimelineItem } from './types';

export type FeedKind = 'home' | 'for-you' | 'public' | 'tag' | 'followed';

export interface TimelineSource {
	kind: FeedKind;
	tag?: string;
}

const PAGE_SIZE = 20;

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

	let cursor: string | null = null;
	let offset = 0;
	let generation = 0;

	const key = computed(() => sourceKey(source()));

	const include = (post: TimelineItem): boolean => {
		const current = source();
		if (current.kind === 'public') {
			return post.visibility === 'public';
		}
		if (current.kind === 'tag') {
			const tag = (current.tag ?? '').toLowerCase();
			return tag !== '' && post.content.toLowerCase().includes(`#${tag}`);
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

	async function fetchPage(before: string | null): Promise<TimelineItem[]> {
		const current = source();
		const api = getApi();

		switch (current.kind) {
			case 'home':
				return api.homeTimeline(PAGE_SIZE, before);
			case 'for-you':
				return api.forYou(PAGE_SIZE, offset).then(async (data) => {
					const loaded = await api.posts(data.posts);
					return data.posts
						.map((id) => loaded[id])
						.filter((post): post is TimelineItem => post !== undefined);
				});
			case 'tag':
				return api.tagTimeline(current.tag ?? '', PAGE_SIZE, before);
			case 'followed':
				return api.followedTimeline(PAGE_SIZE, before);
			default:
				return api.publicTimeline(PAGE_SIZE, before);
		}
	}

	async function load(reset: boolean): Promise<void> {
		const currentGeneration = ++generation;
		const before = reset ? null : cursor;

		const isForYou = current.kind === 'for-you';
		if (reset) {
			offset = 0;
			loading.value = true;
		} else {
			loadingMore.value = true;
		}
		error.value = '';

		try {
			const batch = await hydrateTimelineBoosts(await fetchPage(before));
			if (currentGeneration !== generation) {
				return;
			}

			posts.value = reset ? merge([], batch) : merge(posts.value, batch);
			if (isForYou) {
				offset = (reset ? 0 : offset) + batch.length;
				hasMore.value = batch.length >= PAGE_SIZE;
			} else {
				cursor = batch.length > 0 ? batch[batch.length - 1].id : cursor;
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
		if (!loading.value && !loadingMore.value && hasMore.value && error.value === '') {
			void load(false);
		}
	}

	const stopCreated = onPostCreated((post) => {
		if (!include(post) || posts.value.some((item) => item.id === post.id)) {
			return;
		}

		posts.value = [post, ...posts.value];
	});

	const stopUpdated = onPostUpdated((post) => {
		posts.value = posts.value.map((item) => {
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

	const stopDeleted = onPostDeleted((postId) => {
		posts.value = posts.value.filter((post) => post.id !== postId && post.object_id !== postId);
	});

	watch(key, () => {
		posts.value = [];
		cursor = null;
		offset = 0;
		hasMore.value = true;
		loaded.value = false;
		void load(true);
	}, { immediate: true });

	onUnmounted(() => {
		generation += 1;
		stopCreated();
		stopUpdated();
		stopDeleted();
	});

	return {
		posts,
		loading,
		loadingMore,
		error,
		hasMore,
		loaded,
		refresh,
		loadMore,
	};
}
