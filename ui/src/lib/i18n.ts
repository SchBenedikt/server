/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref } from 'vue';

/**
 * UI localization.
 *
 * The account's `ui_locale` preference arrives through the mount dataset
 * (`data-locale`); `null`/empty means "follow the browser". `resolveLocale`
 * maps a BCP-47 tag onto a shipped catalog by primary subtag, falling back
 * to English. `setLocale` also keeps `<html lang>` in sync so assistive
 * tech and browser translate see the active language.
 *
 * `t()` looks the key up in the active catalog and then English; missing
 * keys render the key itself so gaps are visible during development rather
 * than silently blank. `{name}` placeholders interpolate params.
 */

type Messages = Record<string, string>;

const en: Messages = {
	'time.justNow': 'just now',
	'settings.language.label': 'Language',
	'settings.language.hint': 'Interface language. Dates and numbers follow this choice too.',
	'settings.language.browser': 'Browser default',
	'settings.language.english': 'English',
	'settings.language.german': 'Deutsch',
	'nav.home': 'Home',
	'nav.explore': 'Explore',
	'nav.notifications': 'Notifications',
	'nav.messages': 'Messages',
	'nav.profile': 'Profile',
	'nav.settings': 'Settings',
	'nav.search': 'Search',
	'nav.kokua': 'Kokua board',
	'nav.memories': 'Memories',
	'nav.bookmarks': 'Bookmarks',
	'nav.collections': 'Collections',
	'nav.starterPacks': 'Starter packs',
	'nav.lists': 'Lists',
	'nav.drafts': 'Drafts',
	'nav.archive': 'Archive',
	'nav.scheduled': 'Scheduled posts',
	'nav.followRequests': 'Follow requests',
	'nav.filters': 'Filters',
	'nav.muted': 'Muted',
	'nav.blocked': 'Blocked',
	'nav.about': 'About this instance',
	'nav.administration': 'Administration',
	'nav.dashboard': 'Server dashboard',
	'nav.signIn': 'Sign in',
	'nav.group.discover': 'Discover',
	'nav.group.library': 'Library',
	'nav.group.moderation': 'Moderation',
	'nav.group.instance': 'Instance',
	'nav.group.account': 'Your account',
};

const de: Messages = {
	'time.justNow': 'gerade eben',
	'settings.language.label': 'Sprache',
	'settings.language.hint': 'Sprache der Oberfläche. Datums- und Zahlenformate folgen dieser Wahl.',
	'settings.language.browser': 'Browser-Standard',
	'settings.language.english': 'English',
	'settings.language.german': 'Deutsch',
	'nav.home': 'Start',
	'nav.explore': 'Entdecken',
	'nav.notifications': 'Benachrichtigungen',
	'nav.messages': 'Nachrichten',
	'nav.profile': 'Profil',
	'nav.settings': 'Einstellungen',
	'nav.search': 'Suche',
	'nav.kokua': 'Kokua-Board',
	'nav.memories': 'Erinnerungen',
	'nav.bookmarks': 'Lesezeichen',
	'nav.collections': 'Sammlungen',
	'nav.starterPacks': 'Starter-Packs',
	'nav.lists': 'Listen',
	'nav.drafts': 'Entwürfe',
	'nav.archive': 'Archiv',
	'nav.scheduled': 'Geplante Beiträge',
	'nav.followRequests': 'Follow-Anfragen',
	'nav.filters': 'Filter',
	'nav.muted': 'Stummgeschaltet',
	'nav.blocked': 'Blockiert',
	'nav.about': 'Über diese Instanz',
	'nav.administration': 'Verwaltung',
	'nav.dashboard': 'Server-Dashboard',
	'nav.signIn': 'Anmelden',
	'nav.group.discover': 'Entdecken',
	'nav.group.library': 'Deine Inhalte',
	'nav.group.moderation': 'Moderation',
	'nav.group.instance': 'Instanz',
	'nav.group.account': 'Dein Konto',
};

const catalogs: Record<string, Messages> = { en, de };

export const locale = ref('en');

export const availableLocales = computed(() => Object.keys(catalogs));

function browserLocales(): string[] {
	if (typeof navigator === 'undefined') {
		return [];
	}

	const languages = navigator.languages;
	if (Array.isArray(languages) && languages.length > 0) {
		return languages.filter((entry): entry is string => typeof entry === 'string');
	}

	return typeof navigator.language === 'string' && navigator.language !== ''
		? [navigator.language]
		: [];
}

export function resolveLocale(preference?: string | null): string {
	const candidates = [
		...(preference ? [preference] : []),
		...browserLocales(),
		'en',
	];

	for (const candidate of candidates) {
		const tag = candidate.trim();
		if (tag === '') {
			continue;
		}

		const exact = tag.toLowerCase();
		if (catalogs[exact] !== undefined) {
			return exact;
		}

		const primary = exact.split('-')[0] ?? '';
		if (catalogs[primary] !== undefined) {
			return primary;
		}
	}

	return 'en';
}

export function setLocale(preference?: string | null): void {
	const resolved = resolveLocale(preference);
	locale.value = resolved;
	if (typeof document !== 'undefined') {
		document.documentElement.lang = resolved;
	}
}

/**
 * Resolves a catalog-backed label. Items carry an English `label` fallback
 * plus an optional `labelKey`; calling this during render tracks `locale`,
 * so labels re-render when the language changes.
 */
export function labelFor(item: { label: string; labelKey?: string }): string {
	if (item.labelKey === undefined) {
		return item.label;
	}

	const catalog = catalogs[locale.value] ?? catalogs.en;
	return catalog[item.labelKey] ?? catalogs.en[item.labelKey] ?? item.label;
}

export function t(key: string, params?: Record<string, string | number>): string {
	const template = catalogs[locale.value]?.[key] ?? catalogs.en[key] ?? key;
	if (params === undefined) {
		return template;
	}

	return template.replace(/\{(\w+)\}/g, (match, name: string) =>
		params[name] === undefined ? match : String(params[name]),
	);
}
