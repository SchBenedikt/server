<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { relativeTime, truncate, visibilityLabel } from '../lib/format';
import { appState, openComposer, openDraft, showToast } from '../lib/state';
import type { Draft } from '../lib/types';

const drafts = ref<Draft[]>([]);
const loading = ref(false);
const error = ref('');
const deleting = ref('');

const signedIn = computed(() => appState.session.user !== null);

async function load(): Promise<void> {
	if (!signedIn.value) {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		drafts.value = await getApi().drafts();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Drafts could not be loaded.';
	} finally {
		loading.value = false;
	}
}

function contextLabel(draft: Draft): string {
	if (draft.in_reply_to_id !== null) {
		return 'Reply';
	}
	if (draft.quoted_post_id !== null) {
		return 'Quote';
	}

	return 'Your post';
}

function preview(draft: Draft): string {
	const text = truncate(draft.content, 140);
	return text !== '' ? text : 'Draft without text';
}

function resume(draft: Draft): void {
	openDraft(draft);
}

async function remove(draft: Draft): Promise<void> {
	if (deleting.value !== '' || !window.confirm('Really delete this draft?')) {
		return;
	}

	deleting.value = draft.id;
	try {
		await getApi().deleteDraft(draft.id);
		drafts.value = drafts.value.filter((entry) => entry.id !== draft.id);
		showToast('Draft deleted.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The draft could not be deleted.', 'error');
	} finally {
		deleting.value = '';
	}
}

function compose(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	openComposer();
}

onMounted(load);
watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		drafts.value = [];
	}
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Drafts"
			subtitle="Saved to your account so they reappear on every device."
			back-href="#/"
			back-label="Back to home"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" aria-label="Reload drafts" title="Refresh" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
					<AppIcon name="pen" :size="15" /> New post
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>Sign-in required</strong>
				<p>Drafts belong to your account and are visible only to you.</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">Sign in</a>
		</div>

		<p v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Try again</button>
		</p>

		<div v-else-if="loading && drafts.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<EmptyState
			v-else-if="drafts.length === 0"
			icon="pen"
			title="No drafts"
			text="Drafts you save in the composer wait here for you to continue."
		>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">Write post</button>
		</EmptyState>

		<ul v-else class="a-draft-list">
			<li v-for="draft in drafts" :key="draft.id" class="a-draft-card">
				<button type="button" class="a-draft-card__body" :disabled="deleting === draft.id" @click="resume(draft)">
					<span class="a-draft-card__meta">
						<span class="a-draft-card__badge">{{ contextLabel(draft) }}</span>
						<span class="a-draft-card__badge">
							<AppIcon :name="draft.visibility === 'followers' ? 'users' : draft.visibility === 'unlisted' ? 'eye' : 'globe'" :size="12" />
							{{ visibilityLabel(draft.visibility) }}
						</span>
						<time :datetime="draft.updated_at">{{ relativeTime(draft.updated_at) }}</time>
					</span>
					<strong>{{ preview(draft) }}</strong>
					<span class="a-draft-card__detail">
						<template v-if="draft.content_warning">Content warning · </template>
						<template v-if="draft.attachment_ids.length > 0">
							{{ draft.attachment_ids.length }} {{ draft.attachment_ids.length === 1 ? 'attachment' : 'Attachments' }}
						</template>
						<template v-if="draft.poll !== null">
							· poll with {{ draft.poll.options.length }} replies
						</template>
					</span>
				</button>
				<button
					type="button"
					class="a-icon-btn a-icon-btn--danger"
					aria-label="Delete draft"
					title="Delete"
					:disabled="deleting === draft.id"
					@click="remove(draft)"
				>
					<AppIcon name="trash" :size="16" />
				</button>
			</li>
		</ul>
	</div>
</template>
