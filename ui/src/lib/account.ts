/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, reactive, watch, type ComputedRef } from 'vue';
import { getApi } from './api';
import { accountName, handleLabel, isRemoteId } from './format';
import type { Account, Relationship } from './types';

export const directory = reactive({
	accounts: {} as Record<string, Account | null>,
	loading: {} as Record<string, boolean>,
});

const pending = new Set<string>();
const hostNames = reactive<Record<string, string>>({});
let hostNamesRequested = false;

/**
 * Load the host's public display names. The core account contract only knows
 * handles, so the host's own account table remains the authority for names.
 */
export async function loadHostDisplayNames(): Promise<void> {
	if (hostNamesRequested) {
		return;
	}

	hostNamesRequested = true;
	try {
		const names = await getApi().hostProfiles();
		for (const [id, name] of Object.entries(names)) {
			hostNames[id] = name;
			const account = directory.accounts[id];
			if (account !== null && account !== undefined) {
				account.display_name = name;
			}
		}
	} catch {
		// A host without the optional directory endpoint keeps handle titles.
	}
}

export function hostDisplayName(id: string): string {
	return hostNames[id] ?? '';
}

export async function resolveAccount(id: string): Promise<Account | null> {
	if (id === '' || isRemoteId(id)) {
		return null;
	}

	if (Object.prototype.hasOwnProperty.call(directory.accounts, id)) {
		return directory.accounts[id];
	}

	if (pending.has(id)) {
		return null;
	}

	pending.add(id);
	directory.loading[id] = true;

	try {
		const account = await getApi().account(id);
		const name = hostDisplayName(id);
		const resolved = name === '' ? account : { ...account, display_name: name };
		directory.accounts[id] = resolved;
		return resolved;
	} catch {
		directory.accounts[id] = null;
		return null;
	} finally {
		pending.delete(id);
		delete directory.loading[id];
	}
}

export async function refreshAccount(id: string): Promise<Account | null> {
	delete directory.accounts[id];
	return resolveAccount(id);
}

export function accountFor(id: string): Account | null {
	return directory.accounts[id] ?? null;
}

export function isLoadingAccount(id: string): boolean {
	return directory.loading[id] === true;
}

export function displayNameFor(id: string): string {
	return hostDisplayName(id) || accountName(accountFor(id), id);
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
