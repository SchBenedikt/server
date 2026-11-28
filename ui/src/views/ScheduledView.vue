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
import { absoluteTime, relativeTime, visibilityLabel } from '../lib/format';
import { appState, openComposer, showToast } from '../lib/state';
import type { ScheduledPost, ScheduledPostUpdate } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const posts = ref<ScheduledPost[]>([]);
const loading = ref(false);
const error = ref('');
const busy = ref('');
const confirmCancel = ref('');

const editingId = ref('');
const editingAt = ref('');
const editingContent = ref('');
const editingWarning = ref('');
const editingPollEnabled = ref(false);
const editingPollOptions = ref<string[]>(['', '']);
const editingPollMultiple = ref(false);
const editingPollHideTotals = ref(false);
const editingPollExpiresIn = ref('86400');
const savingId = ref('');
const POLL_MAX_OPTIONS = 4;
const POLL_EXPIRY_OPTIONS = [
	{ value: '300', label: '5 minutes' },
	{ value: '3600', label: '1 hour' },
	{ value: '86400', label: '1 day' },
	{ value: '259200', label: '3 days' },
	{ value: '604800', label: '7 days' },
];

onMounted(() => {
	if (signedIn.value) {
		void load();
	}
});

watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		posts.value = [];
	}
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		posts.value = await getApi().scheduledPosts();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Scheduled posts could not be loaded.';
	} finally {
		loading.value = false;
	}
}

function toLocalInput(value: string): string {
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return '';
	}

	const pad = (part: number): string => String(part).padStart(2, '0');
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function startEdit(post: ScheduledPost): void {
	editingId.value = post.id;
	editingAt.value = toLocalInput(post.scheduled_at);
	editingContent.value = post.content;
	editingWarning.value = post.content_warning ?? '';
	const poll = post.poll ?? null;
	editingPollEnabled.value = poll !== null;
	editingPollOptions.value = poll !== null ? [...poll.options] : ['', ''];
	editingPollMultiple.value = poll?.multiple ?? false;
	editingPollHideTotals.value = poll?.hide_totals ?? false;
	editingPollExpiresIn.value = String(poll?.expires_in ?? 86400);
}

function cancelEdit(): void {
	editingId.value = '';
	editingAt.value = '';
}

function addPollOption(): void {
	if (editingPollOptions.value.length < POLL_MAX_OPTIONS) {
		editingPollOptions.value = [...editingPollOptions.value, ''];
	}
}

function removePollOption(index: number): void {
	if (editingPollOptions.value.length <= 2) {
		return;
	}

	editingPollOptions.value = editingPollOptions.value.filter((_, i) => i !== index);
}

async function saveEdit(post: ScheduledPost): Promise<void> {
	if (savingId.value !== '') {
		return;
	}

	const patch: ScheduledPostUpdate = {};

	if (editingAt.value !== '') {
		const date = new Date(editingAt.value);
		if (Number.isNaN(date.getTime()) || date.getTime() < Date.now() + 60_000) {
			showToast('The new time must be at least one minute in the future.', 'error');
			return;
		}

		patch.scheduled_at = date.toISOString();
	}

	if (editingContent.value.trim() !== post.content) {
		patch.content = editingContent.value;
	}

	const warning = editingWarning.value.trim();
	if (warning !== (post.content_warning ?? '')) {
		patch.content_warning = warning === '' ? null : warning;
	}

	if (editingPollEnabled.value) {
		const options = editingPollOptions.value.map((option) => option.trim()).filter((option) => option !== '');
		if (options.length < 2) {
			showToast('A poll needs at least two options.', 'error');
			return;
		}

		const existing = post.poll ?? null;
		patch.poll = {
			options,
			multiple: editingPollMultiple.value,
			hide_totals: editingPollHideTotals.value,
			expires_in: existing?.expires_at != null ? null : Number(editingPollExpiresIn.value),
			expires_at: existing?.expires_at ?? null,
		};
	} else if (post.poll != null) {
		patch.poll = null;
	}

	if (Object.keys(patch).length === 0) {
		cancelEdit();
		return;
	}

	savingId.value = post.id;
	try {
		const updated = await getApi().updateScheduledPost(post.id, patch);
		posts.value = posts.value.map((entry) => entry.id === updated.id ? updated : entry);
		editingId.value = '';
		editingAt.value = '';
		showToast('Scheduled post updated.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The scheduled post could not be updated.', 'error');
	} finally {
		savingId.value = '';
	}
}

async function cancel(post: ScheduledPost): Promise<void> {
	if (confirmCancel.value !== post.id) {
		confirmCancel.value = post.id;
		window.setTimeout(() => {
			if (confirmCancel.value === post.id) {
				confirmCancel.value = '';
			}
		}, 3500);
		return;
	}

	if (busy.value !== '') {
		return;
	}

	busy.value = post.id;
	try {
		await getApi().deleteScheduledPost(post.id);
		posts.value = posts.value.map((entry) => (
			entry.id === post.id ? { ...entry, state: 'cancelled' as const } : entry
		));
		confirmCancel.value = '';
		showToast('Scheduled post discarded.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The post could not be discarded.', 'error');
	} finally {
		busy.value = '';
	}
}

function stateLabel(state: ScheduledPost['state']): string {
	switch (state) {
		case 'published':
			return 'Published';
		case 'failed':
			return 'Failed';
		case 'cancelled':
			return 'Dismissed';
		default:
			return 'Scheduled';
	}
}

