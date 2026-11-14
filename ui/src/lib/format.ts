/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Account, TimelineItem } from './types';

const relative = new Intl.RelativeTimeFormat('de', { numeric: 'auto' });

const absoluteDate = new Intl.DateTimeFormat('de-DE', {
	dateStyle: 'medium',
	timeStyle: 'short',
});

const compactNumber = new Intl.NumberFormat('de-DE', {
	notation: 'compact',
	maximumFractionDigits: 1,
});

export function relativeTime(value: string): string {
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return value;
	}

	const diff = date.getTime() - Date.now();
	const seconds = Math.round(diff / 1000);
	const absolute = Math.abs(seconds);

	if (absolute < 45) {
		return 'gerade eben';
	}
	if (absolute < 3600) {
		return relative.format(Math.round(seconds / 60), 'minute');
	}
	if (absolute < 86_400) {
		return relative.format(Math.round(seconds / 3600), 'hour');
	}
	if (absolute < 604_800) {
		return relative.format(Math.round(seconds / 86_400), 'day');
	}

	return absoluteDate.format(date);
}

export function absoluteTime(value: string): string {
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return value;
	}

	return absoluteDate.format(date);
}

export function formatCount(value: number): string {
	if (!Number.isFinite(value)) {
		return '0';
	}

	return compactNumber.format(Math.max(0, value));
}

export function isRemoteId(id: string): boolean {
	return /^https?:\/\//i.test(id);
}

/**
 * Media URLs are built from the instance URL by the core. When the public
 * origin differs from the host the PHP process sees (a preview proxy or a
 * reverse proxy), keep the path but serve it from the origin the reader is on.
 */
export function mediaUrl(value: string): string {
	try {
		const url = new URL(value, window.location.origin);
		const local = url.hostname === 'localhost' || url.hostname === '127.0.0.1' || url.hostname === '::1';
		if (local && url.origin !== window.location.origin) {
			return `${window.location.origin}${url.pathname}${url.search}`;
		}

		return value;
	} catch {
		return value;
	}
}

export function handleLabel(id: string): string {
	if (id === '') {
		return '@unbekannt';
	}

	if (isRemoteId(id)) {
		try {
			const url = new URL(id);
			const last = url.pathname.split('/').filter(Boolean).pop() ?? url.host;
			const name = decodeURIComponent(last).replace(/^@/, '');
			return `@${name}@${url.host}`;
		} catch {
			return id;
		}
	}

	return `@${id.replace(/^@/, '')}`;
}

export function accountName(account: Account | null | undefined, fallbackId: string): string {
	const name = account?.display_name?.trim() ?? '';
	if (name !== '' && name !== account?.id) {
		return name;
	}

	return handleLabel(fallbackId);
}

export function avatarInitials(name: string | undefined, fallbackId: string): string {
	const source = (name?.trim() !== '' ? name : '') || fallbackId;
	const cleaned = source
		.replace(/^@/, '')
		.replace(/@[^@]+$/, '')
		.replace(/[^\p{L}\p{N}]+/gu, ' ')
		.trim();

	if (cleaned === '') {
		return '?';
	}

	const parts = cleaned.split(/\s+/).filter(Boolean);
	const value = parts.length === 1
		? Array.from(parts[0]).slice(0, 2).join('')
		: Array.from(parts[0])[0] + Array.from(parts[1])[0];

	return value.toUpperCase();
}

export function avatarHue(id: string): number {
	let hash = 0;
	for (const character of id) {
		hash = (hash * 31 + character.codePointAt(0)!) % 360;
	}

	return hash;
}

export function avatarStyle(id: string): Record<string, string> {
	const palettes = [
		['#e8604c', '#b14330'],
		['#1f6f80', '#0f4c5c'],
		['#7c9a72', '#4f6b4b'],
		['#d29a4a', '#9a6412'],
		['#c16a78', '#8e3f50'],
		['#4b8f9c', '#1c5b68'],
	];
	const [start, end] = palettes[avatarHue(id || 'aloha') % palettes.length];

	return {
		background: `radial-gradient(circle at 28% 18%, rgba(255, 255, 255, 0.24), transparent 46%), linear-gradient(145deg, ${start}, ${end})`,
	};
}

export function isLocalPostId(id: string | null | undefined): boolean {
	return typeof id === 'string' && id !== '' && !isRemoteId(id);
}

export function postRouteId(post: TimelineItem): string | null {
	if (isLocalPostId(post.object_id)) {
		return post.object_id;
	}

	return isLocalPostId(post.id) ? post.id : null;
}

export function postHref(post: TimelineItem): string | null {
	const id = postRouteId(post);
	return id === null ? null : `#/p/${encodeURIComponent(id)}`;
}

export function postExternalUrl(post: TimelineItem): string | null {
	const candidate = post.object_id ?? post.id;
	return isRemoteId(candidate) ? candidate : null;
}

export function extractTags(content: string): string[] {
	const tags = new Set<string>();
	for (const match of content.matchAll(/#([\p{L}\p{N}_]{1,100})/gu)) {
		tags.add(match[1].toLowerCase());
	}

	return [...tags];
}

export function visibilityLabel(visibility: string | null | undefined): string {
	switch (visibility) {
		case 'unlisted':
			return 'Nicht gelistet';
		case 'followers':
			return 'Nur Follower';
		case 'direct':
			return 'Direktnachricht';
		default:
			return 'Öffentlich';
	}
}

export function plural(count: number, singular: string, pluralForm: string): string {
	return count === 1 ? singular : pluralForm;
}

export function truncate(value: string, length: number): string {
	const characters = Array.from(value);
	return characters.length <= length ? value : `${characters.slice(0, length - 1).join('')}…`;
}

export function normalizeTag(value: string): string {
	return value.trim().replace(/^#/, '').toLowerCase();
}
