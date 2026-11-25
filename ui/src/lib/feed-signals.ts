/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onUnmounted, watch } from 'vue';
import { getApi } from './api';

const FLUSH_INTERVAL_MS = 6000;
const MAX_DWELL_MS = 20_000;
const MIN_DWELL_MS = 250;
const MAX_BATCH = 60;
const VISIBLE_THRESHOLD = 0.35;

/**
 * Reports which For You posts were actually on screen, and for how long.
 *
 * The server turns this into two things: the post is marked as seen so the
 * next page moves on, and a long read is a small positive interest signal
 * while an instant scroll-past is a small negative one. Reporting is
 * best-effort — a failed batch never interrupts the feed.
 */
export function useFeedImpressions(options: {
	container: { value: HTMLElement | null };
	postIds: { value: string[] };
	enabled: { value: boolean };
}): void {
	const dwell = new Map<string, number>();
	const started = new Map<string, number>();
	const pending = new Set<string>();
	let observer: IntersectionObserver | null = null;
	let timer: number | null = null;

	function close(id: string): void {
		const since = started.get(id);
		if (since === undefined) {
			return;
		}

		started.delete(id);
		dwell.set(id, (dwell.get(id) ?? 0) + Math.min(Date.now() - since, MAX_DWELL_MS));
		pending.add(id);
	}

	function closeAll(): void {
		for (const id of [...started.keys()]) {
			close(id);
		}
	}

	async function flush(): Promise<void> {
		const ready = [...pending].filter((id) => (dwell.get(id) ?? 0) >= MIN_DWELL_MS);
		pending.clear();
		for (const id of [...dwell.keys()]) {
			if (!ready.includes(id)) {
				dwell.delete(id);
			}
		}
		if (ready.length === 0 || !options.enabled.value) {
			for (const id of ready) {
				dwell.delete(id);
			}

			return;
		}

		const items = ready.slice(0, MAX_BATCH).map((id) => ({
			post_id: id,
			dwell_ms: Math.round(dwell.get(id) ?? 0),
		}));
		for (const item of items) {
			dwell.delete(item.post_id);
		}

		try {
			await getApi().forYouImpressions(items);
		} catch {
			// Learning is best-effort; the next batch reports new posts.
		}
	}

	function track(): void {
		if (typeof IntersectionObserver === 'undefined') {
			return;
		}
		const root = options.container.value;
		if (root === null) {
			return;
		}

		observer?.disconnect();
		observer = new IntersectionObserver((entries) => {
			const now = Date.now();
			for (const entry of entries) {
				const id = (entry.target as HTMLElement).dataset.feedPostId ?? '';
				if (id === '') {
					continue;
				}
				if (entry.isIntersecting) {
					started.set(id, now);
					pending.delete(id);
				} else {
					close(id);
				}
			}
		}, { threshold: VISIBLE_THRESHOLD });

		for (const element of root.querySelectorAll<HTMLElement>('[data-feed-post-id]')) {
			observer.observe(element);
		}
	}

	function handleVisibility(): void {
		if (document.visibilityState === 'hidden') {
			closeAll();
			void flush();
		}
	}

	watch(options.postIds, () => {
		void flush();
		track();
	}, { flush: 'post' });
	watch(options.container, () => track(), { flush: 'post' });

	if (typeof window !== 'undefined') {
		timer = window.setInterval(() => {
			closeAll();
			void flush();
		}, FLUSH_INTERVAL_MS);
		document.addEventListener('visibilitychange', handleVisibility);
	}

	onUnmounted(() => {
		closeAll();
		void flush();
		observer?.disconnect();
		observer = null;
		if (timer !== null) {
			window.clearInterval(timer);
		}
		document.removeEventListener('visibilitychange', handleVisibility);
	});
}
