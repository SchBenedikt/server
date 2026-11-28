<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import EmptyState from './EmptyState.vue';
import PostCard from './PostCard.vue';
import TimelineSkeleton from './TimelineSkeleton.vue';
import { t } from '../lib/i18n';
import { useFeedImpressions } from '../lib/feed-signals';
import { useTimeline, type FeedKind } from '../lib/timelines';

const props = withDefaults(defineProps<{
	kind: FeedKind;
	tag?: string;
	emptyIcon?: string;
	emptyTitle?: string;
	emptyText?: string;
	cards?: boolean;
}>(), {
	tag: '',
	cards: false,
	emptyIcon: 'sparkles',
	emptyTitle: 'Nothing here yet',
	emptyText: 'As soon as someone posts, it appears here.',
});

const timeline = useTimeline(() => ({ kind: props.kind, tag: props.tag }));

const section = ref<HTMLElement | null>(null);
const sentinel = ref<HTMLElement | null>(null);

useFeedImpressions({
	container: section,
	postIds: computed(() => timeline.posts.value.map((post) => post.id)),
	enabled: computed(() => props.kind === 'for-you'),
});

let sentinelObserver: IntersectionObserver | null = null;

function observeSentinel(element: HTMLElement | null): void {
	sentinelObserver?.disconnect();
	sentinelObserver = null;
	if (element === null || typeof IntersectionObserver === 'undefined') {
		return;
	}

	// Load the next page before the reader reaches the end, so scrolling never
	// stalls on a spinner at the bottom of the viewport.
	sentinelObserver = new IntersectionObserver((entries) => {
		if (entries.some((entry) => entry.isIntersecting)) {
			timeline.loadMore();
		}
	}, { rootMargin: '900px 0px' });
	sentinelObserver.observe(element);
}

watch(sentinel, (element) => observeSentinel(element));

onUnmounted(() => {
	sentinelObserver?.disconnect();
	sentinelObserver = null;
});

defineExpose({
	refresh: timeline.refresh,
});
</script>

<template>
	<section ref="section" class="a-feed" :class="{ 'a-feed--cards': cards }" aria-live="polite">
		<div v-if="kind === 'for-you' && timeline.interests.value.length > 0" class="a-feed__interests">
			<span class="a-feed__interests-label">{{ t('feed.interests') }}</span>
			<span v-for="interest in timeline.interests.value" :key="interest.key" class="a-feed__interest">
				#{{ interest.key }}
			</span>
		</div>

		<button
			v-if="timeline.pendingNew.value.length > 0"
			type="button"
			class="a-feed__new"
			@click="timeline.showNewPosts"
		>
			<AppIcon name="arrowUp" :size="14" />
			{{ t(timeline.pendingNew.value.length === 1 ? 'feed.newPostOne' : 'feed.newPosts', { count: timeline.pendingNew.value.length }) }}
		</button>

		<TimelineSkeleton v-if="timeline.loading.value && timeline.posts.value.length === 0" />

		<div v-else-if="timeline.error.value !== '' && timeline.posts.value.length === 0" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div>
				<strong>{{ t('feed.unavailable') }}</strong>
				<p>{{ timeline.error.value }}</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="timeline.refresh">
				{{ t('common.tryAgain') }}
			</button>
		</div>

		<TransitionGroup
			v-else-if="timeline.posts.value.length > 0"
			name="a-post-in"
			tag="ol"
			class="a-feed__list"
		>
			<li v-for="post in timeline.posts.value" :key="post.id" :data-feed-post-id="post.id">
				<PostCard :post="post" :for-you="kind === 'for-you'" />
			</li>
		</TransitionGroup>

		<slot v-else name="empty" :has-more="timeline.hasMore.value">
			<EmptyState
				v-if="timeline.hasMore.value"
				icon="filter"
				:title="t('feed.moreTitle')"
				:text="t('feed.moreText')"
			/>
			<EmptyState v-else :icon="emptyIcon" :title="emptyTitle" :text="emptyText" />
		</slot>

		<p v-if="timeline.error.value !== '' && timeline.posts.value.length > 0" class="a-feed__inline-error" role="alert">
			{{ timeline.error.value }}
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="timeline.loadMore">
				{{ t('feed.loadMoreRetry') }}
			</button>
		</p>

		<div
			v-if="timeline.hasMore.value && timeline.loaded.value && timeline.error.value === ''"
			ref="sentinel"
			class="a-feed__sentinel"
			aria-hidden="true"
		/>

		<button
			v-if="timeline.hasMore.value && timeline.loaded.value && timeline.error.value === ''"
			type="button"
			class="a-btn a-btn--ghost a-feed__more"
			:disabled="timeline.loadingMore.value"
			@click="timeline.loadMore"
		>
			<AppIcon v-if="timeline.loadingMore.value" name="refresh" :size="16" class="a-spin" />
			{{ timeline.loadingMore.value ? t('common.loading') : t(timeline.posts.value.length === 0 ? 'feed.continue' : 'feed.more') }}
		</button>

		<p
			v-else-if="timeline.loaded.value && timeline.posts.value.length > 0 && !timeline.hasMore.value"
			class="a-feed__end"
		>
			{{ t('feed.caughtUp') }}
		</p>
	</section>
</template>
