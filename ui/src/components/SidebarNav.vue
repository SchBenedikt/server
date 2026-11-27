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
import { primaryCatalogItems } from '../lib/sidebar';
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
		&& (item.adminOnly !== true || appState.session.admin);
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
	const groups: Array<{ title: string; items: SidebarItem[] }> = [
		{
			title: 'Discover',
			items: [
				{ id: 'search', label: 'Search', icon: 'search', href: '#/search', group: 'main' },
				{ id: 'kokua', label: 'Kokua board', icon: 'handHeart', href: '#/kokua', group: 'main' },
			],
		},
	];

	if (signedIn.value) {
		groups.push(
			{
				title: 'Library',
				items: [
					{ id: 'memories', label: 'Memories', icon: 'clock', href: '#/memories', group: 'library', requiresAuth: true },
					{ id: 'bookmarks', label: 'Bookmarks', icon: 'bookmark', href: '#/bookmarks', group: 'library', requiresAuth: true },
					{ id: 'collections', label: 'Collections', icon: 'list', href: '#/collections', group: 'library', requiresAuth: true },
					{ id: 'starter-packs', label: 'Starter packs', icon: 'users', href: '#/starter-packs', group: 'library', requiresAuth: true },
					{ id: 'lists', label: 'Lists', icon: 'list', href: '#/lists', group: 'library', requiresAuth: true },
					{ id: 'drafts', label: 'Drafts', icon: 'pen', href: '#/drafts', group: 'library', requiresAuth: true },
					{ id: 'archived', label: 'Archive', icon: 'archive', href: '#/archived', group: 'library', requiresAuth: true },
					{ id: 'scheduled', label: 'Scheduled posts', icon: 'calendar', href: '#/scheduled', group: 'library', requiresAuth: true },
					{ id: 'follow-requests', label: 'Follow requests', icon: 'lock', href: '#/follow-requests', group: 'library', requiresAuth: true },
				],
			},
			{
				title: 'Moderation',
				items: [
					{ id: 'filters', label: 'Filters', icon: 'filter', href: '#/filters', group: 'moderation', requiresAuth: true },
					{ id: 'muted', label: 'Muted', icon: 'bellOff', href: '#/muted', group: 'moderation', requiresAuth: true },
					{ id: 'blocked', label: 'Blocked', icon: 'ban', href: '#/blocked', group: 'moderation', requiresAuth: true },
				],
			},
		);
	}

	const instanceItems: SidebarItem[] = [
		{ id: 'about', label: 'About this instance', icon: 'info', href: '#/about', group: 'instance' },
	];
	if (appState.session.admin) {
		instanceItems.unshift({ id: 'admin', label: 'Administration', icon: 'shield', href: '#/admin', group: 'instance', adminOnly: true });
	}
	instanceItems.push({ id: 'dashboard', label: 'Server dashboard', icon: 'server', href: '/', group: 'instance' });
	groups.push({ title: 'Instance', items: instanceItems });

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
	<aside class="a-sidebar" aria-label="Main navigation">
		<a class="a-brand" href="#/" :title="instanceHost">
			<span class="a-brand__mark" aria-hidden="true">
				<img :src="'/ui/brand/aloha-mark.svg'" alt="" />
			</span>
			<span class="a-brand__copy">
				<strong>{{ instanceName }}</strong>
				<small>Open &amp; federated</small>
			</span>
		</a>

		<nav class="a-nav" aria-label="Main sections">
			<a
				v-for="item in primaryItems"
				:key="item.id"
				:href="item.href"
				class="a-nav__item"
				:class="{ 'a-nav__item--active': isActive(item.id) }"
				:aria-label="item.label"
				:aria-current="isActive(item.id) ? 'page' : undefined"
			>
				<span class="a-nav__icon">
					<AppIcon :name="item.icon" :size="21" />
					<span
						v-if="item.id === 'notifications' && appState.unread > 0"
						class="a-nav__badge"
					>{{ appState.unread > 9 ? '9+' : appState.unread }}</span>
				</span>
				<span>{{ item.label }}</span>
			</a>
		</nav>

		<button type="button" class="a-btn a-btn--primary a-sidebar__compose" :aria-label="signedIn ? 'New post' : 'Sign in'" @click="compose">
			<AppIcon name="pen" :size="18" />
			<span>{{ signedIn ? 'New post' : 'Sign in' }}</span>
		</button>

		<div class="a-sidebar__more">
			<button
				type="button"
				class="a-nav__item a-nav__more-trigger"
				:class="{ 'a-nav__item--active': moreActive || moreOpen }"
				:aria-expanded="moreOpen"
				aria-haspopup="menu"
				aria-label="More sections"
				@click.stop="moreOpen = !moreOpen"
			>
				<span class="a-nav__icon"><AppIcon name="list" :size="21" /></span>
				<span>More</span>
				<AppIcon name="chevronDown" :size="16" class="a-nav__more-chevron" :class="{ 'a-nav__more-chevron--open': moreOpen }" />
			</button>

			<div v-if="moreOpen" class="a-sidebar__more-menu" role="menu">
				<div v-for="group in moreGroups" :key="group.title" class="a-sidebar__more-group">
					<p class="a-sidebar__more-title">{{ group.title }}</p>
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
						<span>{{ item.label }}</span>
					</a>
				</div>

				<button type="button" class="a-sidebar__customize" @click="openCustomize">
					<AppIcon name="sliders" :size="17" />
					<span>Customize sidebar</span>
				</button>
			</div>
		</div>

		<a
			v-if="!signedIn"
			class="a-sidebar__guest"
			href="/login"
			aria-label="Sign in to take part"
		>
			<AppIcon name="globe" :size="17" />
			<span>You are reading as a guest. Sign in to take part.</span>
		</a>

		<div class="a-sidebar__spacer" />

		<div v-if="signedIn" class="a-profile-menu">
			<button
				type="button"
				class="a-profile-menu__trigger"
				:aria-expanded="menuOpen"
				aria-haspopup="menu"
				:aria-label="`Account menu for ${displayName}`"
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
					<AppIcon name="user" :size="16" /> My profile
				</a>
				<a v-if="appState.session.admin" href="#/admin" role="menuitem" @click="menuOpen = false">
					<AppIcon name="shield" :size="16" /> administration
				</a>
				<button type="button" role="menuitem" @click="toggleTheme">
					<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="16" />
					{{ appState.theme === 'dark' ? 'Light theme' : 'Dark theme' }}
				</button>
				<button type="button" role="menuitem" class="a-profile-menu__danger" :disabled="loggingOut" @click="logout">
					<AppIcon name="logout" :size="16" />
					{{ loggingOut ? 'Signing out…' : 'Sign out' }}
				</button>
			</div>
		</div>

		<button v-else type="button" class="a-theme-toggle" aria-label="Toggle color theme" @click="toggleTheme">
			<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="18" />
			<span>{{ appState.theme === 'dark' ? 'Light theme' : 'Dark theme' }}</span>
		</button>

		<SidebarSettingsDialog :open="customizeOpen" @close="customizeOpen = false" />
	</aside>
</template>
