/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest';
import { labelFor, locale, resolveLocale, setLocale, t } from './i18n';

function stubBrowserLanguage(tag: string): void {
	vi.stubGlobal('navigator', { language: tag, languages: [tag] });
}

describe('resolveLocale', () => {
	it('prefers the account preference over the browser', () => {
		expect(resolveLocale('de')).toBe('de');
	});

	it('maps regional tags onto shipped catalogs', () => {
		expect(resolveLocale('de-AT')).toBe('de');
		expect(resolveLocale('de-DE')).toBe('de');
		expect(resolveLocale('en-GB')).toBe('en');
	});

	it('falls back through the browser list to a shipped catalog', () => {
		stubBrowserLanguage('fr-FR');
		expect(resolveLocale('xx-YY')).toBe('en');
		expect(resolveLocale('')).toBe('en');
		expect(resolveLocale(null)).toBe('en');

		stubBrowserLanguage('de-CH');
		expect(resolveLocale(null)).toBe('de');
		vi.unstubAllGlobals();
	});
});

describe('t', () => {
	it('translates known keys and falls back to English', () => {
		setLocale('de');
		expect(t('time.justNow')).toBe('gerade eben');
		setLocale('en');
		expect(t('time.justNow')).toBe('just now');
		expect(t('missing.key')).toBe('missing.key');
	});

	it('interpolates params into the resolved template', () => {
		setLocale('en');
		expect(t('{missing}', { missing: 'x' })).toBe('x');
		expect(t('{missing}', {})).toBe('{missing}');
	});

	it('exposes the resolved locale', () => {
		setLocale('de');
		expect(locale.value).toBe('de');
		setLocale(null);
		expect(['en', 'de']).toContain(locale.value);
	});
});

describe('labelFor', () => {
	it('prefers the catalog key and falls back to the literal label', () => {
		setLocale('de');
		expect(labelFor({ label: 'Home', labelKey: 'nav.home' })).toBe('Start');
		expect(labelFor({ label: 'Home' })).toBe('Home');
		expect(labelFor({ label: 'Home', labelKey: 'nav.missing' })).toBe('Home');
		setLocale('en');
		expect(labelFor({ label: 'Home', labelKey: 'nav.home' })).toBe('Home');
	});
});
