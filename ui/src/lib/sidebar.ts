/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref } from 'vue';

export interface SidebarItem {
	id: string;
	label: string;
	labelKey?: string;
	icon: string;
	href: string;
	group: 'main' | 'library' | 'moderation' | 'instance';
	requiresAuth?: boolean;
	adminOnly?: boolean;
}

export const SIDEBAR_CATALOG: SidebarItem[] = [
	{ id: 'home', label: 'Home', labelKey: 'nav.home', icon: 'home', href: '#/', group: 'main' },
	{ id: 'explore', label: 'Explore', labelKey: 'nav.explore', icon: 'compass', href: '#/explore', group: 'main' },
	{ id: 'notifications', label: 'Notifications', labelKey: 'nav.notifications', icon: 'bell', href: '#/notifications', group: 'main' },
	{ id: 'messages', label: 'Messages', labelKey: 'nav.messages', icon: 'message', href: '#/messages', group: 'main', requiresAuth: true },
	{ id: 'profile', label: 'Profile', labelKey: 'nav.profile', icon: 'user', href: '#/profile', group: 'main', requiresAuth: true },
	{ id: 'settings', label: 'Settings', labelKey: 'nav.settings', icon: 'settings', href: '#/settings', group: 'main', requiresAuth: true },
	{ id: 'search', label: 'Search', labelKey: 'nav.search', icon: 'search', href: '#/search', group: 'main' },
	{ id: 'kokua', label: 'Kokua board', labelKey: 'nav.kokua', icon: 'handHeart', href: '#/kokua', group: 'main' },
	{ id: 'memories', label: 'Memories', labelKey: 'nav.memories', icon: 'clock', href: '#/memories', group: 'library', requiresAuth: true },
	{ id: 'bookmarks', label: 'Bookmarks', labelKey: 'nav.bookmarks', icon: 'bookmark', href: '#/bookmarks', group: 'library', requiresAuth: true },
	{ id: 'collections', label: 'Collections', labelKey: 'nav.collections', icon: 'list', href: '#/collections', group: 'library', requiresAuth: true },
	{ id: 'starter-packs', label: 'Starter packs', labelKey: 'nav.starterPacks', icon: 'users', href: '#/starter-packs', group: 'library', requiresAuth: true },
	{ id: 'lists', label: 'Lists', labelKey: 'nav.lists', icon: 'list', href: '#/lists', group: 'library', requiresAuth: true },
	{ id: 'drafts', label: 'Drafts', labelKey: 'nav.drafts', icon: 'pen', href: '#/drafts', group: 'library', requiresAuth: true },
	{ id: 'archived', label: 'Archive', labelKey: 'nav.archive', icon: 'archive', href: '#/archived', group: 'library', requiresAuth: true },
	{ id: 'scheduled', label: 'Scheduled posts', labelKey: 'nav.scheduled', icon: 'calendar', href: '#/scheduled', group: 'library', requiresAuth: true },
	{ id: 'follow-requests', label: 'Follow requests', labelKey: 'nav.followRequests', icon: 'lock', href: '#/follow-requests', group: 'library', requiresAuth: true },
	{ id: 'filters', label: 'Filters', labelKey: 'nav.filters', icon: 'filter', href: '#/filters', group: 'moderation', requiresAuth: true },
	{ id: 'muted', label: 'Muted', labelKey: 'nav.muted', icon: 'bellOff', href: '#/muted', group: 'moderation', requiresAuth: true },
	{ id: 'blocked', label: 'Blocked', labelKey: 'nav.blocked', icon: 'ban', href: '#/blocked', group: 'moderation', requiresAuth: true },
	{ id: 'about', label: 'About this instance', labelKey: 'nav.about', icon: 'info', href: '#/about', group: 'instance' },
	{ id: 'admin', label: 'Administration', labelKey: 'nav.administration', icon: 'shield', href: '/admin', group: 'instance', adminOnly: true },
	{ id: 'dashboard', label: 'Server dashboard', labelKey: 'nav.dashboard', icon: 'server', href: '/', group: 'instance' },
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
