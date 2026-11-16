<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useHostBridge } from '@alohasocial/ui';
import type { AlohaHostBridge } from './bridge';
import { adminSession } from './bridge';
import AppIcon from './components/AppIcon.vue';
import ComposerModal from './components/ComposerModal.vue';
import Lightbox from './components/Lightbox.vue';
import MobileNav from './components/MobileNav.vue';
import RightRail from './components/RightRail.vue';
import SidebarNav from './components/SidebarNav.vue';
import ToastHost from './components/ToastHost.vue';
import AboutView from './views/AboutView.vue';
import AdminView from './views/AdminView.vue';
import BlocksView from './views/BlocksView.vue';
import BookmarksView from './views/BookmarksView.vue';
import ConversationView from './views/ConversationView.vue';
import ExploreView from './views/ExploreView.vue';
import FiltersView from './views/FiltersView.vue';
import HomeView from './views/HomeView.vue';
import ListView from './views/ListView.vue';
import ListsView from './views/ListsView.vue';
import MutesView from './views/MutesView.vue';
import MemoriesView from './views/MemoriesView.vue';
import MessagesView from './views/MessagesView.vue';
import ArchivedView from './views/ArchivedView.vue';
import CollectionView from './views/CollectionView.vue';
import CollectionsView from './views/CollectionsView.vue';
import DraftsView from './views/DraftsView.vue';
import NotFoundView from './views/NotFoundView.vue';
import NotificationsView from './views/NotificationsView.vue';
import PostView from './views/PostView.vue';
import ProfileView from './views/ProfileView.vue';
import ScheduledView from './views/ScheduledView.vue';
import SearchView from './views/SearchView.vue';
import TagView from './views/TagView.vue';
import { loadHostDisplayNames, setAccount } from './lib/account';
import { configureApi, createApi, getApi } from './lib/api';
import { configureAdminApi, createAdminApi } from './lib/admin';
import { initRouter, route, routeTitle } from './lib/router';
import { appState, initTheme, setInstance, setSession, setUnread, toggleTheme } from './lib/state';
import type { Account } from './lib/types';

const bridge = useHostBridge() as AlohaHostBridge;
configureApi(createApi(bridge, (file, altText) => bridge.uploadMedia(file, altText)));
configureAdminApi(createAdminApi(bridge));
initTheme();

let stopRouter: (() => void) | null = null;
let unreadTimer = 0;
const loggingOut = ref(false);

const currentView = computed(() => {
	switch (route.value.name) {
		case 'explore':
			return ExploreView;
		case 'notifications':
			return NotificationsView;
		case 'about':
			return AboutView;
		case 'profile':
			return ProfileView;
		case 'post':
			return PostView;
		case 'tag':
			return TagView;
		case 'bookmarks':
			return BookmarksView;
		case 'search':
			return SearchView;
		case 'lists':
			return ListsView;
		case 'list':
			return ListView;
		case 'muted':
			return MutesView;
		case 'blocked':
			return BlocksView;
		case 'filters':
			return FiltersView;
		case 'scheduled':
			return ScheduledView;
		case 'messages':
			return MessagesView;
		case 'conversation':
			return ConversationView;
		case 'memories':
			return MemoriesView;
		case 'collections':
			return CollectionsView;
		case 'collection':
			return CollectionView;
		case 'drafts':
			return DraftsView;
		case 'archived':
			return ArchivedView;
		case 'admin':
			return AdminView;
		case 'home':
			return HomeView;
		default:
			return NotFoundView;
	}
});

const viewKey = computed(() => {
	const current = route.value;
	switch (current.name) {
		case 'profile':
			return `profile:${current.handle}`;
		case 'post':
			return `post:${current.id}`;
		case 'tag':
			return `tag:${current.tag}`;
		case 'list':
			return `list:${current.id}`;
		case 'conversation':
			return `conversation:${current.id}`;
		case 'collection':
			return `collection:${current.id}`;
		case 'admin':
			return `admin:${current.section}`;
		default:
			return current.name;
	}
});

