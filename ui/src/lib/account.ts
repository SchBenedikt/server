/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, reactive, watch, type ComputedRef } from 'vue';
import { ApiError, getApi } from './api';
import { accountName, handleLabel, isRemoteId } from './format';
import type { Account, Relationship } from './types';

export const directory = reactive({
	accounts: {} as Record<string, Account | null>,
	loading: {} as Record<string, boolean>,
});

/**
 * Bookkeeping behind the public `directory` map: when a negative or failed
 * entry stops being authoritative, and how often a transient failure has
 * retried. Kept out of the reactive state because it is not rendered.
 */
interface ResolveMeta {
	until: number;
	attempts: number;
}

const pending = new Map<string, Promise<Account | null>>();
const meta = new Map<string, ResolveMeta>();

/**
 * A confirmed-absent profile stays absent briefly so scrolling back and
 * forth does not refetch it; a transient failure retries with bounded
 * exponential backoff instead of being cached as a permanent null.
 */
const MISSING_TTL_MS = 60_000;
const FAILED_BASE_MS = 1_000;
const FAILED_MAX_MS = 30_000;
const MAX_CACHED_ACCOUNTS = 500;

let directoryEpoch = 0;
const hostNames = reactive<Record<string, string>>({});

/**
 * Names learned from authoritative per-account responses. The core account
 * contract only knows handles, so resolved profiles keep their display name
 * here as a fallback for `displayNameFor` before the account is cached;
 * entries are always refreshed by `resolveAccount`, never stale bootstrap
 * data.
 */
export function hostDisplayName(id: string): string {
	return hostNames[id] ?? '';
}

export async function resolveAccount(id: string): Promise<Account | null> {
	if (id === '' || isRemoteId(id)) {
		return null;
	}

	const cached = directory.accounts[id];
	if (cached !== undefined) {
		if (cached !== null) {
			return cached;
		}

		const entry = meta.get(id);
		if (entry !== undefined && entry.until > Date.now()) {
			return null;
		}

		// The negative entry expired: drop it and resolve again below.
		delete directory.accounts[id];
		meta.delete(id);
	}

	const inflight = pending.get(id);
	if (inflight !== undefined) {
		return inflight;
	}

	const epoch = directoryEpoch;
	const request = (async (): Promise<Account | null> => {
		directory.loading[id] = true;

		try {
			const account = await getApi().account(id);
			if (epoch !== directoryEpoch) {
				return null;
			}

			// The per-account response is authoritative and newer than the
			// bootstrap name map, which may predate a profile edit.
			hostNames[id] = account.display_name;
			rememberAccount(id, account);
			return directory.accounts[id] ?? null;
		} catch (error) {
			if (epoch === directoryEpoch) {
				noteFailure(id, error);
			}

			return null;
		} finally {
			pending.delete(id);
			if (epoch === directoryEpoch) {
				delete directory.loading[id];
			}
		}
	})();

	pending.set(id, request);
	return request;
}

function rememberAccount(id: string, account: Account): void {
	meta.delete(id);
	directory.accounts[id] = account;

	const keys = Object.keys(directory.accounts);
	const overflow = keys.length - MAX_CACHED_ACCOUNTS;
	for (const key of keys.slice(0, Math.max(0, overflow))) {
		delete directory.accounts[key];
		meta.delete(key);
	}
}

function noteFailure(id: string, error: unknown): void {
	directory.accounts[id] = null;

	if (error instanceof ApiError && (error.status === 404 || error.status === 410)) {
		meta.set(id, { until: Date.now() + MISSING_TTL_MS, attempts: 0 });
		return;
	}

	const attempts = (meta.get(id)?.attempts ?? 0) + 1;
	const delay = Math.min(FAILED_MAX_MS, FAILED_BASE_MS * 2 ** (attempts - 1));
	meta.set(id, { until: Date.now() + delay, attempts });
}

/**
 * Drops every cached profile and discards in-flight results. Logout and
 * account switches call this so relationship state from one session can
 * never leak into the next.
 */
export function resetAccountDirectory(): void {
	directoryEpoch++;
	for (const key of Object.keys(directory.accounts)) {
		delete directory.accounts[key];
	}
	for (const key of Object.keys(directory.loading)) {
		delete directory.loading[key];
	}
	meta.clear();
	pending.clear();
	for (const key of Object.keys(hostNames)) {
		delete hostNames[key];
	}
}

export async function refreshAccount(id: string): Promise<Account | null> {
	delete directory.accounts[id];
	meta.delete(id);
	return resolveAccount(id);
}

export function accountFor(id: string): Account | null {
	return directory.accounts[id] ?? null;
}

export function isLoadingAccount(id: string): boolean {
	return directory.loading[id] === true;
}

export function displayNameFor(id: string): string {
	// A resolved profile carries the freshest authoritative name; the host
	// bootstrap map is only a fallback for accounts not fetched yet, and it
	// is capped, so it must never win over per-account data.
	const account = accountFor(id);
	const name = account?.display_name?.trim() ?? '';
	if (name !== '' && name !== account?.id) {
		return name;
	}

	return hostDisplayName(id) || accountName(account, id);
}

/** True when a profile has a real display name beyond its handle. */
export function hasDistinctDisplayName(id: string): boolean {
	return displayNameFor(id) !== handleLabel(id);
}

export function handleFor(id: string): string {
	return handleLabel(accountFor(id)?.handle ?? id);
}

export function useAccount(id: ComputedRef<string>): {
	account: ComputedRef<Account | null>;
	loading: ComputedRef<boolean>;
} {
	const account = computed(() => accountFor(id.value));
	const loading = computed(() => isLoadingAccount(id.value));

	watch(id, (value) => {
		if (value !== '') {
			void resolveAccount(value);
		}
	}, { immediate: true });

	return { account, loading };
}

export function applyRelationship(id: string, relationship: Relationship): void {
	const account = directory.accounts[id];
	if (account !== null && account !== undefined) {
		account.relationship = relationship;
	}
}

export function setAccount(account: Account): void {
	meta.delete(account.id);
	const name = hostDisplayName(account.id);
	directory.accounts[account.id] = name === '' ? account : { ...account, display_name: name };
}

export function setHostDisplayName(id: string, name: string): void {
	if (name === '') {
		delete hostNames[id];
	} else {
		hostNames[id] = name;
	}

	const account = directory.accounts[id];
	if (account !== null && account !== undefined) {
		account.display_name = name;
	}
}
