/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref } from 'vue';

export type Route =
	| { name: 'home' }
	| { name: 'explore' }
	| { name: 'notifications' }
	| { name: 'about' }
	| { name: 'profile'; handle: string }
	| { name: 'post'; id: string }
	| { name: 'tag'; tag: string }
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
		default:
			return 'Nicht gefunden';
	}
}
