<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import { route } from '../lib/router';
import { handleLabel } from '../lib/format';
import { appState, openComposer, toggleTheme } from '../lib/state';
import { getApi } from '../lib/api';

const signedIn = computed(() => appState.session.user !== null);
const profile = computed(() => appState.session.profile);
const handle = computed(() => appState.session.user?.handle ?? '');
const displayName = computed(() => (
	appState.session.user?.displayName
	|| profile.value?.display_name
	|| (handle.value !== '' ? handleLabel(handle.value) : 'Profil')
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
const loggingOut = ref(false);

const primaryItems = computed(() => {
	const items = [
		{ name: 'home' as const, label: 'Startseite', icon: 'home', href: '#/' },
		{ name: 'explore' as const, label: 'Entdecken', icon: 'compass', href: '#/explore' },
		{ name: 'notifications' as const, label: 'Benachrichtigungen', icon: 'bell', href: '#/notifications' },
	];

	if (signedIn.value) {
		items.push(
			{ name: 'messages' as const, label: 'Nachrichten', icon: 'message', href: '#/messages' },
			{
				name: 'profile' as const,
				label: 'Profil',
				icon: 'user',
				href: `#/u/${encodeURIComponent(handle.value)}`,
			},
			{ name: 'settings' as const, label: 'Einstellungen', icon: 'settings', href: '#/settings' },
		);
	}

	return items;
});

const moreGroups = computed(() => {
	const groups: Array<{
		title: string;
		items: Array<{ name: string; label: string; icon: string; href: string }>;
	}> = [
		{
			title: 'Entdecken',
			items: [
				{ name: 'search', label: 'Suchen', icon: 'search', href: '#/search' },
			],
		},
	];

	if (signedIn.value) {
		groups.push(
			{
				title: 'Bibliothek',
				items: [
					{ name: 'memories', label: 'Erinnerungen', icon: 'clock', href: '#/memories' },
					{ name: 'bookmarks', label: 'Lesezeichen', icon: 'bookmark', href: '#/bookmarks' },
					{ name: 'collections', label: 'Sammlungen', icon: 'list', href: '#/collections' },
					{ name: 'lists', label: 'Listen', icon: 'list', href: '#/lists' },
					{ name: 'drafts', label: 'Entwürfe', icon: 'pen', href: '#/drafts' },
					{ name: 'archived', label: 'Archiv', icon: 'archive', href: '#/archived' },
					{ name: 'scheduled', label: 'Geplante Beiträge', icon: 'calendar', href: '#/scheduled' },
					{ name: 'follow-requests', label: 'Folgenanfragen', icon: 'lock', href: '#/follow-requests' },
				],
			},
			{
				title: 'Moderation',
				items: [
					{ name: 'filters', label: 'Filter', icon: 'filter', href: '#/filters' },
					{ name: 'muted', label: 'Stummgeschaltet', icon: 'bellOff', href: '#/muted' },
					{ name: 'blocked', label: 'Blockiert', icon: 'ban', href: '#/blocked' },
				],
			},
		);
	}

	const instanceItems = [
		{ name: 'about', label: 'Über diese Instanz', icon: 'info', href: '#/about' },
	];
	if (appState.session.admin) {
		instanceItems.unshift({ name: 'admin', label: 'Administration', icon: 'shield', href: '#/admin' });
	}
	instanceItems.push({ name: 'dashboard', label: 'Server-Dashboard', icon: 'server', href: '/' });

	groups.push({ title: 'Instanz', items: instanceItems });

	return groups;
});

const moreActive = computed(() => moreGroups.value.some((group) => (
	group.items.some((item) => isActive(item.name))
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

function closeMore(): void {
	moreOpen.value = false;
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
	<aside class="a-sidebar" aria-label="Hauptnavigation">
		<a class="a-brand" href="#/" :title="instanceHost">
			<span class="a-brand__mark" aria-hidden="true">
				<img :src="'/ui/brand/aloha-mark.svg'" alt="" />
			</span>
			<span class="a-brand__copy">
				<strong>{{ instanceName }}</strong>
				<small>Offen &amp; föderiert</small>
			</span>
		</a>

		<nav class="a-nav" aria-label="Hauptbereiche">
			<a
				v-for="item in primaryItems"
				:key="item.name"
				:href="item.href"
				class="a-nav__item"
				:class="{ 'a-nav__item--active': isActive(item.name) }"
				:aria-current="isActive(item.name) ? 'page' : undefined"
			>
				<span class="a-nav__icon">
					<AppIcon :name="item.icon" :size="21" />
					<span
						v-if="item.name === 'notifications' && appState.unread > 0"
						class="a-nav__badge"
					>{{ appState.unread > 9 ? '9+' : appState.unread }}</span>
				</span>
				<span>{{ item.label }}</span>
			</a>
		</nav>

		<button type="button" class="a-btn a-btn--primary a-sidebar__compose" @click="compose">
			<AppIcon name="pen" :size="18" />
			<span>{{ signedIn ? 'Beitrag schreiben' : 'Anmelden' }}</span>
		</button>

		<div class="a-sidebar__more">
			<button
				type="button"
				class="a-nav__item a-nav__more-trigger"
				:class="{ 'a-nav__item--active': moreActive || moreOpen }"
				:aria-expanded="moreOpen"
				aria-haspopup="menu"
				@click.stop="moreOpen = !moreOpen"
			>
				<span class="a-nav__icon"><AppIcon name="list" :size="21" /></span>
				<span>Mehr</span>
				<AppIcon name="chevronDown" :size="16" class="a-nav__more-chevron" :class="{ 'a-nav__more-chevron--open': moreOpen }" />
			</button>

			<div v-if="moreOpen" class="a-sidebar__more-menu" role="menu">
				<div v-for="group in moreGroups" :key="group.title" class="a-sidebar__more-group">
					<p class="a-sidebar__more-title">{{ group.title }}</p>
					<a
						v-for="item in group.items"
						:key="item.name"
						:href="item.href"
						role="menuitem"
						class="a-nav__item a-nav__item--small"
						:class="{ 'a-nav__item--active': isActive(item.name) }"
						@click="closeMore"
					>
						<AppIcon :name="item.icon" :size="18" />
						<span>{{ item.label }}</span>
					</a>
				</div>
			</div>
		</div>

		<a
			v-if="!signedIn"
			class="a-sidebar__guest"
			href="/login"
		>
			<AppIcon name="globe" :size="17" />
			<span>Du liest als Gast. Melde dich an, um mitzureden.</span>
		</a>

		<div class="a-sidebar__spacer" />

		<div v-if="signedIn" class="a-profile-menu">
			<button
				type="button"
				class="a-profile-menu__trigger"
				:aria-expanded="menuOpen"
				aria-haspopup="menu"
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
					<AppIcon name="user" :size="16" /> Mein Profil
				</a>
				<a v-if="appState.session.admin" href="#/admin" role="menuitem" @click="menuOpen = false">
					<AppIcon name="shield" :size="16" /> Administration
				</a>
				<button type="button" role="menuitem" @click="toggleTheme">
					<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="16" />
					{{ appState.theme === 'dark' ? 'Helle Ansicht' : 'Dunkle Ansicht' }}
				</button>
				<button type="button" role="menuitem" class="a-profile-menu__danger" :disabled="loggingOut" @click="logout">
					<AppIcon name="logout" :size="16" />
					{{ loggingOut ? 'Abmelden…' : 'Abmelden' }}
				</button>
			</div>
		</div>

		<button v-else type="button" class="a-theme-toggle" aria-label="Farbschema wechseln" @click="toggleTheme">
			<AppIcon :name="appState.theme === 'dark' ? 'sun' : 'moon'" :size="18" />
			<span>{{ appState.theme === 'dark' ? 'Helle Ansicht' : 'Dunkle Ansicht' }}</span>
		</button>
	</aside>
</template>
