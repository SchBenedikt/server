/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { reactive } from 'vue';
import { getApi } from './api';
import type { PostInteraction } from './types';

/**
 * A small client-side cache for the viewer-specific post interaction data.
 *
 * Cards register the posts they display; the ids are batched into one host
 * request instead of one request per card. A stored value is authoritative for
 * the current page so an in-flight batch can never undo an optimistic like.
 */
export const interactionStore = reactive<Record<string, PostInteraction>>({});

const queued = new Set<string>();
let timer = 0;
let inFlight = false;

export function interactionFor(id: string | null | undefined): PostInteraction | undefined {
	return id === null || id === undefined || id === '' ? undefined : interactionStore[id];
}

export function rememberInteraction(id: string, interaction: PostInteraction): void {
	interactionStore[id] = interaction;
}

export function patchInteraction(id: string, patch: Partial<PostInteraction>): void {
	const current = interactionStore[id];
	if (current === undefined) {
		return;
	}

	interactionStore[id] = { ...current, ...patch };
}

export function ensureInteractions(ids: Array<string | null | undefined>): void {
	for (const id of ids) {
		if (id === null || id === undefined || id === '' || interactionStore[id] !== undefined || queued.has(id)) {
			continue;
		}
		queued.add(id);
	}

	if (queued.size === 0 || timer !== 0 || inFlight) {
		return;
	}

	timer = window.setTimeout(() => {
		void flush();
	}, 20);
}

async function flush(): Promise<void> {
	timer = 0;
	if (inFlight || queued.size === 0) {
		return;
	}

	const ids = [...queued].slice(0, 80);
	for (const id of ids) {
		queued.delete(id);
	}

	inFlight = true;
	try {
		const result = await getApi().interactions(ids);
		for (const [id, interaction] of Object.entries(result)) {
			// An optimistic update happened while the request was in flight.
			if (interactionStore[id] === undefined) {
				interactionStore[id] = interaction;
			}
		}
	} catch {
		// Interaction decoration is an enhancement; the post stays usable.
	} finally {
		inFlight = false;
		if (queued.size > 0 && timer === 0) {
			timer = window.setTimeout(() => {
				void flush();
			}, 20);
		}
	}
}
