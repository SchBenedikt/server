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
	| { name: 'lists' }
	| { name: 'list'; id: string }
	| { name: 'muted' }
	| { name: 'blocked' }
	| { name: 'filters' }
	| { name: 'scheduled' }
	| { name: 'messages' }
	| { name: 'conversation'; id: string }
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
			return 'Startseite';
		case 'explore':
			return 'Entdecken';
		case 'notifications':
			return 'Benachrichtigungen';
		case 'about':
			return 'Über diese Instanz';
		case 'profile':
			return `@${current.handle}`;
		case 'post':
			return 'Beitrag';
		case 'tag':
			return `#${current.tag}`;
		case 'bookmarks':
			return 'Lesezeichen';
		case 'search':
			return 'Suchen';
		case 'lists':
			return 'Listen';
		case 'list':
			return 'Liste';
		case 'muted':
			return 'Stummgeschaltet';
		case 'blocked':
			return 'Blockiert';
		case 'filters':
			return 'Filter';
		case 'scheduled':
			return 'Geplante Beiträge';
		case 'admin':
			return current.section === 'overview'
				? 'Administration'
				: `Administration · ${adminSectionDefinition(current.section).label}`;
		default:
			return 'Nicht gefunden';
	}
}