function stateClass(state: ScheduledPost['state']): string {
	switch (state) {
		case 'published':
			return 'a-badge--mint';
		case 'failed':
			return 'a-badge--rose';
		case 'cancelled':
			return 'a-badge--rose';
		default:
			return 'a-badge--amber';
	}
}
</script>

<template>
	<div class="a-page">
		<PageHeader title="Scheduled posts" subtitle="Posts that will be published at a later time.">
			<template #actions>
				<button type="button" class="a-icon-btn" aria-label="Reload" title="Reload" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="openComposer">
					<AppIcon name="calendar" :size="15" /> Schedule
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="calendar" :size="26" /></span>
			<h2>Schedule posts</h2>
			<p>Sign in to prepare posts and publish them later.</p>
			<a class="a-btn a-btn--primary" href="/login">Sign in</a>
		</div>

		<div v-else-if="loading && posts.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>Scheduled posts unavailable</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Try again</button>
		</div>

		<EmptyState
			v-else-if="posts.length === 0"
			icon="calendar"
			title="No scheduled posts"
			text="In the composer you can choose a time instead of publishing right away."
		>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openComposer">Schedule post</button>
		</EmptyState>

		<ul v-else class="a-management-list">
			<li v-for="post in posts" :key="post.id" class="a-management-card">
				<div class="a-management-card__head">
					<div class="a-management-card__title">
						<span class="a-badge" :class="stateClass(post.state)">{{ stateLabel(post.state) }}</span>
						<strong>{{ absoluteTime(post.scheduled_at) }}</strong>
						<span class="a-management-muted">· {{ relativeTime(post.scheduled_at) }}</span>
					</div>
					<div class="a-management-item__actions">
						<button
							v-if="post.state === 'scheduled'"
							type="button"
							class="a-icon-btn"
							aria-label="Edit post"
							title="Edit post"
							@click="startEdit(post)"
						>
							<AppIcon name="clock" :size="16" />
						</button>
						<button
							v-if="post.state === 'scheduled'"
							type="button"
							class="a-icon-btn"
							:class="{ 'a-icon-btn--danger': confirmCancel === post.id }"
							aria-label="Discard post"
							:title="confirmCancel === post.id ? 'Really discard?' : 'Discard'"
							:disabled="busy === post.id"
							@click="cancel(post)"
						>
							<AppIcon name="trash" :size="16" />
						</button>
					</div>
				</div>

				<p v-if="post.content_warning" class="a-management-cw">Content warning: {{ post.content_warning }}</p>
				<p class="a-management-content">{{ post.content }}</p>

				<p class="a-management-meta">
					<AppIcon name="globe" :size="13" />
					{{ visibilityLabel(post.visibility) }}
					<span v-if="post.attachment_ids.length > 0">· {{ post.attachment_ids.length }} attachment/Attachments</span>
					<span v-if="post.in_reply_to_id">· Reply</span>
					<span v-if="post.poll">· Poll</span>
				</p>

				<p v-if="post.state === 'failed' && post.failure_reason" class="a-form-error" role="alert">
					{{ post.failure_reason }}
				</p>

				<form v-if="editingId === post.id" class="a-management-inline-form" @submit.prevent="saveEdit(post)">
					<label class="a-profile-edit__label" for="a-scheduled-time">Publish at</label>
					<input id="a-scheduled-time" v-model="editingAt" type="datetime-local">
					<label class="a-profile-edit__label" for="a-scheduled-content">Content</label>
					<textarea id="a-scheduled-content" v-model="editingContent" rows="3" />
					<label class="a-profile-edit__label" for="a-scheduled-warning">Content warning <span class="a-muted">(optional)</span></label>
					<input id="a-scheduled-warning" v-model="editingWarning" type="text" maxlength="200">
					<div class="a-scheduled-edit__poll">
						<label class="a-composer__check">
							<input v-model="editingPollEnabled" type="checkbox" />
							<span>{{ post.poll != null ? 'Keep the poll' : 'Add a poll' }}</span>
						</label>
						<template v-if="editingPollEnabled">
							<div v-for="(option, index) in editingPollOptions" :key="index" class="a-scheduled-edit__poll-option">
								<input v-model="editingPollOptions[index]" type="text" :placeholder="`Option ${index + 1}`" maxlength="100">
								<button
									type="button"
									class="a-icon-btn"
									aria-label="Remove option"
									:disabled="editingPollOptions.length <= 2"
									@click="removePollOption(index)"
								>
									<AppIcon name="trash" :size="14" />
								</button>
							</div>
							<button v-if="editingPollOptions.length < POLL_MAX_OPTIONS" type="button" class="a-btn a-btn--ghost a-btn--small" @click="addPollOption">
								<AppIcon name="plus" :size="14" /> Add option
							</button>
							<div class="a-composer__poll-settings">
								<label v-if="post.poll?.expires_at == null" class="a-composer__field">
									<span>Duration</span>
									<select v-model="editingPollExpiresIn">
										<option v-for="option in POLL_EXPIRY_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
									</select>
								</label>
								<label class="a-composer__check">
									<input v-model="editingPollMultiple" type="checkbox" />
									<span>Multiple choice</span>
								</label>
								<label class="a-composer__check">
									<input v-model="editingPollHideTotals" type="checkbox" />
									<span>Results only after the poll closes</span>
								</label>
							</div>
						</template>
					</div>
					<div class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="savingId === post.id" @click="cancelEdit">
							Cancel
						</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="savingId === post.id">
							{{ savingId === post.id ? 'Saving…' : 'Save' }}
						</button>
					</div>
				</form>
			</li>
		</ul>
	</div>
</template>
