<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useHostBridge } from '@alohasocial/ui';
import type { AlohaHostBridge } from './bridge';
import { adminSession, moderatorSession } from './bridge';
import AppIcon from './components/AppIcon.vue';
import Avatar from './components/Avatar.vue';
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
import FollowRequestsView from './views/FollowRequestsView.vue';
import HomeView from './views/HomeView.vue';
import KokuaView from './views/KokuaView.vue';
import ListView from './views/ListView.vue';
import ListsView from './views/ListsView.vue';
import MutesView from './views/MutesView.vue';
import MemoriesView from './views/MemoriesView.vue';
import MessagesView from './views/MessagesView.vue';
import ArchivedView from './views/ArchivedView.vue';
import CollectionView from './views/CollectionView.vue';
import CollectionsView from './views/CollectionsView.vue';
import StarterPacksView from './views/StarterPacksView.vue';
import DraftsView from './views/DraftsView.vue';
import NotFoundView from './views/NotFoundView.vue';
import NotificationsView from './views/NotificationsView.vue';
import PageView from './views/PageView.vue';
import PostView from './views/PostView.vue';
import ProfileView from './views/ProfileView.vue';
import ScheduledView from './views/ScheduledView.vue';
import SearchView from './views/SearchView.vue';
import SettingsView from './views/SettingsView.vue';
import TagView from './views/TagView.vue';
import { setAccount } from './lib/account';
import { configureApi, createApi, getApi } from './lib/api';
import { configureAdminApi, createAdminApi } from './lib/admin';
import { offline } from './lib/connectivity';
import { applyDensity } from './lib/density';
import { useLiveUpdates } from './lib/live';
import { initRouter, navigate, route, routeTitle } from './lib/router';
import { t } from './lib/i18n';
import { appState, initTheme, openComposer, setInstance, setSearchSeed, setSession, setUnread, toggleTheme } from './lib/state';
import type { Account } from './lib/types';

const bridge = useHostBridge() as AlohaHostBridge;
configureApi(createApi(bridge, (file, altText) => bridge.uploadMedia(file, altText)));
configureAdminApi(createAdminApi(bridge));
initTheme();

let stopRouter: (() => void) | null = null;
let unreadTimer = 0;
const searchQuery = ref('');
const topbarHandle = computed(() => appState.session.user?.handle ?? '');
const topbarName = computed(() => (
	appState.session.user?.displayName
	|| appState.session.profile?.display_name
	|| topbarHandle.value
));

function submitSearch(): void {
	const query = searchQuery.value.trim();
	if (query === '') {
		navigate('/search');
		return;
	}

	setSearchSeed(query);
	navigate('/search');
}

function compose(): void {
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	openComposer();
}

// Live updates run for the whole shell so every route sees new posts and
// deletions, not only the home timeline.
useLiveUpdates(computed(() => appState.session.user !== null));

