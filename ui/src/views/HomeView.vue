<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref } from 'vue';
import AnnouncementBanner from '../components/AnnouncementBanner.vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import StoryBar from '../components/StoryBar.vue';
import TimelineFeed from '../components/TimelineFeed.vue';
import { formatCount, handleLabel } from '../lib/format';
import { appState, openComposer, setHomeTab } from '../lib/state';
import type { FeedKind } from '../lib/timelines';

const feed = ref<{ refresh: () => void } | null>(null);
const signedIn = computed(() => appState.session.user !== null);
const currentTab = computed<FeedKind>(() => {
	if (!signedIn.value) {
		return 'public';
	}

	return appState.homeTab === 'tags' ? 'followed' : appState.homeTab;
});
const selfName = computed(() => (
	appState.session.user?.displayName
	|| appState.session.profile?.display_name
	|| (appState.session.user !== null ? handleLabel(appState.session.user.handle) : '')
));
const instance = computed(() => appState.instance);

const tabs = computed(() => {
	if (!signedIn.value) {
		return [];
	}

	return [
		{ id: 'home' as const, label: 'Für dich' },
		{ id: 'public' as const, label: 'Öffentlich' },
		{ id: 'tags' as const, label: 'Meine Tags' },
	];
});

const subtitle = computed(() => {
	if (!signedIn.value) {
		return 'Was diese Instanz und das Fediverse gerade bewegt.';
	}

	switch (currentTab.value) {
		case 'home':
			return 'Beiträge der Menschen, denen du folgst.';
		case 'followed':
			return 'Alles unter den Hashtags, die du verfolgst.';
		default:
			return 'Die neuesten öffentlichen Beiträge dieser Instanz.';
	}
});

const emptyTitle = computed(() => {
	if (currentTab.value === 'followed') {
		return 'Deine Tag-Timeline ist noch leer';
	}
	if (currentTab.value === 'home') {
		return 'Deine Timeline ist noch ruhig';
	}

	return 'Noch keine öffentlichen Beiträge';
});

const emptyText = computed(() => {
	if (currentTab.value === 'followed') {
		return 'Folge Hashtags, die dich interessieren, und ihre Beiträge landen hier.';
	}
	if (currentTab.value === 'home') {
		return 'Folge Menschen, die spannende Dinge teilen – oder schreib selbst den ersten Beitrag.';
	}

	return 'Diese Instanz ist neu. Sei die erste Stimme, die hier zu hören ist.';
});

function switchTab(tab: 'home' | 'public' | 'tags'): void {
	setHomeTab(tab);
}

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	openComposer();
}

function refresh(): void {
	feed.value?.refresh();
}
</script>

<template>
	<div class="a-page">
		<PageHeader title="Startseite" :subtitle="subtitle">
			<template #actions>
				<button type="button" class="a-icon-btn" aria-label="Feed aktualisieren" title="Aktualisieren" @click="refresh">
					<AppIcon name="refresh" :size="19" />
				</button>
			</template>
		</PageHeader>

		<AnnouncementBanner />

		<StoryBar v-if="signedIn" />

		<section v-if="!signedIn" class="a-welcome">
			<div class="a-welcome__glow" aria-hidden="true" />
			<p class="a-welcome__eyebrow">
				<AppIcon name="globe" :size="15" />
				Offen · föderiert · gemeinschaftlich
			</p>
			<h2>Das soziale Netz, das dir gehört.</h2>
			<p>
				{{ instance?.description || 'Eine unabhängige Instanz für gute Gespräche, Bilder und Ideen – verbunden mit dem Fediverse.' }}
			</p>
			<div class="a-welcome__actions">
				<a class="a-btn a-btn--primary" href="/login">Anmelden</a>
				<a class="a-btn a-btn--ghost" href="#/explore">Erst einmal umsehen</a>
			</div>
			<dl v-if="instance" class="a-welcome__stats">
				<div><dt>Menschen</dt><dd>{{ formatCount(instance.stats.user_count) }}</dd></div>
				<div><dt>Beiträge</dt><dd>{{ formatCount(instance.stats.status_count) }}</dd></div>
				<div><dt>Verbunden mit</dt><dd>{{ formatCount(instance.stats.domain_count) }} Servern</dd></div>
			</dl>
		</section>

		<button v-if="signedIn" type="button" class="a-inline-compose" @click="compose">
			<Avatar
				:id="appState.session.user?.handle ?? 'aloha'"
				:name="selfName"
				:size="42"
			/>
			<span class="a-inline-compose__placeholder">Was beschäftigt dich gerade, {{ selfName.split(' ')[0] }}?</span>
			<span class="a-inline-compose__tools" aria-hidden="true">
				<AppIcon name="image" :size="19" />
				<AppIcon name="info" :size="19" />
				<span class="a-btn a-btn--primary a-btn--small">Posten</span>
			</span>
		</button>

		<div v-if="tabs.length > 0" class="a-tabs" role="tablist" aria-label="Feed auswählen">
			<button
				v-for="tab in tabs"
				:key="tab.id"
				type="button"
				class="a-tabs__item"
				:class="{ 'a-tabs__item--active': currentTab === (tab.id === 'tags' ? 'followed' : tab.id) }"
				role="tab"
				:aria-selected="currentTab === (tab.id === 'tags' ? 'followed' : tab.id)"
				@click="switchTab(tab.id)"
			>
				{{ tab.label }}
			</button>
		</div>

		<TimelineFeed
			ref="feed"
			:kind="currentTab"
			:empty-title="emptyTitle"
			:empty-text="emptyText"
			:empty-icon="currentTab === 'followed' ? 'hash' : 'sparkles'"
		>
			<template #empty>
				<EmptyState :icon="currentTab === 'followed' ? 'hash' : 'sparkles'" :title="emptyTitle" :text="emptyText">
					<a v-if="currentTab === 'followed'" class="a-btn a-btn--primary a-btn--small" href="#/explore">Hashtags entdecken</a>
					<button v-else-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
						Ersten Beitrag schreiben
					</button>
					<a v-else class="a-btn a-btn--primary a-btn--small" href="/login">Teil der Instanz werden</a>
				</EmptyState>
			</template>
		</TimelineFeed>
	</div>
</template>
