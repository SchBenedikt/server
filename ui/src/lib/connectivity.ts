/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref } from 'vue';

/**
 * Browser connectivity state shared by the API client and the shell.
 *
 * `offline` turns true when the browser reports no network or when a request
 * fails before any HTTP response arrives, and back to false as soon as any
 * response — even an error status — proves the server is reachable again.
 */
export const offline = ref(false);

export function markOffline(): void {
	offline.value = true;
}

export function markOnline(): void {
	offline.value = false;
}

if (typeof window !== 'undefined') {
	window.addEventListener('offline', () => {
		offline.value = true;
	});
	window.addEventListener('online', () => {
		offline.value = false;
	});
}
