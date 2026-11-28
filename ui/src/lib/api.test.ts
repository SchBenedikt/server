/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { HostBridge, UiResponse } from '@alohasocial/ui';
import { ApiError, createApi, normalizeMastodonStatus } from './api';
import { markOnline, offline } from './connectivity';

describe('normalizeMastodonStatus', () => {
	it('decodes a doubly escaped remote status before turning it into text', () => {
		const status = normalizeMastodonStatus({
			id: 'https://remote.example/users/eve/statuses/1',
			created_at: '2026-11-26T22:49:29.000Z',
			content: '<p>&lt;p&gt;Remote &lt;script&gt;window.__remote_xss=1&lt;/script&gt; '
				+ '&lt;strong&gt;bold&lt;/strong&gt;&lt;/p&gt;</p>',
			account: {
				id: 'https://remote.example/users/eve',
				username: 'eve',
				acct: 'eve@remote.example',
			},
		});

		expect(status?.content).toBe('Remote window.__remote_xss=1 bold');
		expect(status?.content).not.toContain('<p>');
		expect(status?.content).not.toContain('&lt;');
	});
});

describe('request retry', () => {
	afterEach(() => {
		vi.useRealTimers();
	});

	function scriptedBridge(script: (UiResponse | Error)[]): { bridge: HostBridge; calls: () => number } {
		let calls = 0;
		const bridge = {
			apiBase: '/api/v1',
			currentUser: async () => null,
			request: async (): Promise<UiResponse> => {
				calls += 1;
				const next = script[Math.min(calls - 1, script.length - 1)];
				if (next instanceof Error) {
					throw next;
				}
				return next;
			},
			loginUrl: (returnTo: string) => `/login?next=${encodeURIComponent(returnTo)}`,
			logout: async () => {},
			navigate: () => {},
			capabilities: () => ({ media: false, atproto: false }),
		} as unknown as HostBridge;

		return { bridge, calls: () => calls };
	}

	function json(status: number, body: unknown, headers: Record<string, string> = {}): UiResponse {
		return { status, headers, body: JSON.stringify(body) };
	}

	it('retries a rate-limited read and then succeeds', async () => {
		vi.useFakeTimers();
		const { bridge, calls } = scriptedBridge([
			json(429, { error: { code: 'rate_limited', message: 'slow down' } }, { 'retry-after': '1' }),
			json(200, { profiles: { alice: 'Alice' } }),
		]);
		const api = createApi(bridge, async () => {
			throw new Error('unused');
		});

		const pending = api.hostProfiles(['alice']);
		await vi.advanceTimersByTimeAsync(6_000);
		expect(await pending).toEqual({ alice: 'Alice' });
		expect(calls()).toBe(2);
	});

	it('gives up after three attempts on a persistent server error', async () => {
		vi.useFakeTimers();
		const { bridge, calls } = scriptedBridge([
			json(503, { error: { code: 'unavailable', message: 'down' } }),
		]);
		const api = createApi(bridge, async () => {
			throw new Error('unused');
		});

		const pending = api.hostProfiles(['alice']);
		const assertion = expect(pending).rejects.toMatchObject({ status: 503 });
		await vi.advanceTimersByTimeAsync(10_000);
		await assertion;
		expect(calls()).toBe(3);
	});

	it('retries a dropped connection for reads but not for mutations', async () => {
		vi.useFakeTimers();
		const dropped = scriptedBridge([new Error('network down'), json(200, { profiles: {} })]);
		const readApi = createApi(dropped.bridge, async () => {
			throw new Error('unused');
		});

		const pending = readApi.hostProfiles(['alice']);
		await vi.advanceTimersByTimeAsync(6_000);
		await pending;
		expect(dropped.calls()).toBe(2);

		const failing = scriptedBridge([json(500, { error: { code: 'error', message: 'boom' } })]);
		const writeApi = createApi(failing.bridge, async () => {
			throw new Error('unused');
		});
		const write = writeApi.likePost('post-1');
		const rejected = expect(write).rejects.toMatchObject({ status: 500 });
		await vi.advanceTimersByTimeAsync(6_000);
		await rejected;
		expect(failing.calls()).toBe(1);
	});

	it('carries the server request id and classifies the transport kind', async () => {
		const { bridge } = scriptedBridge([
			json(500, { error: { code: 'boom', message: 'nope', request_id: 'req_test123' } }),
		]);
		const api = createApi(bridge, async () => {
			throw new Error('unused');
		});

		const failure = await api.likePost('post-1').then(
			() => null,
			(error: unknown) => error,
		);
		expect(failure).toBeInstanceOf(ApiError);
		const error = failure as ApiError;
		expect(error.requestId).toBe('req_test123');
		expect(error.kind).toBe('http');
	});
});

