<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref } from 'vue';
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
const loggingOut = ref(false);

const items = computed(() => {
	const base = [
		{ name: 'home' as const, label: 'Startseite', icon: 'home', href: '#/' },
		{ name: 'explore' as const, label: 'Entdecken', icon: 'compass', href: '#/explore' },
		{ name: 'notifications' as const, label: 'Benachrichtigungen', icon: 'bell', href: '#/notifications' },
	];

	if (signedIn.value) {
		base.push({
			name: 'profile' as const,
			label: 'Profil',
			icon: 'user',
			href: `#/u/${encodeURIComponent(handle.value)}`,
		});
	}

	return base;
});

function isActive(name: string): boolean {
	if (name === 'profile') {
		return route.value.name === 'profile' && route.value.handle === handle.value;
	}

	return route.value.name === name;
}

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	menuOpen.value = false;
	openComposer();
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

		<nav class="a-nav">
			<a
				v-for="item in items"
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

		<a
			v-if="!signedIn"
			class="a-sidebar__guest"
			href="/login"
		>
			<AppIcon name="globe" :size="17" />
			<span>Du liest als Gast. Melde dich an, um mitzureden.</span>
		</a>

		<div class="a-sidebar__spacer" />

		<nav class="a-sidebar__secondary" aria-label="Weitere Seiten">
			<a href="#/about" class="a-nav__item a-nav__item--small" :class="{ 'a-nav__item--active': route.name === 'about' }">
				<AppIcon name="info" :size="18" />
				<span>Über diese Instanz</span>
			</a>
			<a href="/" class="a-nav__item a-nav__item--small">
				<AppIcon name="settings" :size="18" />
				<span>Server-Dashboard</span>
			</a>
		</nav>

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
