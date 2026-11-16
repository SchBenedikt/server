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
	emptyTitle: 'Noch nichts hier',
	emptyText: 'Sobald jemand schreibt, erscheint es hier.',
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
				<strong>Der Feed ist gerade nicht erreichbar.</strong>
				<p>{{ timeline.error.value }}</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="timeline.refresh">
				Erneut versuchen
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
			<EmptyState :icon="emptyIcon" :title="emptyTitle" :text="emptyText" />
		</slot>

		<p v-if="timeline.error.value !== '' && timeline.posts.value.length > 0" class="a-feed__inline-error">
			{{ timeline.error.value }}
		</p>

		<button
			v-if="timeline.hasMore.value && timeline.posts.value.length > 0"
			type="button"
			class="a-btn a-btn--ghost a-feed__more"
			:disabled="timeline.loadingMore.value"
			@click="timeline.loadMore"
		>
			<AppIcon v-if="timeline.loadingMore.value" name="refresh" :size="16" class="a-spin" />
			{{ timeline.loadingMore.value ? 'Wird geladen…' : 'Weitere Beiträge' }}
		</button>

		<p
			v-else-if="timeline.loaded.value && timeline.posts.value.length > 0 && !timeline.hasMore.value"
			class="a-feed__end"
		>
			Du bist auf dem neuesten Stand.
		</p>
	</section>
</template>
