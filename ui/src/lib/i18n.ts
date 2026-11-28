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
	'common.back': 'Back',
	'common.cancel': 'Cancel',
	'common.confirm': 'Confirm',
	'common.confirmation': 'Confirmation',
	'common.running': 'Running…',
	'common.close': 'Close',
	'common.closeDialog': 'Close dialog',
	'common.dismiss': 'Dismiss notice',
	'common.more': 'More',
	'common.moreSections': 'More sections',
	'common.allSections': 'All sections',
	'common.lightMode': 'Light mode',
	'common.darkMode': 'Dark mode',
	'common.signOut': 'Sign out',
	'common.signingOut': 'Signing out…',
	'common.newPost': 'New post',
	'common.previousImage': 'Previous image',
	'common.nextImage': 'Next image',
	'common.imageView': 'Image view',
	'common.customizeSidebar': 'Customize sidebar',
	'common.signInToTakePart': 'Sign in to take part',
	'common.openAndFederated': 'Open & federated',
	'common.guestTakePart': 'You are reading as a guest. Sign in to take part.',
	'common.myProfile': 'My profile',
	'common.toggleTheme': 'Toggle color theme',
	'common.accountMenuFor': 'Account menu for {name}',
	'common.mainNavigation': 'Main navigation',
	'common.mainSections': 'Main sections',
	'common.mobileNavigation': 'Mobile navigation',
	'common.save': 'Save',
	'sidebar.customize': 'Customize',
	'sidebar.title': 'Sidebar',
	'sidebar.hint': 'Choose up to {max} entries for the main navigation. Everything else stays reachable under “More”.',
	'sidebar.mainNav': 'Main navigation',
	'sidebar.moreEntries': 'More entries',
	'sidebar.empty': 'No entries pinned. Pick from the list below.',
	'sidebar.pin': 'Pin',
	'sidebar.reset': 'Reset',
	'sidebar.moveUp': 'Move {name} up',
	'sidebar.moveDown': 'Move {name} down',
	'sidebar.removeFromNav': 'Remove {name} from the main navigation',
	'sidebar.maxReached': 'The main navigation holds at most {max} entries.',
	'sidebar.saved': 'Sidebar layout saved.',
	'sidebar.resetDone': 'Sidebar reset to the default.',
	'delivery.title': 'Delivery',
	'delivery.none': 'No remote deliveries recorded yet.',
	'delivery.delivered': 'delivered',
	'delivery.sending': 'sending',
	'delivery.waiting': 'waiting',
	'delivery.failing': 'failing',
	'delivery.abandoned': 'abandoned',
	'delivery.instances': 'Instances',
	'delivery.host': 'Instance',
	'delivery.state': 'State',
	'delivery.tries': 'Tries',
	'delivery.last': 'Last update',
	'report.eyebrow': 'Moderation',
	'report.titlePost': 'Report post',
	'report.titleProfile': 'Report profile',
	'report.introPost': 'You are reporting this post',
	'report.introProfile': 'You are reporting this profile',
	'report.introSuffix': '. The report is not visible to other users.',
	'report.reason': 'Reason',
	'report.details': 'Additional details',
	'report.optional': '(optional)',
	'report.detailsHint': 'What exactly is the problem? Links or context help with the review.',
	'report.chars': '{count} / 1000 characters',
	'report.sending': 'Sending…',
	'report.send': 'Send report',
	'report.received': 'Report received. The administration will review it.',
	'report.targetMissing': 'The report target is missing.',
	'report.commentTooLong': 'A comment may be at most 1000 characters long.',
	'report.sendFailed': 'The report could not be sent.',
	'report.cat.spam.label': 'Spam or advertising',
	'report.cat.spam.hint': 'Unsolicited advertising, repetition or bots.',
	'report.cat.abuse.label': 'Abuse',
	'report.cat.abuse.hint': 'Insults, threats or discriminatory content.',
	'report.cat.harassment.label': 'Harassment',
	'report.cat.harassment.hint': 'Targeted stalking, public shaming or intimidation.',
	'report.cat.misinformation.label': 'Misinformation',
	'report.cat.misinformation.hint': 'Demonstrably false or misleading claims.',
	'report.cat.other.label': 'Other',
	'report.cat.other.hint': 'Another reason you describe below.',
	'report.state.open': 'Open',
	'report.state.resolved': 'Resolved',
	'report.state.dismissed': 'Dismissed',
	'rev.title': 'Edit history',
	'rev.loading': 'Loading history…',
	'rev.loadFailed': 'The edit history could not be loaded.',
	'rev.version': 'Version {number}',
	'rev.current': 'Current',
	'rev.unedited': 'This post has not been edited yet.',
	'coll.eyebrow': 'collections',
	'coll.title': 'Add to collection',
	'coll.loading': 'Loading collections…',
	'coll.loadFailed': 'The collections could not be loaded.',
	'coll.empty': "You don't have any collections yet.",
	'coll.posts': '{count} Posts',
	'coll.post': '{count} Post',
	'coll.namePlaceholder': 'Name of the new collection',
	'coll.create': 'Create & add',
	'coll.creating': 'Create …',
	'coll.createFailed': 'The collection could not be created.',
	'coll.new': 'New collection',
	'coll.added': 'Added to “{title}”.',
	'coll.addFailed': 'The post could not be added.',
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
	'common.back': 'Zurück',
	'common.cancel': 'Abbrechen',
	'common.confirm': 'Bestätigen',
	'common.confirmation': 'Bestätigung',
	'common.running': 'Läuft…',
	'common.close': 'Schließen',
	'common.closeDialog': 'Dialog schließen',
	'common.dismiss': 'Hinweis schließen',
	'common.more': 'Mehr',
	'common.moreSections': 'Weitere Bereiche',
	'common.allSections': 'Alle Bereiche',
	'common.lightMode': 'Heller Modus',
	'common.darkMode': 'Dunkler Modus',
	'common.signOut': 'Abmelden',
	'common.signingOut': 'Abmeldung…',
	'common.newPost': 'Neuer Beitrag',
	'common.previousImage': 'Vorheriges Bild',
	'common.nextImage': 'Nächstes Bild',
	'common.imageView': 'Bildansicht',
	'common.customizeSidebar': 'Seitenleiste anpassen',
	'common.signInToTakePart': 'Anmelden, um mitzumachen',
	'common.openAndFederated': 'Offen & föderiert',
	'common.guestTakePart': 'Du liest als Gast. Melde dich an, um mitzumachen.',
	'common.myProfile': 'Mein Profil',
	'common.toggleTheme': 'Farbdesign umschalten',
	'common.accountMenuFor': 'Kontomenü für {name}',
	'common.mainNavigation': 'Hauptnavigation',
	'common.mainSections': 'Hauptbereiche',
	'common.mobileNavigation': 'Mobile Navigation',
	'common.save': 'Speichern',
	'sidebar.customize': 'Anpassen',
	'sidebar.title': 'Seitenleiste',
	'sidebar.hint': 'Wähle bis zu {max} Einträge für die Hauptnavigation. Alles andere bleibt unter „Mehr“ erreichbar.',
	'sidebar.mainNav': 'Hauptnavigation',
	'sidebar.moreEntries': 'Weitere Einträge',
	'sidebar.empty': 'Keine Einträge angeheftet. Wähle aus der Liste unten.',
	'sidebar.pin': 'Anheften',
	'sidebar.reset': 'Zurücksetzen',
	'sidebar.moveUp': '{name} nach oben verschieben',
	'sidebar.moveDown': '{name} nach unten verschieben',
	'sidebar.removeFromNav': '{name} aus der Hauptnavigation entfernen',
	'sidebar.maxReached': 'Die Hauptnavigation fasst höchstens {max} Einträge.',
	'sidebar.saved': 'Seitenleisten-Layout gespeichert.',
	'sidebar.resetDone': 'Seitenleiste auf den Standard zurückgesetzt.',
	'delivery.title': 'Zustellung',
	'delivery.none': 'Noch keine Remote-Zustellungen erfasst.',
	'delivery.delivered': 'zugestellt',
	'delivery.sending': 'wird gesendet',
	'delivery.waiting': 'wartet',
	'delivery.failing': 'schlägt fehl',
	'delivery.abandoned': 'aufgegeben',
	'delivery.instances': 'Instanzen',
	'delivery.host': 'Instanz',
	'delivery.state': 'Status',
	'delivery.tries': 'Versuche',
	'delivery.last': 'Letzte Aktualisierung',
	'report.eyebrow': 'Moderation',
	'report.titlePost': 'Beitrag melden',
	'report.titleProfile': 'Profil melden',
	'report.introPost': 'Du meldest den Beitrag',
	'report.introProfile': 'Du meldest das Profil',
	'report.introSuffix': '. Die Meldung ist für andere Nutzer nicht sichtbar.',
	'report.reason': 'Grund',
	'report.details': 'Zusätzliche Details',
	'report.optional': '(optional)',
	'report.detailsHint': 'Was genau ist das Problem? Links oder Kontext helfen bei der Prüfung.',
	'report.chars': '{count} / 1000 Zeichen',
	'report.sending': 'Wird gesendet…',
	'report.send': 'Meldung senden',
	'report.received': 'Meldung eingegangen. Die Administration prüft sie.',
	'report.targetMissing': 'Das Meldeziel fehlt.',
	'report.commentTooLong': 'Ein Kommentar darf höchstens 1000 Zeichen lang sein.',
	'report.sendFailed': 'Die Meldung konnte nicht gesendet werden.',
	'report.cat.spam.label': 'Spam oder Werbung',
	'report.cat.spam.hint': 'Unerwünschte Werbung, Wiederholungen oder Bots.',
	'report.cat.abuse.label': 'Missbrauch',
	'report.cat.abuse.hint': 'Beleidigungen, Drohungen oder diskriminierende Inhalte.',
	'report.cat.harassment.label': 'Belästigung',
	'report.cat.harassment.hint': 'Gezieltes Stalking, Bloßstellung oder Einschüchterung.',
	'report.cat.misinformation.label': 'Fehlinformationen',
	'report.cat.misinformation.hint': 'Nachweislich falsche oder irreführende Angaben.',
	'report.cat.other.label': 'Sonstiges',
	'report.cat.other.hint': 'Ein anderer Grund, den du unten beschreibst.',
	'report.state.open': 'Offen',
	'report.state.resolved': 'Gelöst',
	'report.state.dismissed': 'Verworfen',
	'rev.title': 'Bearbeitungsverlauf',
	'rev.loading': 'Verlauf wird geladen…',
	'rev.loadFailed': 'Der Bearbeitungsverlauf konnte nicht geladen werden.',
	'rev.version': 'Version {number}',
	'rev.current': 'Aktuell',
	'rev.unedited': 'Dieser Beitrag wurde noch nicht bearbeitet.',
	'coll.eyebrow': 'sammlungen',
	'coll.title': 'Zu Sammlung hinzufügen',
	'coll.loading': 'Sammlungen werden geladen…',
	'coll.loadFailed': 'Die Sammlungen konnten nicht geladen werden.',
	'coll.empty': 'Du hast noch keine Sammlungen.',
	'coll.posts': '{count} Beiträge',
	'coll.post': '{count} Beitrag',
	'coll.namePlaceholder': 'Name der neuen Sammlung',
	'coll.create': 'Erstellen & hinzufügen',
	'coll.creating': 'Erstellen …',
	'coll.createFailed': 'Die Sammlung konnte nicht erstellt werden.',
	'coll.new': 'Neue Sammlung',
	'coll.added': 'Zu „{title}“ hinzugefügt.',
	'coll.addFailed': 'Der Beitrag konnte nicht hinzugefügt werden.',
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
