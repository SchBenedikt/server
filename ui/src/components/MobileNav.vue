<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';
import { route } from '../lib/router';
import { appState, openComposer } from '../lib/state';

const signedIn = computed(() => appState.session.user !== null);
const handle = computed(() => appState.session.user?.handle ?? '');

const items = computed(() => [
	{ name: 'home', label: 'Start', icon: 'home', href: '#/' },
	{ name: 'explore', label: 'Entdecken', icon: 'compass', href: '#/explore' },
	{ name: 'notifications', label: 'Mitteilungen', icon: 'bell', href: '#/notifications' },
	{
		name: 'profile',
		label: signedIn.value ? 'Profil' : 'Anmelden',
		icon: 'user',
		href: signedIn.value ? `#/u/${encodeURIComponent(handle.value)}` : '/login',
	},
]);

function isActive(name: string): boolean {
	if (name === 'profile' && signedIn.value) {
		return route.value.name === 'profile' && route.value.handle === handle.value;
	}

	return route.value.name === name;
}

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	openComposer();
}
</script>

<template>
	<nav class="a-mobile-nav" aria-label="Mobile Navigation">
		<a
			v-for="item in items"
			:key="item.name"
			:href="item.href"
			class="a-mobile-nav__item"
			:class="{ 'a-mobile-nav__item--active': isActive(item.name) }"
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
		<button v-if="signedIn" type="button" class="a-mobile-fab" aria-label="Neuen Beitrag schreiben" @click="compose">
			<AppIcon name="pen" :size="21" />
		</button>
	</nav>
</template>
