/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { configureApi, ApiError, type Api } from './api';
import {
	accountFor,
	directory,
	displayNameFor,
	loadHostDisplayNames,
	refreshAccount,
	resetAccountDirectory,
	resolveAccount,
} from './account';
import type { Account } from './types';

function account(id: string, handle = `@${id}`): Account {
	return {
		id,
		handle,
		display_name: `Name ${id}`,
		url: `https://example.test/@${id}`,
		followers_count: null,
		following_count: null,
		relationship: null,
	};
}

function apiWith(accountImpl: (id: string) => Promise<Account>): Api {
	return { account: accountImpl } as unknown as Api;
}

describe('resolveAccount', () => {
	beforeEach(() => {
		resetAccountDirectory();
		vi.useRealTimers();
	});

	it('shares one in-flight request between concurrent callers', async () => {
		let calls = 0;
		let release!: (value: Account) => void;
		const gate = new Promise<Account>((resolve) => {
			release = resolve;
		});
		configureApi(apiWith(async () => {
			calls++;
			return gate;
		}));

		const first = resolveAccount('alice');
		const second = resolveAccount('alice');
		expect(calls).toBe(1);

		release(account('alice'));
		const [a, b] = await Promise.all([first, second]);
		expect(a?.id).toBe('alice');
		expect(b?.id).toBe('alice');
		expect(calls).toBe(1);
	});

	it('caches a confirmed-missing profile only for the negative TTL', async () => {
		vi.useFakeTimers();
		let calls = 0;
		configureApi(apiWith(async () => {
			calls++;
			throw new ApiError(404, 'not_found', 'missing');
		}));

		expect(await resolveAccount('ghost')).toBeNull();
		expect(await resolveAccount('ghost')).toBeNull();
		expect(calls).toBe(1);

		vi.advanceTimersByTime(61_000);
		expect(await resolveAccount('ghost')).toBeNull();
		expect(calls).toBe(2);
	});

	it('retries a transient failure after backoff instead of caching null forever', async () => {
		vi.useFakeTimers();
		let calls = 0;
		configureApi(apiWith(async () => {
			calls++;
			if (calls === 1) {
				throw new ApiError(503, 'unavailable', 'try later');
			}
			return account('alice');
		}));

		expect(await resolveAccount('alice')).toBeNull();
		// Inside the backoff window the failure is not refetched.
		expect(await resolveAccount('alice')).toBeNull();
		expect(calls).toBe(1);

		vi.advanceTimersByTime(1_001);
		expect((await resolveAccount('alice'))?.id).toBe('alice');
		expect(calls).toBe(2);
		expect(accountFor('alice')?.id).toBe('alice');
	});

	it('bounds the cache and evicts the oldest entries', async () => {
		configureApi(apiWith(async (id) => account(id)));

		for (let index = 0; index < 505; index++) {
			await resolveAccount(`user-${index}`);
		}

		expect(Object.keys(directory.accounts)).toHaveLength(500);
		expect(accountFor('user-0')).toBeNull();
		expect(accountFor('user-4')).toBeNull();
		expect(accountFor('user-5')?.id).toBe('user-5');
		expect(accountFor('user-504')?.id).toBe('user-504');
	});

	it('discards in-flight results after a directory reset', async () => {
		let release!: (value: Account) => void;
		const gate = new Promise<Account>((resolve) => {
			release = resolve;
		});
		configureApi(apiWith(async () => gate));

		const pendingResult = resolveAccount('alice');
		resetAccountDirectory();
		release(account('alice'));

		expect(await pendingResult).toBeNull();
		expect(accountFor('alice')).toBeNull();
	});

	it('prefers the freshly fetched display name over the bootstrap map', async () => {
		configureApi({
			account: async (id: string) => account(id),
			hostProfiles: async () => ({ alice: 'Stale Bootstrap Name' }),
		} as unknown as Api);

		await loadHostDisplayNames();
		expect(displayNameFor('alice')).toBe('Stale Bootstrap Name');

		await resolveAccount('alice');
		expect(accountFor('alice')?.display_name).toBe('Name alice');
		expect(displayNameFor('alice')).toBe('Name alice');
	});

	it('refreshAccount bypasses a recent failure entry', async () => {
		let calls = 0;
		configureApi(apiWith(async (id) => {
			calls++;
			if (calls === 1) {
				throw new ApiError(500, 'error', 'boom');
			}
			return account(id);
		}));

		expect(await resolveAccount('alice')).toBeNull();
		expect((await refreshAccount('alice'))?.id).toBe('alice');
		expect(calls).toBe(2);
	});
});