const currentView = computed(() => {
	switch (route.value.name) {
		case 'explore':
			return ExploreView;
		case 'notifications':
			return NotificationsView;
		case 'about':
			return AboutView;
		case 'page':
			return PageView;
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
		case 'kokua':
			return KokuaView;
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
		case 'starter-packs':
		case 'starter-pack':
			return StarterPacksView;
		case 'drafts':
			return DraftsView;
		case 'archived':
			return ArchivedView;
		case 'settings':
			return SettingsView;
		case 'follow-requests':
			return FollowRequestsView;
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
		case 'page':
			return `page:${current.slug}`;
		case 'list':
			return `list:${current.id}`;
		case 'conversation':
			return `conversation:${current.id}`;
		case 'collection':
			return `collection:${current.id}`;
		case 'starter-pack':
			return `starter-pack:${current.id}`;
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

		setSession(user, profile, adminSession, moderatorSession);
		try {
			applyDensity((await getApi().preferences()).ui_density);
		} catch {
			// Density is cosmetic; a failed lookup keeps the default.
		}
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
	<a class="a-skip-link" href="#a-main">{{ t('chrome.skipToContent') }}</a>

	<div v-if="!appState.session.ready" class="a-boot" role="status" aria-live="polite">
		<div class="a-boot__mark" aria-hidden="true">
			<img :src="'/ui/brand/aloha-mark.svg'" alt="" />
		</div>
		<p>{{ t('chrome.opening') }}</p>
		<span class="a-boot__spinner" />
	</div>

	<div v-else class="a-app">
		<div v-if="offline" class="a-offline" role="status">
			{{ t('chrome.offline') }}
		</div>
		<header class="a-topbar">
			<a class="a-topbar__brand" href="#/" :title="appState.instance?.name ?? 'Aloha Social'">
				<span class="a-topbar__mark" aria-hidden="true"><img :src="'/ui/brand/aloha-mark.svg'" alt="" /></span>
				<strong>{{ appState.instance?.name ?? 'Aloha Social' }}</strong>
			</a>

			<form class="a-topbar__search" role="search" @submit.prevent="submitSearch">
				<AppIcon name="search" :size="18" />
				<input
					v-model="searchQuery"
					type="search"
					:placeholder="t('chrome.search')"
					:aria-label="t('chrome.search')"
					autocomplete="off"
				/>
			</form>

			<nav class="a-topbar__nav" :aria-label="t('chrome.primaryNav')">
				<a href="#/" :class="{ 'is-active': route.name === 'home' }">{{ t('nav.home') }}</a>
				<a href="#/explore" :class="{ 'is-active': route.name === 'explore' }">{{ t('nav.explore') }}</a>
				<a href="#/notifications" :class="{ 'is-active': route.name === 'notifications' }">{{ t('nav.notifications') }}</a>
			</nav>

			<div class="a-topbar__actions">
				<button type="button" class="a-btn a-btn--primary a-btn--small a-topbar__create" @click="compose">
					<AppIcon name="plus" :size="16" /> {{ t('chrome.create') }}
				</button>
				<a href="#/notifications" class="a-topbar__icon" :aria-label="t('nav.notifications')" :title="t('nav.notifications')">
					<AppIcon name="bell" :size="20" />
					<span v-if="appState.unread > 0" class="a-topbar__badge">{{ appState.unread > 9 ? '9+' : appState.unread }}</span>
				</a>
				<a href="#/messages" class="a-topbar__icon" :aria-label="t('nav.messages')" :title="t('nav.messages')">
					<AppIcon name="message" :size="20" />
				</a>
				<a v-if="appState.session.user !== null" :href="`#/u/${encodeURIComponent(topbarHandle)}`" class="a-topbar__me" :title="topbarName">
					<Avatar :id="topbarHandle" :name="topbarName" :size="30" />
				</a>
				<a v-else class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('common.signIn') }}</a>
			</div>
		</header>

		<div class="a-shell">
			<div class="a-shell__ambient" aria-hidden="true">
			<span class="a-shell__glow a-shell__glow--one" />
			<span class="a-shell__glow a-shell__glow--two" />
		</div>

		<header class="a-mobile-head">
			<a class="a-brand" href="#/">
				<span class="a-brand__mark" aria-hidden="true"><img :src="'/ui/brand/aloha-mark.svg'" alt="" /></span>
				<span class="a-brand__copy">
					<strong>{{ appState.instance?.name ?? 'Aloha Social' }}</strong>
					<small>{{ t('common.openAndFederated') }}</small>
				</span>
			</a>
			<div class="a-mobile-head__actions">
				<a class="a-icon-btn" href="#/search" :aria-label="t('nav.search')" :title="t('nav.search')">
					<AppIcon name="search" :size="19" />
				</a>
				<button type="button" class="a-icon-btn" :aria-label="t('common.toggleTheme')" :title="t('common.toggleTheme')" @click="toggleTheme">
					<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="19" />
				</button>
				<a v-if="appState.session.user !== null" :href="`#/u/${encodeURIComponent(topbarHandle)}`" class="a-topbar__me" :title="topbarName" :aria-label="t('card.profileOf', { name: topbarName })">
					<Avatar :id="topbarHandle" :name="topbarName" :size="30" />
				</a>
				<a v-else class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('common.signIn') }}</a>
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
	</div>

	<MobileNav v-if="appState.session.ready" />
	<ComposerModal />
	<Lightbox />
	<ToastHost />
</template>
