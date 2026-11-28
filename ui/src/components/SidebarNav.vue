<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import SidebarSettingsDialog from './SidebarSettingsDialog.vue';
import { route } from '../lib/router';
import { handleLabel } from '../lib/format';
import { appState, openComposer, toggleTheme } from '../lib/state';
import { getApi } from '../lib/api';
import { resetAccountDirectory } from '../lib/account';
import { primaryCatalogItems } from '../lib/sidebar';
import { labelFor, t } from '../lib/i18n';
import type { SidebarItem } from '../lib/sidebar';

const signedIn = computed(() => appState.session.user !== null);
const profile = computed(() => appState.session.profile);
const handle = computed(() => appState.session.user?.handle ?? '');
const displayName = computed(() => (
	appState.session.user?.displayName
	|| profile.value?.display_name
	|| (handle.value !== '' ? handleLabel(handle.value) : 'Profile')
));
const showHandle = computed(() => displayName.value !== handleLabel(handle.value));
const instanceHost = computed(() => {
	if (typeof window === 'undefined') {
		return '';
	}

	return window.location.host;
});
const instanceName = computed(() => appState.instance?.name ?? 'Aloha Social');

const menuOpen = ref(false);
const moreOpen = ref(false);
const customizeOpen = ref(false);
const loggingOut = ref(false);

function canAccess(item: SidebarItem): boolean {
	return (item.requiresAuth !== true || signedIn.value)
		&& (item.adminOnly !== true || (appState.session.admin || appState.session.moderator));
}

function itemHref(item: SidebarItem): string {
	return item.id === 'profile' ? `#/u/${encodeURIComponent(handle.value)}` : item.href;
}

const primaryItems = computed(() => primaryCatalogItems(canAccess).map((item) => ({
	...item,
	href: itemHref(item),
})));

const moreGroups = computed(() => {
	const pinned = new Set(primaryItems.value.map((item) => item.id));
	const groups: Array<{ title: string; titleKey?: string; items: SidebarItem[] }> = [
		{
			title: 'Discover', titleKey: 'nav.group.discover',
			items: [
				{ id: 'search', label: 'Search', labelKey: 'nav.search', icon: 'search', href: '#/search', group: 'main' },
				{ id: 'kokua', label: 'Kokua board', labelKey: 'nav.kokua', icon: 'handHeart', href: '#/kokua', group: 'main' },
			],
		},
	];

	if (signedIn.value) {
		groups.push(
			{
				title: 'Library', titleKey: 'nav.group.library',
				items: [
					{ id: 'memories', label: 'Memories', labelKey: 'nav.memories', icon: 'clock', href: '#/memories', group: 'library', requiresAuth: true },
					{ id: 'bookmarks', label: 'Bookmarks', labelKey: 'nav.bookmarks', icon: 'bookmark', href: '#/bookmarks', group: 'library', requiresAuth: true },
					{ id: 'collections', label: 'Collections', labelKey: 'nav.collections', icon: 'list', href: '#/collections', group: 'library', requiresAuth: true },
					{ id: 'starter-packs', label: 'Starter packs', labelKey: 'nav.starterPacks', icon: 'users', href: '#/starter-packs', group: 'library', requiresAuth: true },
					{ id: 'lists', label: 'Lists', labelKey: 'nav.lists', icon: 'list', href: '#/lists', group: 'library', requiresAuth: true },
					{ id: 'drafts', label: 'Drafts', labelKey: 'nav.drafts', icon: 'pen', href: '#/drafts', group: 'library', requiresAuth: true },
					{ id: 'archived', label: 'Archive', labelKey: 'nav.archive', icon: 'archive', href: '#/archived', group: 'library', requiresAuth: true },
					{ id: 'scheduled', label: 'Scheduled posts', labelKey: 'nav.scheduled', icon: 'calendar', href: '#/scheduled', group: 'library', requiresAuth: true },
					{ id: 'follow-requests', label: 'Follow requests', labelKey: 'nav.followRequests', icon: 'lock', href: '#/follow-requests', group: 'library', requiresAuth: true },
				],
			},
			{
				title: 'Moderation', titleKey: 'nav.group.moderation',
				items: [
					{ id: 'filters', label: 'Filters', labelKey: 'nav.filters', icon: 'filter', href: '#/filters', group: 'moderation', requiresAuth: true },
					{ id: 'muted', label: 'Muted', labelKey: 'nav.muted', icon: 'bellOff', href: '#/muted', group: 'moderation', requiresAuth: true },
					{ id: 'blocked', label: 'Blocked', labelKey: 'nav.blocked', icon: 'ban', href: '#/blocked', group: 'moderation', requiresAuth: true },
				],
			},
		);
	}

	const instanceItems: SidebarItem[] = [
		{ id: 'about', label: 'About this instance', labelKey: 'nav.about', icon: 'info', href: '#/about', group: 'instance' },
	];
	if ((appState.session.admin || appState.session.moderator)) {
		instanceItems.unshift({ id: 'admin', label: 'Administration', labelKey: 'nav.administration', icon: 'shield', href: '#/admin', group: 'instance', adminOnly: true });
	}
	instanceItems.push({ id: 'dashboard', label: 'Server dashboard', labelKey: 'nav.dashboard', icon: 'server', href: '/', group: 'instance' });
	groups.push({ title: 'Instance', titleKey: 'nav.group.instance', items: instanceItems });

	return groups
		.map((group) => ({
			...group,
			items: group.items.filter((item) => !pinned.has(item.id)),
		}))
		.filter((group) => group.items.length > 0);
});

