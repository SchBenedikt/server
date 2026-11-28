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

const signedIn = computed(() => appState.session.user !== null);
const handle = computed(() => appState.session.user?.handle ?? '');
const sheetOpen = ref(false);
const loggingOut = ref(false);

const moreActive = computed(() => (
	sheetOpen.value
	|| sheetGroups.value.some((group) => group.items.some((item) => isActive(item.name)))
));

const primaryItems = [
	{ name: 'home', label: 'Start', icon: 'home', href: '#/' },
	{ name: 'explore', label: 'Explore', icon: 'compass', href: '#/explore' },
	{ name: 'notifications', label: 'Notifications', icon: 'bell', href: '#/notifications' },
	{ name: 'messages', label: 'Messages', icon: 'message', href: '#/messages' },
];

const sheetGroups = computed(() => {
	const groups: Array<{
		title: string;
		items: Array<{ name: string; label: string; icon: string; href: string }>;
	}> = [];

	if (signedIn.value) {
		groups.push({
			title: 'Your account',
			items: [
				{ name: 'profile', label: 'Profile', icon: 'user', href: `#/u/${encodeURIComponent(handle.value)}` },
				{ name: 'settings', label: 'Settings', icon: 'settings', href: '#/settings' },
				{ name: 'follow-requests', label: 'Follow requests', icon: 'lock', href: '#/follow-requests' },
			],
		});
	}

	groups.push({
		title: 'Explore',
		items: [
			{ name: 'search', label: 'Search', icon: 'search', href: '#/search' },
			{ name: 'kokua', label: 'Kokua', icon: 'handHeart', href: '#/kokua' },
			{ name: 'memories', label: 'Memories', icon: 'clock', href: '#/memories' },
		],
	});

	if (signedIn.value) {
		groups.push(
			{
				title: 'Library',
				items: [
					{ name: 'bookmarks', label: 'Bookmarks', icon: 'bookmark', href: '#/bookmarks' },
					{ name: 'collections', label: 'Collections', icon: 'list', href: '#/collections' },
					{ name: 'starter-packs', label: 'Starter packs', icon: 'users', href: '#/starter-packs' },
					{ name: 'lists', label: 'Lists', icon: 'list', href: '#/lists' },
					{ name: 'drafts', label: 'Drafts', icon: 'pen', href: '#/drafts' },
					{ name: 'archived', label: 'Archive', icon: 'archive', href: '#/archived' },
					{ name: 'scheduled', label: 'Scheduled', icon: 'calendar', href: '#/scheduled' },
				],
			},
			{
				title: 'Moderation',
				items: [
					{ name: 'filters', label: 'Filters', icon: 'filter', href: '#/filters' },
					{ name: 'muted', label: 'Muted', icon: 'bellOff', href: '#/muted' },
					{ name: 'blocked', label: 'Blocked', icon: 'ban', href: '#/blocked' },
				],
			},
		);
	}

	const instanceItems = [
		{ name: 'about', label: 'About this instance', icon: 'info', href: '#/about' },
	];
	if ((appState.session.admin || appState.session.moderator)) {
		instanceItems.unshift({ name: 'admin', label: 'Administration', icon: 'shield', href: '#/admin' });
	}
			instanceItems.push({ name: 'dashboard', label: 'Server dashboard', icon: 'server', href: '/' });
	groups.push({ title: 'instance', items: instanceItems });

	if (!signedIn.value) {
		groups.push({
			title: 'Sign in',
			items: [{ name: 'login', label: 'Sign in', icon: 'logout', href: '/login' }],
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
			:aria-label="item.label"
			:aria-current="isActive(item.name) ? 'page' : undefined"
		>
			<span class="a-mobile-nav__icon">
				<AppIcon :name="item.icon" :size="21" />
				<span v-if="item.name === 'notifications' && appState.unread > 0" class="a-nav__badge">
					{{ appState.unread > 9 ? '9+' : appState.unread }}
				</span>
			</span>
			<span>{{ item.label }}</span>
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
						<p class="a-mobile-sheet__title">{{ group.title }}</p>
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
								<span>{{ item.label }}</span>
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
