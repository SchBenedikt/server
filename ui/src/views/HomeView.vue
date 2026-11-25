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
		{ id: 'for-you' as const, label: 'For you' },
		{ id: 'trending' as const, label: 'Trending' },
		{ id: 'home' as const, label: 'Following' },
		{ id: 'public' as const, label: 'Public' },
		{ id: 'tags' as const, label: 'My Tags' },
	];
});

const subtitle = computed(() => {
	if (!signedIn.value) {
		return "What's moving this instance and the fediverse right now.";
	}

	switch (currentTab.value) {
		case 'for-you':
			return 'Recommended from this instance and the fediverse — learned from who you follow, your tags, your reactions and what you actually read.';
		case 'trending':
			return 'Local posts are ranked by known engagement and freshness; federated posts are ranked by recency.';
		case 'home':
			return 'Posts from people you follow.';
		case 'followed':
			return 'Everything under the hashtags you follow.';
		default:
			return 'The latest public posts on this instance.';
	}
});

const emptyTitle = computed(() => {
	if (currentTab.value === 'followed') {
		return 'Your hashtag timeline is still empty';
	}
	if (currentTab.value === 'for-you') {
		return 'No recommendations yet';
	}
	if (currentTab.value === 'trending') {
		return 'Nothing is trending yet';
	}
	if (currentTab.value === 'home') {
		return 'Your timeline is still quiet';
	}

	return 'No public posts yet';
});

const emptyText = computed(() => {
	if (currentTab.value === 'followed') {
		return 'Follow hashtags you care about and their posts will show up here.';
	}
	if (currentTab.value === 'for-you') {
		return 'Follow people and hashtags, and this feed mixes their posts with what is moving on this instance.';
	}
	if (currentTab.value === 'trending') {
		return 'Local posts are ranked by engagement and freshness. Federated posts are included by recency because their global engagement is not available here.';
	}
	if (currentTab.value === 'home') {
		return 'Follow people who share interesting things — or write the first post yourself.';
	}

	return 'This instance is new. Be the first voice heard here.';
});

function switchTab(tab: 'home' | 'for-you' | 'trending' | 'public' | 'tags'): void {
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
		<PageHeader title="Home" :subtitle="subtitle">
			<template #actions>
				<button type="button" class="a-icon-btn" aria-label="Feed refresh" title="Refresh" @click="refresh">
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
				Open · federated · community-run
			</p>
			<h2>The social network that belongs to you.</h2>
			<p>
				{{ instance?.description || 'An independent instance for good conversations, images and ideas — connected to the fediverse.' }}
			</p>
			<div class="a-welcome__actions">
				<a class="a-btn a-btn--primary" href="/login">Sign in</a>
				<a class="a-btn a-btn--ghost" href="#/explore">Take a look around</a>
			</div>
			<dl v-if="instance" class="a-welcome__stats">
				<div><dt>People</dt><dd>{{ formatCount(instance.stats.user_count) }}</dd></div>
				<div><dt>Posts</dt><dd>{{ formatCount(instance.stats.status_count) }}</dd></div>
				<div><dt>Connected to</dt><dd>{{ formatCount(instance.stats.domain_count) }} servers</dd></div>
			</dl>
		</section>

		<button v-if="signedIn" type="button" class="a-inline-compose" @click="compose">
			<Avatar
				:id="appState.session.user?.handle ?? 'aloha'"
				:name="selfName"
				:size="42"
			/>
			<span class="a-inline-compose__placeholder">What's on your mind, {{ selfName.split(' ')[0] }}?</span>
			<span class="a-inline-compose__tools" aria-hidden="true">
				<AppIcon name="image" :size="19" />
				<AppIcon name="info" :size="19" />
				<span class="a-btn a-btn--primary a-btn--small">Post</span>
			</span>
		</button>

		<div v-if="tabs.length > 0" class="a-tabs" role="tablist" aria-label="Choose feed">
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
			<template #empty="{ hasMore }">
				<EmptyState
					v-if="hasMore"
					icon="filter"
					title="No posts on this page"
					text="Some entries may be hidden by your settings or no longer available. Continue below to keep looking."
				/>
				<EmptyState v-else :icon="currentTab === 'followed' ? 'hash' : 'sparkles'" :title="emptyTitle" :text="emptyText">
					<a v-if="currentTab === 'followed'" class="a-btn a-btn--primary a-btn--small" href="#/explore">Discover hashtags</a>
					<button v-else-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
						Write your first post
					</button>
					<a v-else class="a-btn a-btn--primary a-btn--small" href="/login">Join the instance</a>
				</EmptyState>
			</template>
		</TimelineFeed>
	</div>
</template>