const moreActive = computed(() => moreGroups.value.some((group) => (
	group.items.some((item) => isActive(item.id))
)));

function isActive(name: string): boolean {
	if (name === 'profile') {
		return route.value.name === 'profile' && route.value.handle === handle.value;
	}
	if (name === 'lists') {
		return route.value.name === 'lists' || route.value.name === 'list';
	}
	if (name === 'collections') {
		return route.value.name === 'collections' || route.value.name === 'collection';
	}
	if (name === 'starter-packs') {
		return route.value.name === 'starter-packs' || route.value.name === 'starter-pack';
	}
	if (name === 'search') {
		return route.value.name === 'search' || route.value.name === 'tag';
	}

	return route.value.name === name;
}

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	menuOpen.value = false;
	moreOpen.value = false;
	openComposer();
}

function openCustomize(): void {
	moreOpen.value = false;
	customizeOpen.value = true;
}

async function logout(): Promise<void> {
	if (loggingOut.value) {
		return;
	}

	loggingOut.value = true;
	resetAccountDirectory();
	try {
		await getApi().logout();
	} finally {
		loggingOut.value = false;
	}
}

function onDocumentClick(event: MouseEvent): void {
	if (!moreOpen.value) {
		return;
	}
	const target = event.target as HTMLElement | null;
	if (target?.closest('.a-sidebar__more') === null) {
		moreOpen.value = false;
	}
}

watch(route, () => {
	moreOpen.value = false;
	menuOpen.value = false;
}, { deep: true });

onMounted(() => {
	document.addEventListener('click', onDocumentClick);
});