describe('scheduled posts', () => {
	function scheduledBridge(captured: { body?: unknown }) {
		const bridge = {
			apiBase: '/api/v1',
			currentUser: async () => null,
			request: async (input: { method: string; path: string; body?: unknown }): Promise<UiResponse> => {
				captured.body = input.body;
				return {
					status: 200,
					headers: {},
					body: JSON.stringify({
						scheduled_post: {
							id: 'sp-1',
							author_id: 'acct-alice',
							content: 'Hello poll',
							content_warning: null,
							visibility: 'public',
							attachment_ids: [],
							recipient_ids: [],
							in_reply_to_id: null,
							scheduled_at: '2026-12-01T10:00:00Z',
							language: null,
							poll: {
								options: ['Yes', 'No'],
								multiple: false,
								hide_totals: true,
								expires_in: 86400,
								expires_at: null,
							},
							created_at: '2026-11-28T10:00:00Z',
							state: 'scheduled',
							published_at: null,
							failure_reason: null,
						},
					}),
				};
			},
			loginUrl: (returnTo: string) => `/login?next=${encodeURIComponent(returnTo)}`,
			logout: async () => {},
			navigate: () => {},
			capabilities: () => ({ media: false, atproto: false }),
		} as unknown as HostBridge;

		return bridge;
	}

	it('sends the full edit patch and normalizes the poll', async () => {
		const captured: { body?: unknown } = {};
		const api = createApi(scheduledBridge(captured), async () => {
			throw new Error('unused');
		});

		const updated = await api.updateScheduledPost('sp-1', {
			content: 'Hello poll',
			content_warning: 'nsfw',
			scheduled_at: '2026-12-01T10:00:00Z',
			poll: {
				options: ['Yes', 'No'],
				multiple: false,
				hide_totals: true,
				expires_in: 86400,
				expires_at: null,
			},
		});

		expect(captured.body).toEqual({
			content: 'Hello poll',
			content_warning: 'nsfw',
			scheduled_at: '2026-12-01T10:00:00Z',
			poll: {
				options: ['Yes', 'No'],
				multiple: false,
				hide_totals: true,
				expires_in: 86400,
				expires_at: null,
			},
		});
		expect(updated.poll).toEqual({
			options: ['Yes', 'No'],
			multiple: false,
			hide_totals: true,
			expires_in: 86400,
			expires_at: null,
		});
	});
});

describe('postDelivery', () => {
	function deliveryBridge(body: unknown) {
		return {
			apiBase: '/api/v1',
			currentUser: async () => null,
			request: async (): Promise<UiResponse> => ({
				status: 200,
				headers: {},
				body: JSON.stringify(body),
			}),
			loginUrl: (returnTo: string) => `/login?next=${encodeURIComponent(returnTo)}`,
			logout: async () => {},
			navigate: () => {},
			capabilities: () => ({ media: false, atproto: false }),
		} as unknown as HostBridge;
	}

	it('normalizes counts and per-instance rows', async () => {
		const api = createApi(deliveryBridge({
			delivery: {
				delivered: 3,
				sending: 1,
				waiting: 2,
				failing: 0,
				abandoned: 1,
				total: 7,
				instances: [
					{ host: 'peer.example', state: 'failing', tries: 4, last: '2026-11-28 10:00:00' },
					{ host: 'broken', state: 42 },
				],
			},
		}), async () => {
			throw new Error('unused');
		});

		const delivery = await api.postDelivery('post-1');
		expect(delivery).not.toBeNull();
		expect(delivery?.delivered).toBe(3);
		expect(delivery?.total).toBe(7);
		expect(delivery?.instances).toEqual([
			{ host: 'peer.example', state: 'failing', tries: 4, last: '2026-11-28 10:00:00' },
		]);
	});

	it('returns null when the payload has no delivery object', async () => {
		const api = createApi(deliveryBridge({ ok: true }), async () => {
			throw new Error('unused');
		});

		expect(await api.postDelivery('post-1')).toBeNull();
	});
});

describe('network failures', () => {
	it('reports a dropped connection as a friendly error and flags offline', async () => {
		const { bridge } = (() => {
			const script: (UiResponse | Error)[] = [new Error('network down')];
			let calls = 0;
			const b = {
				apiBase: '/api/v1',
				currentUser: async () => null,
				request: async (): Promise<UiResponse> => {
					calls += 1;
					const next = script[Math.min(calls - 1, script.length - 1)];
					if (next instanceof Error) {
						throw next;
					}
					return next;
				},
				loginUrl: (returnTo: string) => `/login?next=${encodeURIComponent(returnTo)}`,
				logout: async () => {},
				navigate: () => {},
				capabilities: () => ({ media: false, atproto: false }),
			} as unknown as HostBridge;
			return { bridge: b };
		})();
		const api = createApi(bridge, async () => {
			throw new Error('unused');
		});

		await expect(api.likePost('post-1')).rejects.toMatchObject({
			status: 0,
			code: 'network_error',
		});
		expect(offline.value).toBe(true);
		markOnline();
	});
});

describe('loadErrorMessage', () => {
	it('gives network failures offline-specific copy', async () => {
		const { loadErrorMessage } = await import('./api');
		const error = new ApiError(0, 'network_error', 'Failed to fetch');
		expect(loadErrorMessage(error, 'fallback')).toBe('No connection — check your network and try again.');
	});

	it('appends the request id to server errors', async () => {
		const { loadErrorMessage } = await import('./api');
		const error = new ApiError(500, 'http_error', 'Server error', {}, 'req_abc123');
		expect(loadErrorMessage(error, 'fallback')).toBe('Server error (ref req_abc123)');
	});

	it('uses the fallback for non-error throws', async () => {
		const { loadErrorMessage } = await import('./api');
		expect(loadErrorMessage('boom', 'fallback')).toBe('fallback');
		expect(loadErrorMessage(new Error(''), 'fallback')).toBe('fallback');
	});
});
