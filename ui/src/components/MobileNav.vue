<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { route } from '../lib/router';
import { getApi } from '../lib/api';
import { resetAccountDirectory } from '../lib/account';
import { appState, openComposer, toggleTheme } from '../lib/state';
import { labelFor, t } from '../lib/i18n';

const signedIn = computed(() => appState.session.user !== null);
const handle = computed(() => appState.session.user?.handle ?? '');
const sheetOpen = ref(false);
const loggingOut = ref(false);

const moreActive = computed(() => (
	sheetOpen.value
	|| sheetGroups.value.some((group) => group.items.some((item) => isActive(item.name)))
));

const primaryItems = [
	{ name: 'home', label: 'Start', labelKey: 'nav.home', icon: 'home', href: '#/' },
	{ name: 'explore', label: 'Explore', labelKey: 'nav.explore', icon: 'compass', href: '#/explore' },
	{ name: 'notifications', label: 'Notifications', labelKey: 'nav.notifications', icon: 'bell', href: '#/notifications' },
	{ name: 'messages', label: 'Messages', labelKey: 'nav.messages', icon: 'message', href: '#/messages' },
];

const sheetGroups = computed(() => {
	const groups: Array<{
		title: string;
		titleKey?: string;
		items: Array<{ name: string; label: string; labelKey?: string; icon: string; href: string }>;
	}> = [];

	if (signedIn.value) {
		groups.push({
			title: 'Your account', titleKey: 'nav.group.account',
			items: [
				{ name: 'profile', label: 'Profile', labelKey: 'nav.profile', icon: 'user', href: `#/u/${encodeURIComponent(handle.value)}` },
				{ name: 'settings', label: 'Settings', labelKey: 'nav.settings', icon: 'settings', href: '#/settings' },
				{ name: 'follow-requests', label: 'Follow requests', labelKey: 'nav.followRequests', icon: 'lock', href: '#/follow-requests' },
			],
		});
	}

	groups.push({
		title: 'Explore', titleKey: 'nav.group.discover',
		items: [
			{ name: 'search', label: 'Search', labelKey: 'nav.search', icon: 'search', href: '#/search' },
			{ name: 'kokua', label: 'Kokua', labelKey: 'nav.kokua', icon: 'handHeart', href: '#/kokua' },
			{ name: 'memories', label: 'Memories', labelKey: 'nav.memories', icon: 'clock', href: '#/memories' },
		],
	});

	if (signedIn.value) {
		groups.push(
			{
				title: 'Library', titleKey: 'nav.group.library',
				items: [
					{ name: 'bookmarks', label: 'Bookmarks', labelKey: 'nav.bookmarks', icon: 'bookmark', href: '#/bookmarks' },
					{ name: 'collections', label: 'Collections', labelKey: 'nav.collections', icon: 'list', href: '#/collections' },
					{ name: 'starter-packs', label: 'Starter packs', labelKey: 'nav.starterPacks', icon: 'users', href: '#/starter-packs' },
					{ name: 'lists', label: 'Lists', labelKey: 'nav.lists', icon: 'list', href: '#/lists' },
					{ name: 'drafts', label: 'Drafts', labelKey: 'nav.drafts', icon: 'pen', href: '#/drafts' },
					{ name: 'archived', label: 'Archive', labelKey: 'nav.archive', icon: 'archive', href: '#/archived' },
					{ name: 'scheduled', label: 'Scheduled', labelKey: 'nav.scheduled', icon: 'calendar', href: '#/scheduled' },
				],
			},
			{
				title: 'Moderation', titleKey: 'nav.group.moderation',
				items: [
					{ name: 'filters', label: 'Filters', labelKey: 'nav.filters', icon: 'filter', href: '#/filters' },
					{ name: 'muted', label: 'Muted', labelKey: 'nav.muted', icon: 'bellOff', href: '#/muted' },
					{ name: 'blocked', label: 'Blocked', labelKey: 'nav.blocked', icon: 'ban', href: '#/blocked' },
				],
			},
		);
	}

	const instanceItems = [
		{ name: 'about', label: 'About this instance', labelKey: 'nav.about', icon: 'info', href: '#/about' },
	];
	if ((appState.session.admin || appState.session.moderator)) {
		instanceItems.unshift({ name: 'admin', label: 'Administration', labelKey: 'nav.administration', icon: 'shield', href: '#/admin' });
	}
			instanceItems.push({ name: 'dashboard', label: 'Server dashboard', labelKey: 'nav.dashboard', icon: 'server', href: '/' });
	groups.push({ title: 'Instance', titleKey: 'nav.group.instance', items: instanceItems });

	if (!signedIn.value) {
		groups.push({
			title: 'Sign in', titleKey: 'nav.signIn',
			items: [{ name: 'login', label: 'Sign in', labelKey: 'nav.signIn', icon: 'logout', href: '/login' }],
		});
	}

	return groups;
});

