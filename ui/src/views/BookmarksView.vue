<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { getApi, loadErrorMessage } from '../lib/api';
import { onPostDeleted } from '../lib/events';
import { t } from '../lib/i18n';
import { appState } from '../lib/state';
import type { TimelineItem } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const items = ref<TimelineItem[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const hasMore = ref(true);

onMounted(() => {
	onPostDeleted((id) => {
		items.value = items.value.filter((post) => post.id !== id);
	});
	void load(true);
});

async function load(reset: boolean): Promise<void> {
	if ((reset ? loading.value : loadingMore.value) || (!reset && !hasMore.value)) {
		return;
	}

	if (reset) {
		loading.value = true;
		error.value = '';
	} else {
		loadingMore.value = true;
	}

	try {
		const page = await getApi().bookmarks(20, reset ? null : cursor.value);
		items.value = reset ? page : [...items.value, ...page];
		cursor.value = page.length > 0 ? page[page.length - 1].id : null;
		hasMore.value = page.length === 20;
	} catch (caught) {
		error.value = loadErrorMessage(caught, t('bookmarks.loadFailed'));
	} finally {
		loading.value = false;
		loadingMore.value = false;
	}
}

function onBookmark(id: string, bookmarked: boolean): void {
	if (!bookmarked) {
		items.value = items.value.filter((post) => post.id !== id);
	}
}
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="t('nav.bookmarks')"
			:subtitle="t('bookmarks.subtitle')"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('common.reload')" :title="t('common.reload')" :disabled="loading" @click="load(true)">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
			</template>
		</PageHeader>

		<EmptyState
			v-if="!signedIn"
			icon="bookmark"
			:title="t('bookmarks.loginTitle')"
			:text="t('bookmarks.loginText')"
		>
			<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
		</EmptyState>

		<TimelineSkeleton v-else-if="loading && items.length === 0" :count="4" />

		<p v-else-if="error" class="a-error-panel" role="alert">
			<AppIcon name="info" :size="18" />
			<strong>{{ error }}</strong>
		</p>

		<EmptyState
			v-else-if="items.length === 0"
			icon="bookmark"
			:title="t('bookmarks.emptyTitle')"
			:text="t('bookmarks.emptyText')"
		/>

		<div v-else class="a-feed">
			<div class="a-feed__list">
				<PostCard
					v-for="post in items"
					:key="post.id"
					:post="post"
					@bookmark="onBookmark(post.id, $event)"
				/>
			</div>

			<button
				v-if="hasMore"
				type="button"
				class="a-btn a-feed__more"
				:disabled="loadingMore"
				@click="load(false)"
			>
				<AppIcon v-if="loadingMore" name="refresh" :size="15" class="a-spin" />
				{{ loadingMore ? t('common.loading') : t('bookmarks.more') }}
			</button>
		</div>
	</div>
</template>
