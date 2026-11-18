/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { reactive } from 'vue';
import type {
	Account,
	Attachment,
	Draft,
	InstanceInfo,
	SessionUser,
	ThemeName,
	TimelineItem,
	ToastMessage,
	ToastTone,
} from './types';

const THEME_KEY = 'aloha.theme';
const HOME_TAB_KEY = 'aloha.home-tab';

type HomeTab = 'home' | 'for-you' | 'trending' | 'public' | 'tags';

function initialTheme(): ThemeName {
	try {
		const stored = localStorage.getItem(THEME_KEY);
		if (stored === 'dark' || stored === 'light') {
			return stored;
		}
	} catch {
		// Storage can be unavailable; follow the operating system below.
	}

	return typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches
		? 'dark'
		: 'light';
}

function initialHomeTab(): HomeTab {
	try {
		const stored = localStorage.getItem(HOME_TAB_KEY);
		if (stored === 'home' || stored === 'for-you' || stored === 'trending' || stored === 'public' || stored === 'tags') {
			return stored;
		}
	} catch {
		// A private window without storage simply starts on Home.
	}

	return 'home';
}

export const appState = reactive({
	session: {
		ready: false,
		user: null as SessionUser | null,
		profile: null as Account | null,
		admin: false,
	},
	instance: null as InstanceInfo | null,
	instanceError: false,
	unread: 0,
	composer: {
		open: false,
		replyTo: null as TimelineItem | null,
		quoteTo: null as TimelineItem | null,
		editPost: null as TimelineItem | null,
		directTo: null as Account | null,
		draft: null as Draft | null,
	},
	lightbox: {
		open: false,
		attachments: [] as Attachment[],
		index: 0,
	},
	toasts: [] as ToastMessage[],
	homeTab: initialHomeTab(),
	theme: initialTheme() as ThemeName,
	mobileMenuOpen: false,
});

let toastSequence = 0;

export function showToast(message: string, tone: ToastTone = 'info'): number {
	const id = ++toastSequence;
	appState.toasts.push({ id, message, tone });

	window.setTimeout(() => {
		const index = appState.toasts.findIndex((toast) => toast.id === id);
		if (index !== -1) {
			appState.toasts.splice(index, 1);
		}
	}, 4600);

	return id;
}

export function dismissToast(id: number): void {
	const index = appState.toasts.findIndex((toast) => toast.id === id);
	if (index !== -1) {
		appState.toasts.splice(index, 1);
	}
}

export function setHomeTab(tab: HomeTab): void {
	appState.homeTab = tab;
	try {
		localStorage.setItem(HOME_TAB_KEY, tab);
	} catch {
		// The choice still applies to this page.
	}
}

export function openComposer(
	replyTo: TimelineItem | null = null,
	quoteTo: TimelineItem | null = null,
	directTo: Account | null = null,
): void {
	appState.composer.replyTo = replyTo;
	appState.composer.quoteTo = quoteTo;
	appState.composer.directTo = directTo;
	appState.composer.editPost = null;
	appState.composer.draft = null;
	appState.composer.open = true;
}

/**
 * Opens the composer with a saved draft restored.
 */
export function openDraft(draft: Draft): void {
	appState.composer.replyTo = null;
	appState.composer.quoteTo = null;
	appState.composer.directTo = null;
	appState.composer.editPost = null;
	appState.composer.draft = draft;
	appState.composer.open = true;
}

/**
 * Opens the composer with an embedded quote of another post.
 */
export function openQuote(quoteTo: TimelineItem): void {
	openComposer(null, quoteTo);
}

/**
 * Opens the composer as a private direct message to one local account.
 */
export function openDirectMessage(account: Account): void {
	openComposer(null, null, account);
}

/**
 * Opens the composer in edit mode for one of the signed-in account's posts.
 */
export function openEditor(post: TimelineItem): void {
	appState.composer.replyTo = null;
	appState.composer.quoteTo = null;
	appState.composer.directTo = null;
	appState.composer.editPost = post;
	appState.composer.draft = null;
	appState.composer.open = true;
}

export function closeComposer(): void {
	appState.composer.open = false;
	appState.composer.replyTo = null;
	appState.composer.quoteTo = null;
	appState.composer.directTo = null;
	appState.composer.editPost = null;
	appState.composer.draft = null;
}

export function openLightbox(attachments: Attachment[], index: number): void {
	if (attachments.length === 0) {
		return;
	}

	appState.lightbox.attachments = attachments;
	appState.lightbox.index = Math.min(Math.max(0, index), attachments.length - 1);
	appState.lightbox.open = true;
}

export function closeLightbox(): void {
	appState.lightbox.open = false;
}

export function applyTheme(theme: ThemeName): void {
	appState.theme = theme;
	document.documentElement.dataset.theme = theme;
	document.documentElement.style.colorScheme = theme;

	try {
		localStorage.setItem(THEME_KEY, theme);
	} catch {
		// The theme applies for this page even when it cannot be remembered.
	}
}

export function toggleTheme(): void {
	applyTheme(appState.theme === 'dark' ? 'light' : 'dark');
}

export function initTheme(): void {
	applyTheme(appState.theme);
}

export function setInstance(instance: InstanceInfo | null): void {
	appState.instance = instance;
	appState.instanceError = instance === null;
}

export function setSession(user: SessionUser | null, profile: Account | null = null, admin = false): void {
	appState.session.user = user;
	appState.session.profile = profile;
	appState.session.admin = user !== null && admin;
	appState.session.ready = true;
}

export function setUnread(count: number): void {
	appState.unread = Math.max(0, Math.trunc(count));
}
