/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, reactive } from 'vue';
import { getApi } from './api';
import { extractTags } from './format';
import { onPostCreated, onPostDeleted, onPostUpdated } from './events';
import { appState } from './state';
import type { TimelineItem } from './types';

const PAGE_SIZE = 40;
const MAX_PAGES = 3;

export const discover = reactive({
	posts: [] as TimelineItem[],
	loading: false,
	loaded: false,
	error: '',
});

let inFlight: Promise<void> | null = null;

function merge(posts: TimelineItem[], incoming: TimelineItem[]): TimelineItem[] {
	const seen = new Set(posts.map((post) => post.id));
	const merged = [...posts];

	for (const post of incoming) {
		if (!seen.has(post.id)) {
			seen.add(post.id);
			merged.push(post);
		}
	}

	return merged;
}

export async function loadDiscover(force = false): Promise<void> {
	if (inFlight !== null) {
		return inFlight;
	}
	if (discover.loaded && !force) {
		return;
	}

	inFlight = (async () => {
		discover.loading = true;
		discover.error = '';
		let before: string | null = null;
		let collected: TimelineItem[] = [];

		try {
			for (let page = 0; page < MAX_PAGES; page += 1) {
				const batch = await getApi().publicTimeline(PAGE_SIZE, before);
				collected = merge(collected, batch);

				if (batch.length < PAGE_SIZE) {
					break;
				}

				before = batch[batch.length - 1].id;
			}

			discover.posts = collected;
			discover.loaded = true;
		} catch (error) {
			discover.error = error instanceof Error ? error.message : 'Die öffentlichen Beiträge konnten nicht geladen werden.';
		} finally {
			discover.loading = false;
			inFlight = null;
		}
	})();

	return inFlight;
}

export const popularTags = computed(() => {
	const counts = new Map<string, number>();

	for (const post of discover.posts) {
		for (const tag of extractTags(post.content)) {
			counts.set(tag, (counts.get(tag) ?? 0) + 1);
		}
	}

	return [...counts.entries()]
		.sort((left, right) => right[1] - left[1] || left[0].localeCompare(right[0]))
		.slice(0, 12)
		.map(([tag, count]) => ({ tag, count }));
});

export const discoveryPeople = computed(() => {
	const seen = new Map<string, number>();
	for (const post of discover.posts) {
		const author = post.author_id;
		if (author === '' || author === appState.session.user?.handle) {
			continue;
		}

		seen.set(author, (seen.get(author) ?? 0) + 1);
	}

	return [...seen.entries()]
		.sort((left, right) => right[1] - left[1])
		.slice(0, 8)
		.map(([id, posts]) => ({ id, posts }));
});

onPostCreated((post) => {
	if (post.visibility !== 'public') {
		return;
	}

	discover.posts = merge([post], discover.posts);
});

onPostUpdated((post) => {
	discover.posts = discover.posts.map((item) => {
		if (item.id !== post.id) {
			return item;
		}

		return { ...item, ...post, interaction: item.interaction ?? post.interaction };
	});
});

onPostDeleted((postId) => {
	discover.posts = discover.posts.filter((post) => post.id !== postId && post.object_id !== postId);
});