async function refreshUnread(): Promise<void> {
	if (appState.session.user === null) {
		setUnread(0);
		return;
	}

	try {
		setUnread(await getApi().unreadCount());
	} catch {
		// The badge is a convenience; a failed poll must not disturb the page.
	}
}

async function bootstrap(): Promise<void> {
	await loadHostDisplayNames();

	try {
		const instance = await getApi().instance();
		setInstance(instance);
	} catch {
		setInstance(null);
	}

	try {
		const user = await bridge.currentUser();
		if (user === null) {
			setSession(null);
			return;
		}

		let profile: Account | null = null;
		try {
			profile = await getApi().account(user.handle);
			setAccount(profile);
		} catch {
			// The host data is enough to show the signed-in identity.
		}

		setSession(user, profile, adminSession);
		await refreshUnread();
	} catch {
		setSession(null);
	}
}

function onVisibilityChange(): void {
	if (document.visibilityState === 'visible') {
		void refreshUnread();
	}
}

async function logout(): Promise<void> {
	if (loggingOut.value) {
		return;
	}

	loggingOut.value = true;
	try {
		await getApi().logout();
	} finally {
		loggingOut.value = false;
	}
}

onMounted(async () => {
	stopRouter = initRouter();
	await bootstrap();
	unreadTimer = window.setInterval(() => {
		void refreshUnread();
	}, 60_000);
	document.addEventListener('visibilitychange', onVisibilityChange);
});

onBeforeUnmount(() => {
	stopRouter?.();
	window.clearInterval(unreadTimer);
	document.removeEventListener('visibilitychange', onVisibilityChange);
});

watch(route, () => {
	window.scrollTo({ top: 0, behavior: 'auto' });
	document.title = `${routeTitle(route.value)} · ${appState.instance?.name ?? 'Aloha Social'}`;
}, { deep: true, immediate: true });

watch(() => appState.instance?.name, (name) => {
	document.title = `${routeTitle(route.value)} · ${name ?? 'Aloha Social'}`;
});
</script>

<template>
	<a class="a-skip-link" href="#a-main">Zum Inhalt springen</a>

	<div v-if="!appState.session.ready" class="a-boot" role="status" aria-live="polite">
		<div class="a-boot__mark" aria-hidden="true">
			<img :src="'/ui/brand/aloha-mark.svg'" alt="" />
		</div>
		<p>Aloha wird geöffnet …</p>
		<span class="a-boot__spinner" />
	</div>

	<div v-else class="a-shell">
		<div class="a-shell__ambient" aria-hidden="true">
			<span class="a-shell__glow a-shell__glow--one" />
			<span class="a-shell__glow a-shell__glow--two" />
		</div>

		<header class="a-mobile-head">
			<a class="a-brand" href="#/">
				<span class="a-brand__mark" aria-hidden="true"><img :src="'/ui/brand/aloha-mark.svg'" alt="" /></span>
				<span class="a-brand__copy">
					<strong>{{ appState.instance?.name ?? 'Aloha Social' }}</strong>
					<small>offen &amp; föderiert</small>
				</span>
			</a>
			<div class="a-mobile-head__actions">
				<button
					v-if="appState.session.user !== null"
					type="button"
					class="a-icon-btn"
					aria-label="Abmelden"
					title="Abmelden"
					:disabled="loggingOut"
					@click="logout"
				>
					<AppIcon name="logout" :size="19" />
				</button>
				<button type="button" class="a-icon-btn" aria-label="Farbschema wechseln" @click="toggleTheme">
					<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="19" />
				</button>
				<a v-if="appState.session.user === null" class="a-btn a-btn--primary a-btn--small" href="/login">Anmelden</a>
			</div>
		</header>

		<SidebarNav />

		<main id="a-main" class="a-main" tabindex="-1">
			<div class="a-main__inner">
				<Transition name="a-page" mode="out-in">
					<component :is="currentView" :key="viewKey" />
				</Transition>
			</div>
		</main>

		<RightRail />
	</div>

	<MobileNav v-if="appState.session.ready" />
	<ComposerModal />
	<Lightbox />
	<ToastHost />
</template>
