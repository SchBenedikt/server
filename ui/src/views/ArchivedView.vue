<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import { getApi, loadErrorMessage } from '../lib/api';
import { relativeTime } from '../lib/format';
import { t } from '../lib/i18n';
import { appState, showToast } from '../lib/state';
import type { ArchivedPost, TimelineItem } from '../lib/types';

const rows = ref<ArchivedPost[]>([]);
const posts = ref<Record<string, TimelineItem>>({});
const loading = ref(false);
const error = ref('');
const restoring = ref('');

const signedIn = computed(() => appState.session.user !== null);
const ordered = computed(() => rows.value
	.map((row) => ({ row, post: posts.value[row.post_id] }))
	.filter((entry): entry is { row: ArchivedPost; post: TimelineItem } => entry.post !== undefined));

async function load(): Promise<void> {
	if (!signedIn.value) {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		rows.value = await getApi().archivedPosts();
		const ids = rows.value.map((row) => row.post_id);
		posts.value = ids.length === 0 ? {} : await getApi().posts(ids);
	} catch (caught) {
		error.value = loadErrorMessage(caught, t('archive.loadFailed'));
	} finally {
		loading.value = false;
	}
}

async function restore(row: ArchivedPost): Promise<void> {
	if (restoring.value !== '') {
		return;
	}

	restoring.value = row.post_id;
	try {
		await getApi().unarchivePost(row.post_id);
		rows.value = rows.value.filter((entry) => entry.post_id !== row.post_id);
		showToast(t('archive.restored'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('archive.restoreFailed')), 'error');
	} finally {
		restoring.value = '';
	}
}

onMounted(load);
watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		rows.value = [];
		posts.value = {};
	}
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="t('nav.archive')"
			:subtitle="t('archive.subtitle')"
			back-href="#/"
			:back-label="t('common.backHome')"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('archive.reload')" :title="t('common.refresh')" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>{{ t('common.signInRequired') }}</strong>
				<p>{{ t('archive.signInText') }}</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<p v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</p>

		<div v-else-if="loading && rows.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<EmptyState
			v-else-if="ordered.length === 0"
			icon="archive"
			:title="t('archive.emptyTitle')"
			:text="t('archive.emptyText')"
		/>

		<ol v-else class="a-feed__list">
			<li v-for="entry in ordered" :key="entry.row.post_id" class="a-archived-post">
				<PostCard :post="entry.post" />
				<footer class="a-archived-post__footer">
					<small>{{ t('archive.archivedAt', { time: relativeTime(entry.row.archived_at) }) }}</small>
					<button
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="restoring === entry.row.post_id"
						@click="restore(entry.row)"
					>
						<AppIcon name="refresh" :size="14" />
						{{ restoring === entry.row.post_id ? t('archive.restoring') : t('archive.restore') }}
					</button>
				</footer>
			</li>
		</ol>
	</div>
</template>
