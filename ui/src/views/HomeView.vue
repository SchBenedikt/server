<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AnnouncementBanner from '../components/AnnouncementBanner.vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import OnboardingCard from '../components/OnboardingCard.vue';
import PageHeader from '../components/PageHeader.vue';
import StoryBar from '../components/StoryBar.vue';
import TimelineFeed from '../components/TimelineFeed.vue';
import { getApi } from '../lib/api';
import { formatCount, handleLabel } from '../lib/format';
import { t } from '../lib/i18n';
import { appState, openComposer, setHomeTab } from '../lib/state';
import type { FeedKind } from '../lib/timelines';

const feed = ref<{ refresh: () => void } | null>(null);
const signedIn = computed(() => appState.session.user !== null);
const onboardingVisible = ref(false);
const workerStale = ref(false);

onMounted(async () => {
	try {
		const status = await getApi().hostStatus();
		workerStale.value = !status.worker.online;
	} catch {
		// A failed probe must not turn a healthy feed into a warning.
	}

	if (!signedIn.value) {
		return;
	}
	try {
		const preferences = await getApi().preferences();
		onboardingVisible.value = !preferences.onboarding_dismissed;
	} catch {
		// The guide is an enhancement; a failed lookup simply keeps it hidden.
	}
});
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
		{ id: 'for-you' as const, labelKey: 'home.tab.forYou' },
		{ id: 'trending' as const, labelKey: 'home.tab.trending' },
		{ id: 'home' as const, labelKey: 'home.tab.following' },
		{ id: 'public' as const, labelKey: 'home.tab.local' },
		{ id: 'federated' as const, labelKey: 'home.tab.federated' },
		{ id: 'tags' as const, labelKey: 'home.tab.tags' },
	];
});

const subtitle = computed(() => {
	if (!signedIn.value) {
		return t('home.subtitleGuest');
	}

	switch (currentTab.value) {
		case 'for-you':
			return t('home.sub.forYou');
		case 'trending':
			return t('home.sub.trending');
		case 'home':
			return t('home.sub.following');
		case 'federated':
			return t('home.sub.federated');
		case 'followed':
			return t('home.sub.tags');
		default:
			return t('home.sub.local');
	}
});

const emptyTitle = computed(() => {
	if (currentTab.value === 'followed') {
		return t('home.empty.tagsTitle');
	}
	if (currentTab.value === 'for-you') {
		return t('home.empty.forYouTitle');
	}
	if (currentTab.value === 'trending') {
		return t('home.empty.trendingTitle');
	}
	if (currentTab.value === 'home') {
		return t('home.empty.followingTitle');
	}
	if (currentTab.value === 'federated') {
		return t('home.empty.federatedTitle');
	}

	return t('home.empty.localTitle');
});

const emptyText = computed(() => {
	if (currentTab.value === 'followed') {
		return t('home.empty.tagsText');
	}
	if (currentTab.value === 'for-you') {
		return t('home.empty.forYouText');
	}
	if (currentTab.value === 'trending') {
		return t('home.empty.trendingText');
	}
	if (currentTab.value === 'home') {
		return t('home.empty.followingText');
	}
	if (currentTab.value === 'federated') {
		return t('home.empty.federatedText');
	}

	return t('home.empty.localText');
});

function switchTab(tab: 'home' | 'for-you' | 'trending' | 'public' | 'federated' | 'tags'): void {
	setHomeTab(tab);
}

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	openComposer();
}

async function recheck(): Promise<void> {
	try {
		const status = await getApi().hostStatus();
		workerStale.value = !status.worker.online;
	} catch {
		// Keep the last known state when the probe itself fails.
	}
	refresh();
}

function refresh(): void {
	feed.value?.refresh();
}
</script>

<template>
	<div class="a-page a-home">
		<PageHeader :title="t('nav.home')" :subtitle="subtitle">
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('home.refresh')" :title="t('common.refresh')" @click="refresh">
					<AppIcon name="refresh" :size="19" />
				</button>
			</template>
		</PageHeader>

		<AnnouncementBanner />

		<StoryBar v-if="signedIn" />

		<OnboardingCard v-if="onboardingVisible" @dismissed="onboardingVisible = false" />

		<section v-if="!signedIn" class="a-welcome">
			<div class="a-welcome__glow" aria-hidden="true" />
			<p class="a-welcome__eyebrow">
				<AppIcon name="globe" :size="15" />
				{{ t('home.welcomeEyebrow') }}
			</p>
			<h2>{{ t('home.welcomeTitle') }}</h2>
			<p>
				{{ instance?.description || t('home.welcomeText') }}
			</p>
			<div class="a-welcome__actions">
				<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
				<a class="a-btn a-btn--ghost" href="#/explore">{{ t('home.lookAround') }}</a>
			</div>
			<dl v-if="instance" class="a-welcome__stats">
				<div><dt>{{ t('rail.people') }}</dt><dd>{{ formatCount(instance.stats.user_count) }}</dd></div>
				<div><dt>{{ t('rail.posts') }}</dt><dd>{{ formatCount(instance.stats.status_count) }}</dd></div>
				<div><dt>{{ t('home.connected') }}</dt><dd>{{ t('home.servers', { count: formatCount(instance.stats.domain_count) }) }}</dd></div>
			</dl>
		</section>

		<button v-if="signedIn" type="button" class="a-inline-compose" @click="compose">
			<Avatar
				:id="appState.session.user?.handle ?? 'aloha'"
				:name="selfName"
				:size="42"
			/>
			<span class="a-inline-compose__placeholder">{{ t('home.composePlaceholder', { name: selfName.split(' ')[0] }) }}</span>
			<span class="a-inline-compose__tools" aria-hidden="true">
				<AppIcon name="image" :size="19" />
				<AppIcon name="info" :size="19" />
				<span class="a-btn a-btn--primary a-btn--small">{{ t('rail.post') }}</span>
			</span>
		</button>

		<div v-if="tabs.length > 0" class="a-tabs" role="tablist" :aria-label="t('home.chooseFeed')">
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
				{{ t(tab.labelKey) }}
			</button>
		</div>

		<TimelineFeed
			ref="feed"
			:kind="currentTab"
			:cards="true"
			:empty-title="emptyTitle"
			:empty-text="emptyText"
			:empty-icon="currentTab === 'followed' ? 'hash' : 'sparkles'"
		>
			<template #empty="{ hasMore }">
				<EmptyState
					v-if="workerStale && !hasMore"
					icon="refresh"
					:title="t('home.syncTitle')"
					:text="t('home.syncText')"
				>
					<button type="button" class="a-btn a-btn--primary a-btn--small" @click="recheck">
						{{ t('home.checkAgain') }}
					</button>
				</EmptyState>
				<EmptyState
					v-else-if="hasMore"
					icon="filter"
					:title="t('home.pageEmptyTitle')"
					:text="t('home.pageEmptyText')"
				/>
				<EmptyState v-else :icon="currentTab === 'followed' ? 'hash' : 'sparkles'" :title="emptyTitle" :text="emptyText">
					<a v-if="currentTab === 'followed'" class="a-btn a-btn--primary a-btn--small" href="#/explore">{{ t('home.discoverTags') }}</a>
					<template v-else-if="signedIn">
						<button type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
							{{ t('home.writeFirst') }}
						</button>
						<a
							v-if="currentTab === 'home' || currentTab === 'for-you'"
							class="a-btn a-btn--ghost a-btn--small"
							href="#/explore"
						>{{ t('home.discoverPeople') }}</a>
					</template>
					<a v-else class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('home.join') }}</a>
				</EmptyState>
			</template>
		</TimelineFeed>
	</div>
</template>