function isActive(name: string): boolean {
	if (name === 'profile') {
		return route.value.name === 'profile' && route.value.handle === handle.value;
	}
	if (name === 'search') {
		return route.value.name === 'search' || route.value.name === 'tag';
	}
	if (name === 'collections') {
		return route.value.name === 'collections' || route.value.name === 'collection';
	}
	if (name === 'starter-packs') {
		return route.value.name === 'starter-packs' || route.value.name === 'starter-pack';
	}
	if (name === 'lists') {
		return route.value.name === 'lists' || route.value.name === 'list';
	}

	return route.value.name === name;
}

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	sheetOpen.value = false;
	openComposer();
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

watch(route, () => {
	sheetOpen.value = false;
}, { deep: true });
</script>

<template>
	<nav class="a-mobile-nav" aria-label="Mobile Navigation">
		<a
			v-for="item in primaryItems"
			:key="item.name"
			:href="item.href"
			class="a-mobile-nav__item"
			:class="{ 'a-mobile-nav__item--active': isActive(item.name) }"
			:aria-label="labelFor(item)"
			:aria-current="isActive(item.name) ? 'page' : undefined"
		>
			<span class="a-mobile-nav__icon">
				<AppIcon :name="item.icon" :size="21" />
				<span v-if="item.name === 'notifications' && appState.unread > 0" class="a-nav__badge">
					{{ appState.unread > 9 ? '9+' : appState.unread }}
				</span>
			</span>
			<span>{{ labelFor(item) }}</span>
		</a>

		<button
			type="button"
			class="a-mobile-nav__item"
			:class="{ 'a-mobile-nav__item--active': moreActive }"
			:aria-expanded="sheetOpen"
			aria-controls="a-mobile-sheet"
			aria-label="More sections"
			@click="sheetOpen = !sheetOpen"
		>
			<span class="a-mobile-nav__icon"><AppIcon name="list" :size="21" /></span>
			<span>More</span>
		</button>

		<button v-if="signedIn" type="button" class="a-mobile-fab" aria-label="Write a new post" @click="compose">
			<AppIcon name="pen" :size="21" />
		</button>
	</nav>

	<Transition name="a-sheet">
		<div v-if="sheetOpen" class="a-mobile-sheet-backdrop" @click.self="sheetOpen = false">
			<div id="a-mobile-sheet" class="a-mobile-sheet" role="dialog" aria-modal="true" aria-label="More sections">
				<header class="a-mobile-sheet__head">
					<strong>All sections</strong>
					<button type="button" class="a-icon-btn" aria-label="Close" @click="sheetOpen = false">
						<AppIcon name="x" :size="20" />
					</button>
				</header>

				<div class="a-mobile-sheet__body">
					<section v-for="group in sheetGroups" :key="group.title" class="a-mobile-sheet__group">
						<p class="a-mobile-sheet__title">{{ group.titleKey === undefined ? group.title : t(group.titleKey) }}</p>
						<div class="a-mobile-sheet__grid">
							<a
								v-for="item in group.items"
								:key="item.name"
								:href="item.href"
								class="a-mobile-sheet__item"
								:class="{ 'a-mobile-sheet__item--active': isActive(item.name) }"
								@click="sheetOpen = false"
							>
								<AppIcon :name="item.icon" :size="19" />
								<span>{{ labelFor(item) }}</span>
							</a>
						</div>
					</section>
				</div>

				<footer class="a-mobile-sheet__foot">
					<button type="button" class="a-btn a-btn--ghost" @click="toggleTheme">
						<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="17" />
						{{ appState.theme === 'dark' ? 'Light mode' : 'Dark mode' }}
					</button>
					<button v-if="signedIn" type="button" class="a-btn a-btn--ghost" :disabled="loggingOut" @click="logout">
						<AppIcon name="logout" :size="17" />
						{{ loggingOut ? 'Signing out…' : 'Sign out' }}
					</button>
				</footer>
			</div>
		</div>
	</Transition>
</template>