onUnmounted(() => {
	document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
	<aside class="a-sidebar" :aria-label="t('common.mainNavigation')">
		<a class="a-brand" href="#/" :title="instanceHost">
			<span class="a-brand__mark" aria-hidden="true">
				<img :src="'/ui/brand/aloha-mark.svg'" alt="" />
			</span>
			<span class="a-brand__copy">
				<strong>{{ instanceName }}</strong>
				<small>{{ t('common.openAndFederated') }}</small>
			</span>
		</a>

		<nav class="a-nav" :aria-label="t('common.mainSections')">
			<a
				v-for="item in primaryItems"
				:key="item.id"
				:href="item.href"
				class="a-nav__item"
				:class="{ 'a-nav__item--active': isActive(item.id) }"
				:aria-label="labelFor(item)"
				:aria-current="isActive(item.id) ? 'page' : undefined"
			>
				<span class="a-nav__icon">
					<AppIcon :name="item.icon" :size="21" />
					<span
						v-if="item.id === 'notifications' && appState.unread > 0"
						class="a-nav__badge"
					>{{ appState.unread > 9 ? '9+' : appState.unread }}</span>
				</span>
				<span>{{ labelFor(item) }}</span>
			</a>
		</nav>

		<button type="button" class="a-btn a-btn--primary a-sidebar__compose" :aria-label="signedIn ? t('common.newPost') : t('nav.signIn')" @click="compose">
			<AppIcon name="pen" :size="18" />
			<span>{{ signedIn ? t('common.newPost') : t('nav.signIn') }}</span>
		</button>

		<div class="a-sidebar__more">
			<button
				type="button"
				class="a-nav__item a-nav__more-trigger"
				:class="{ 'a-nav__item--active': moreActive || moreOpen }"
				:aria-expanded="moreOpen"
				aria-haspopup="menu"
				:aria-label="t('common.moreSections')"
				@click.stop="moreOpen = !moreOpen"
			>
				<span class="a-nav__icon"><AppIcon name="list" :size="21" /></span>
				<span>{{ t('common.more') }}</span>
				<AppIcon name="chevronDown" :size="16" class="a-nav__more-chevron" :class="{ 'a-nav__more-chevron--open': moreOpen }" />
			</button>

			<div v-if="moreOpen" class="a-sidebar__more-menu" role="menu">
				<div v-for="group in moreGroups" :key="group.title" class="a-sidebar__more-group">
					<p class="a-sidebar__more-title">{{ group.titleKey === undefined ? group.title : t(group.titleKey) }}</p>
					<a
						v-for="item in group.items"
						:key="item.id"
						:href="item.href"
						role="menuitem"
						class="a-nav__item a-nav__item--small"
						:class="{ 'a-nav__item--active': isActive(item.id) }"
						@click="moreOpen = false"
					>
						<AppIcon :name="item.icon" :size="18" />
						<span>{{ labelFor(item) }}</span>
					</a>
				</div>

				<button type="button" class="a-sidebar__customize" @click="openCustomize">
					<AppIcon name="sliders" :size="17" />
					<span>{{ t('common.customizeSidebar') }}</span>
				</button>
			</div>
		</div>

		<a
			v-if="!signedIn"
			class="a-sidebar__guest"
			href="/login"
			:aria-label="t('common.signInToTakePart')"
		>
			<AppIcon name="globe" :size="17" />
			<span>{{ t('common.guestTakePart') }}</span>
		</a>

		<div class="a-sidebar__spacer" />

		<div v-if="signedIn" class="a-profile-menu">
			<button
				type="button"
				class="a-profile-menu__trigger"
				:aria-expanded="menuOpen"
				aria-haspopup="menu"
				:aria-label="t('common.accountMenuFor', { name: displayName })"
				@click="menuOpen = !menuOpen"
			>
				<Avatar :id="handle" :name="displayName" :size="40" />
				<span class="a-profile-menu__copy">
					<strong>{{ displayName }}</strong>
					<small v-if="showHandle">{{ handleLabel(handle) }}</small>
				</span>
				<AppIcon name="chevronDown" :size="17" />
			</button>

			<div v-if="menuOpen" class="a-profile-menu__popover" role="menu">
				<a :href="`#/u/${encodeURIComponent(handle)}`" role="menuitem" @click="menuOpen = false">
					<AppIcon name="user" :size="16" /> {{ t('common.myProfile') }}
				</a>
				<a v-if="(appState.session.admin || appState.session.moderator)" href="#/admin" role="menuitem" @click="menuOpen = false">
					<AppIcon name="shield" :size="16" /> {{ t('nav.administration') }}
				</a>
				<button type="button" role="menuitem" @click="toggleTheme">
					<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="16" />
					{{ appState.theme === 'dark' ? t('common.lightMode') : t('common.darkMode') }}
				</button>
				<button type="button" role="menuitem" class="a-profile-menu__danger" :disabled="loggingOut" @click="logout">
					<AppIcon name="logout" :size="16" />
					{{ loggingOut ? t('common.signingOut') : t('common.signOut') }}
				</button>
			</div>
		</div>

		<button v-else type="button" class="a-theme-toggle" :aria-label="t('common.toggleTheme')" @click="toggleTheme">
			<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="18" />
			<span>{{ appState.theme === 'dark' ? t('common.lightMode') : t('common.darkMode') }}</span>
		</button>

		<SidebarSettingsDialog :open="customizeOpen" @close="customizeOpen = false" />
	</aside>
</template>
