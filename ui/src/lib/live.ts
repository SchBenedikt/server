/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onUnmounted, type Ref, watch } from 'vue';
import { getApi } from './api';
import { announcePostCreated, announcePostDeleted } from './events';

const POLL_INTERVAL_MS = 15_000;

/**
 * Keeps an open page current without a websocket.
 *
 * The host exposes a cheap cursor endpoint that only reports ids of posts the
 * viewer may already see, plus deletions. The poller hydrates those ids once
 * and pushes them through the existing event bus, so timelines, threads and
 * notification counts update in place instead of reloading the page.
 *
 * Hidden tabs do not poll, and a failed poll never surfaces an error: the next
 * tick retries with the same cursor.
 */
export function useLiveUpdates(enabled: Ref<boolean>): void {
	let cursor = new Date().toISOString();
	let timer = 0;
	let busy = false;

	async function poll(): Promise<void> {
		if (busy || !enabled.value || document.visibilityState !== 'visible') {
			return;
		}

		busy = true;
		try {
			const data = await getApi().updates(cursor);
			cursor = data.cursor;

			for (const id of data.deleted) {
				announcePostDeleted(id, true);
			}

			if (data.created.length > 0) {
				const posts = await getApi().posts(data.created);
				for (const id of data.created) {
					const post = posts[id];
					if (post !== undefined) {
						announcePostCreated(post, true);
					}
				}
			}
		} catch {
			// Best effort: keep the cursor and retry on the next tick.
		} finally {
			busy = false;
		}
	}

	watch(enabled, (value) => {
		if (value) {
			cursor = new Date().toISOString();
			void poll();
		}
	}, { immediate: true });

	if (typeof window !== 'undefined') {
		timer = window.setInterval(() => {
			void poll();
		}, POLL_INTERVAL_MS);
	}

	onUnmounted(() => {
		if (timer !== 0) {
			window.clearInterval(timer);
		}
	});
}
