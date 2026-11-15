/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Quote cards need the summary of a post that is named by its canonical
 * object id, not its local id. A feed can show dozens of them at once, so the
 * lookups are coalesced into one request per animation frame and cached for
 * the lifetime of the page.
 */
import { getApi } from './api';
import type { TimelineItem } from './types';

const cache = new Map<string, TimelineItem | null>();
const waiting = new Map<string, Array<(value: TimelineItem | null) => void>>();
let timer: number | null = null;

export function cachedQuote(objectId: string): TimelineItem | null | undefined {
	return cache.get(objectId);
}

export function loadQuote(objectId: string): Promise<TimelineItem | null> {
	if (objectId === '') {
		return Promise.resolve(null);
	}

	const hit = cache.get(objectId);
	if (hit !== undefined) {
		return Promise.resolve(hit);
	}

	return new Promise((resolve) => {
		const listeners = waiting.get(objectId) ?? [];
		listeners.push(resolve);
		waiting.set(objectId, listeners);

		if (timer === null) {
			timer = window.setTimeout(() => {
				void flush();
			}, 25);
		}
	});
}

export function prefetchQuotes(objectIds: Array<string | null | undefined>): void {
	for (const id of objectIds) {
		if (typeof id === 'string' && id !== '' && !cache.has(id) && !waiting.has(id)) {
			void loadQuote(id);
		}
	}
}

async function flush(): Promise<void> {
	timer = null;
	const ids = [...waiting.keys()];
	if (ids.length === 0) {
		return;
	}

	const batch = ids.slice(0, 40);
	const overflow = ids.slice(40);
	if (overflow.length > 0) {
		timer = window.setTimeout(() => {
			void flush();
		}, 25);
	}

	try {
		const loaded = await getApi().quotedPosts(batch);
		for (const id of batch) {
			const value = loaded[id] ?? null;
			cache.set(id, value);
			settle(id, value);
		}
	} catch {
		for (const id of batch) {
			cache.set(id, null);
			settle(id, null);
		}
	}
}

function settle(id: string, value: TimelineItem | null): void {
	const listeners = waiting.get(id) ?? [];
	waiting.delete(id);
	for (const resolve of listeners) {
		resolve(value);
	}
}
