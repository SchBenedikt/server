<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from './AppIcon.vue';
import EmptyState from './EmptyState.vue';
import PostCard from './PostCard.vue';
import TimelineSkeleton from './TimelineSkeleton.vue';
import { useTimeline, type FeedKind } from '../lib/timelines';

const props = withDefaults(defineProps<{
	kind: FeedKind;
	tag?: string;
	emptyIcon?: string;
	emptyTitle?: string;
	emptyText?: string;
}>(), {
	tag: '',
	emptyIcon: 'sparkles',
	emptyTitle: 'Nothing here yet',
	emptyText: 'As soon as someone posts, it appears here.',
});

const timeline = useTimeline(() => ({ kind: props.kind, tag: props.tag }));

defineExpose({
	refresh: timeline.refresh,
});
</script>

<template>
	<section class="a-feed" aria-live="polite">
		<TimelineSkeleton v-if="timeline.loading.value && timeline.posts.value.length === 0" />

		<div v-else-if="timeline.error.value !== '' && timeline.posts.value.length === 0" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div>
				<strong>The feed is currently unavailable.</strong>
				<p>{{ timeline.error.value }}</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="timeline.refresh">
				Try again
			</button>
		</div>

		<TransitionGroup
			v-else-if="timeline.posts.value.length > 0"
			name="a-post-in"
			tag="ol"
			class="a-feed__list"
		>
			<li v-for="post in timeline.posts.value" :key="post.id">
				<PostCard :post="post" :for-you="kind === 'for-you'" />
			</li>
		</TransitionGroup>

		<slot v-else name="empty">
			<EmptyState
				v-if="timeline.hasMore.value"
				icon="filter"
				title="More posts are available"
				text="Some entries are hidden by your settings. Continue to older posts."
			/>
			<EmptyState v-else :icon="emptyIcon" :title="emptyTitle" :text="emptyText" />
		</slot>

		<p v-if="timeline.error.value !== '' && timeline.posts.value.length > 0" class="a-feed__inline-error">
			{{ timeline.error.value }}
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="timeline.loadMore">
				Try loading more again
			</button>
		</p>

		<button
			v-if="timeline.hasMore.value && timeline.loaded.value && timeline.error.value === ''"
			type="button"
			class="a-btn a-btn--ghost a-feed__more"
			:disabled="timeline.loadingMore.value"
			@click="timeline.loadMore"
		>
			<AppIcon v-if="timeline.loadingMore.value" name="refresh" :size="16" class="a-spin" />
			{{ timeline.loadingMore.value ? 'Loading…' : timeline.posts.value.length === 0 ? 'Continue' : 'More posts' }}
		</button>

		<p
			v-else-if="timeline.loaded.value && timeline.posts.value.length > 0 && !timeline.hasMore.value"
			class="a-feed__end"
		>
			You're all caught up.
		</p>
	</section>
</template>
