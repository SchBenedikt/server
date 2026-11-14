/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge, UiRequest, UiResponse, UiUser } from '@alohasocial/ui';

const mount = document.getElementById('aloha-social');

const handle = mount?.dataset.user ?? '';
const displayName = mount?.dataset.displayName ?? '';
const csrf = mount?.dataset.csrf ?? '';
const instance = mount?.dataset.instance ?? 'Aloha Social';

function apiUrl(path: string, query?: Record<string, string>): string {
	const url = new URL(path, window.location.origin);

	for (const [key, value] of Object.entries(query ?? {})) {
		url.searchParams.set(key, value);
	}

	return url.toString();
}

/**
 * The standalone host owns its session cookie and its CSRF header. The shared
 * components only ever see core paths and the raw response.
 */
export const bridge: HostBridge = {
	apiBase: '',

	async currentUser(): Promise<UiUser | null> {
		if (handle === '') {
			return null;
		}

		return {
			id: handle,
			handle,
			displayName: displayName === '' ? undefined : displayName,
		};
	},

	async request({ method, path, query, body }: UiRequest): Promise<UiResponse> {
		const headers: Record<string, string> = { Accept: 'application/json' };

		if (body !== undefined) {
			headers['Content-Type'] = 'application/json';
		}

		if (csrf !== '') {
			headers['X-CSRF-Token'] = csrf;
		}

		const response = await fetch(apiUrl(path, query), {
			method,
			headers,
			credentials: 'same-origin',
			body: body === undefined ? undefined : JSON.stringify(body),
		});

		return {
			status: response.status,
			headers: Object.fromEntries(response.headers.entries()),
			body: await response.text(),
		};
	},

	loginUrl(returnTo: string): string {
		return `/login?return_to=${encodeURIComponent(returnTo)}`;
	},

	async logout(): Promise<void> {
		await fetch('/logout', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-CSRF-Token': csrf,
			},
			credentials: 'same-origin',
			body: new URLSearchParams({ _csrf: csrf }).toString(),
		});

		window.location.assign('/login');
	},

	navigate(path: string): void {
		window.location.assign(path);
	},

	capabilities() {
		return { media: true, atproto: false };
	},
};

export const instanceName = instance;
export const currentHandle = handle;
export const signedIn = handle !== '';
