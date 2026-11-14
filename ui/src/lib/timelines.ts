/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import { ApiError, getApi } from './api';
import { onPostCreated, onPostDeleted } from './events';
import type { TimelineItem } from './types';

export type FeedKind = 'home' | 'public' | 'tag' | 'followed';

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
			return 'Melde dich an, um diesen Feed zu sehen.';
		}
		if (error.status === 404) {
			return 'Dieser Feed ist nicht verfügbar.';
		}

		return error.message;
	}

	return error instanceof Error
		? error.message
		: 'Der Feed konnte nicht geladen werden.';
}

export function useTimeline(source: () => TimelineSource) {
	const posts = ref<TimelineItem[]>([]);
	const loading = ref(false);
	const loadingMore = ref(false);
	const error = ref('');
	const hasMore = ref(true);
	const loaded = ref(false);

	let cursor: string | null = null;
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

		if (reset) {
			loading.value = true;
		} else {
			loadingMore.value = true;
		}
		error.value = '';

		try {
			const batch = await fetchPage(before);
			if (currentGeneration !== generation) {
				return;
			}

			posts.value = reset ? merge([], batch) : merge(posts.value, batch);
			cursor = batch.length > 0 ? batch[batch.length - 1].id : cursor;
			hasMore.value = batch.length >= PAGE_SIZE;
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

	const stopDeleted = onPostDeleted((postId) => {
		posts.value = posts.value.filter((post) => post.id !== postId && post.object_id !== postId);
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
