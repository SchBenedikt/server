/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref } from 'vue';

export interface SidebarItem {
	id: string;
	label: string;
	icon: string;
	href: string;
	group: 'main' | 'library' | 'moderation' | 'instance';
	requiresAuth?: boolean;
	adminOnly?: boolean;
}

export const SIDEBAR_CATALOG: SidebarItem[] = [
	{ id: 'home', label: 'Home', icon: 'home', href: '#/', group: 'main' },
	{ id: 'explore', label: 'Explore', icon: 'compass', href: '#/explore', group: 'main' },
	{ id: 'notifications', label: 'Notifications', icon: 'bell', href: '#/notifications', group: 'main' },
	{ id: 'messages', label: 'Messages', icon: 'message', href: '#/messages', group: 'main', requiresAuth: true },
	{ id: 'profile', label: 'Profile', icon: 'user', href: '#/profile', group: 'main', requiresAuth: true },
	{ id: 'settings', label: 'Settings', icon: 'settings', href: '#/settings', group: 'main', requiresAuth: true },
	{ id: 'search', label: 'Search', icon: 'search', href: '#/search', group: 'main' },
	{ id: 'kokua', label: 'Kokua board', icon: 'handHeart', href: '#/kokua', group: 'main' },
	{ id: 'memories', label: 'Memories', icon: 'clock', href: '#/memories', group: 'library', requiresAuth: true },
	{ id: 'bookmarks', label: 'Bookmarks', icon: 'bookmark', href: '#/bookmarks', group: 'library', requiresAuth: true },
	{ id: 'collections', label: 'Collections', icon: 'list', href: '#/collections', group: 'library', requiresAuth: true },
	{ id: 'lists', label: 'Lists', icon: 'list', href: '#/lists', group: 'library', requiresAuth: true },
	{ id: 'drafts', label: 'Drafts', icon: 'pen', href: '#/drafts', group: 'library', requiresAuth: true },
	{ id: 'archived', label: 'Archive', icon: 'archive', href: '#/archived', group: 'library', requiresAuth: true },
	{ id: 'scheduled', label: 'Scheduled posts', icon: 'calendar', href: '#/scheduled', group: 'library', requiresAuth: true },
	{ id: 'follow-requests', label: 'Follow requests', icon: 'lock', href: '#/follow-requests', group: 'library', requiresAuth: true },
	{ id: 'filters', label: 'Filters', icon: 'filter', href: '#/filters', group: 'moderation', requiresAuth: true },
	{ id: 'muted', label: 'Muted', icon: 'bellOff', href: '#/muted', group: 'moderation', requiresAuth: true },
	{ id: 'blocked', label: 'Blocked', icon: 'ban', href: '#/blocked', group: 'moderation', requiresAuth: true },
	{ id: 'about', label: 'About this instance', icon: 'info', href: '#/about', group: 'instance' },        { id: 'admin', label: 'Administration', icon: 'shield', href: '/admin', group: 'instance', adminOnly: true },
	{ id: 'dashboard', label: 'Server dashboard', icon: 'server', href: '/', group: 'instance' },
];

export const DEFAULT_PRIMARY = ['home', 'explore', 'notifications', 'messages', 'profile', 'settings'];
const STORAGE_KEY = 'aloha.sidebar.v1';
const MAX_PRIMARY = 8;

interface StoredPreferences {
	primary: string[];
}

function load(): string[] {
	if (typeof window === 'undefined') {
		return [...DEFAULT_PRIMARY];
	}

	try {
		const raw = window.localStorage.getItem(STORAGE_KEY);
		if (raw === null) {
			return [...DEFAULT_PRIMARY];
		}
		const parsed = JSON.parse(raw) as Partial<StoredPreferences>;
		if (!Array.isArray(parsed.primary)) {
			return [...DEFAULT_PRIMARY];
		}
		const known = new Set(SIDEBAR_CATALOG.map((item) => item.id));
		const primary = parsed.primary
			.filter((id): id is string => typeof id === 'string' && known.has(id))
			.filter((id, index, list) => list.indexOf(id) === index)
			.slice(0, MAX_PRIMARY);
		return primary.length > 0 ? primary : [...DEFAULT_PRIMARY];
	} catch {
		return [...DEFAULT_PRIMARY];
	}
}

export const primarySidebarIds = ref<string[]>(load());

export function catalogFor(canAccess: (item: SidebarItem) => boolean): SidebarItem[] {
	return SIDEBAR_CATALOG.filter(canAccess);
}

export function primaryCatalogItems(canAccess: (item: SidebarItem) => boolean): SidebarItem[] {
	const available = new Map(catalogFor(canAccess).map((item) => [item.id, item]));
	const result: SidebarItem[] = [];
	for (const id of primarySidebarIds.value) {
		const item = available.get(id);
		if (item !== undefined) {
			result.push(item);
		}
	}

	return result;
}

export function setPrimarySidebar(ids: string[]): void {
	const available = new Set(SIDEBAR_CATALOG.map((item) => item.id));
	const next = ids
		.filter((id) => available.has(id))
		.filter((id, index, list) => list.indexOf(id) === index)
		.slice(0, MAX_PRIMARY);
	primarySidebarIds.value = next.length > 0 ? next : [...DEFAULT_PRIMARY];
	persist();
}

export function resetSidebar(): void {
	primarySidebarIds.value = [...DEFAULT_PRIMARY];
	persist();
}

function persist(): void {
	if (typeof window === 'undefined') {
		return;
	}

	try {
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ primary: primarySidebarIds.value }));
	} catch {
		// Preferences are a convenience; an unavailable store keeps them in memory.
	}
}

export { MAX_PRIMARY };
