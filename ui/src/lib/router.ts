/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref } from 'vue';
import { adminSectionDefinition, isAdminSection } from './admin';
import type { AdminSection } from './admin';

export type Route =
	| { name: 'home' }
	| { name: 'explore' }
	| { name: 'notifications' }
	| { name: 'about' }
	| { name: 'profile'; handle: string }
	| { name: 'post'; id: string }
	| { name: 'tag'; tag: string }
	| { name: 'bookmarks' }
	| { name: 'search' }
	| { name: 'kokua' }
	| { name: 'lists' }
	| { name: 'list'; id: string }
	| { name: 'muted' }
	| { name: 'blocked' }
	| { name: 'filters' }
	| { name: 'scheduled' }
	| { name: 'messages' }
	| { name: 'conversation'; id: string }
	| { name: 'memories' }
	| { name: 'collections' }
	| { name: 'collection'; id: string }
	| { name: 'drafts' }
	| { name: 'archived' }
	| { name: 'settings' }
	| { name: 'follow-requests' }
	| { name: 'admin'; section: AdminSection }
	| { name: 'notfound' };

function decode(value: string): string {
	try {
		return decodeURIComponent(value);
	} catch {
		return value;
	}
}

export function parseHash(hash: string): Route {
	const path = hash.replace(/^#/, '').replace(/\?.*$/, '');
	const parts = path.split('/').filter(Boolean);

	if (parts.length === 0) {
		return { name: 'home' };
	}

	if (parts[0] === 'explore' && parts.length === 1) {
		return { name: 'explore' };
	}

	if (parts[0] === 'notifications' && parts.length === 1) {
		return { name: 'notifications' };
	}

	if (parts[0] === 'about' && parts.length === 1) {
		return { name: 'about' };
	}

	if (parts[0] === 'bookmarks' && parts.length === 1) {
		return { name: 'bookmarks' };
	}

	if (parts[0] === 'search' && parts.length === 1) {
		return { name: 'search' };
	}

	if (parts[0] === 'kokua' && parts.length === 1) {
		return { name: 'kokua' };
	}

	if (parts[0] === 'lists' && parts.length === 1) {
		return { name: 'lists' };
	}

	if (parts[0] === 'l' && parts[1] !== undefined) {
		return { name: 'list', id: decode(parts[1]) };
	}

	if (parts[0] === 'muted' && parts.length === 1) {
		return { name: 'muted' };
	}

	if (parts[0] === 'blocked' && parts.length === 1) {
		return { name: 'blocked' };
	}

	if (parts[0] === 'filters' && parts.length === 1) {
		return { name: 'filters' };
	}

	if (parts[0] === 'scheduled' && parts.length === 1) {
		return { name: 'scheduled' };
	}

	if (parts[0] === 'messages' && parts.length === 1) {
		return { name: 'messages' };
	}

	if (parts[0] === 'messages' && parts[1] !== undefined) {
		return { name: 'conversation', id: decode(parts[1]) };
	}

	if (parts[0] === 'memories' && parts.length === 1) {
		return { name: 'memories' };
	}

	if (parts[0] === 'drafts' && parts.length === 1) {
		return { name: 'drafts' };
	}

	if (parts[0] === 'archived' && parts.length === 1) {
		return { name: 'archived' };
	}

	if (parts[0] === 'settings' && parts.length === 1) {
		return { name: 'settings' };
	}

	if (parts[0] === 'follow-requests' && parts.length === 1) {
		return { name: 'follow-requests' };
	}

	if (parts[0] === 'collections' && parts.length === 1) {
		return { name: 'collections' };
	}

	if (parts[0] === 'c' && parts[1] !== undefined) {
		return { name: 'collection', id: decode(parts[1]) };
	}

	if (parts[0] === 'admin') {
		const section = parts[1] !== undefined && isAdminSection(parts[1]) ? parts[1] : 'overview';
		return { name: 'admin', section };
	}

	if (parts[0] === 'u' && parts[1] !== undefined) {
		return { name: 'profile', handle: decode(parts[1]) };
	}

	if (parts[0] === 'p' && parts[1] !== undefined) {
		return { name: 'post', id: decode(parts[1]) };
	}

	if (parts[0] === 'tag' && parts[1] !== undefined) {
		return { name: 'tag', tag: decode(parts[1]).replace(/^#/, '') };
	}

	return { name: 'notfound' };
}

export const route = ref<Route>(parseHash(typeof window === 'undefined' ? '' : window.location.hash));

export function navigate(path: string): void {
	const normalized = path.startsWith('/') ? path : `/${path}`;
	const target = `#${normalized}`;

	if (window.location.hash === target) {
		route.value = parseHash(target);
		return;
	}

	window.location.hash = target;
}

export function openPath(path: string): void {
	navigate(path);
}

export function hrefFor(path: string): string {
	return `#${path.startsWith('/') ? path : `/${path}`}`;
}

export function initRouter(): () => void {
	const update = (): void => {
		route.value = parseHash(window.location.hash);
	};

	window.addEventListener('hashchange', update);

	return () => window.removeEventListener('hashchange', update);
}

export function isActiveRoute(current: Route, name: Route['name']): boolean {
	return current.name === name;
}

export function routeTitle(current: Route): string {
	switch (current.name) {
		case 'home':
			return 'Home';
		case 'explore':
			return 'Explore';
		case 'notifications':
			return 'Notifications';
		case 'about':
			return 'About this instance';
		case 'profile':
			return `@${current.handle}`;
		case 'post':
			return 'Post';
		case 'tag':
			return `#${current.tag}`;
		case 'bookmarks':
			return 'bookmarks';
		case 'search':
			return 'Search';
		case 'kokua':
			return 'Kokua';
		case 'lists':
			return 'lists';
		case 'list':
			return 'list';
		case 'muted':
			return 'Muted';
		case 'blocked':
			return 'Blocked';
		case 'filters':
			return 'filter';
		case 'scheduled':
			return 'Scheduled posts';
		case 'memories':
			return 'memories';
		case 'collections':
			return 'collections';
		case 'collection':
			return 'collection';
		case 'drafts':
			return 'Drafts';
		case 'archived':
			return 'Archive';
		case 'settings':
			return 'Settings';
		case 'follow-requests':
			return 'Follow requests';
		case 'admin':
			return current.section === 'overview'
				? 'administration'
				: `administration · ${adminSectionDefinition(current.section).label}`;
		default:
			return 'Not found';
	}
}
