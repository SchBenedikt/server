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
import { t } from '../lib/i18n';
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
	{ value: '300', labelKey: 'poll.duration.300' },
	{ value: '3600', labelKey: 'poll.duration.3600' },
	{ value: '86400', labelKey: 'poll.duration.86400' },
	{ value: '259200', labelKey: 'poll.duration.259200' },
	{ value: '604800', labelKey: 'poll.duration.604800' },
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
		error.value = caught instanceof Error ? caught.message : t('scheduled.loadFailed');
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
			showToast(t('scheduled.timeFuture'), 'error');
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
			showToast(t('scheduled.pollOptions'), 'error');
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
		showToast(t('scheduled.updated'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('scheduled.updateFailed'), 'error');
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
		showToast(t('scheduled.discarded'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('scheduled.discardFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

function stateLabel(state: ScheduledPost['state']): string {
	switch (state) {
		case 'published':
			return t('scheduled.state.published');
		case 'failed':
			return t('scheduled.state.failed');
		case 'cancelled':
			return t('scheduled.state.cancelled');
		default:
			return t('scheduled.state.scheduled');
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
		<PageHeader :title="t('nav.scheduled')" :subtitle="t('scheduled.subtitle')">
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('scheduled.reload')" :title="t('scheduled.reload')" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="openComposer">
					<AppIcon name="calendar" :size="15" /> {{ t('scheduled.schedule') }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="calendar" :size="26" /></span>
			<h2>{{ t('scheduled.loginTitle') }}</h2>
			<p>{{ t('scheduled.loginText') }}</p>
			<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<div v-else-if="loading && posts.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>{{ t('scheduled.unavailable') }}</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</div>

		<EmptyState
			v-else-if="posts.length === 0"
			icon="calendar"
			:title="t('scheduled.emptyTitle')"
			:text="t('scheduled.emptyText')"
		>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openComposer">{{ t('scheduled.schedulePost') }}</button>
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
							:aria-label="t('scheduled.edit')"
							:title="t('scheduled.edit')"
							@click="startEdit(post)"
						>
							<AppIcon name="clock" :size="16" />
						</button>
						<button
							v-if="post.state === 'scheduled'"
							type="button"
							class="a-icon-btn"
							:class="{ 'a-icon-btn--danger': confirmCancel === post.id }"
							:aria-label="t('scheduled.discard')"
							:title="confirmCancel === post.id ? t('scheduled.confirmDiscard') : t('scheduled.discard')"
							:disabled="busy === post.id"
							@click="cancel(post)"
						>
							<AppIcon name="trash" :size="16" />
						</button>
					</div>
				</div>

				<p v-if="post.content_warning" class="a-management-cw">{{ t('scheduled.contentWarning', { text: post.content_warning }) }}</p>
				<p class="a-management-content">{{ post.content }}</p>

				<p class="a-management-meta">
					<AppIcon name="globe" :size="13" />
					{{ visibilityLabel(post.visibility) }}
					<span v-if="post.attachment_ids.length > 0">· {{ post.attachment_ids.length }} {{ post.attachment_ids.length === 1 ? t('scheduled.attachment') : t('scheduled.attachments') }}</span>
					<span v-if="post.in_reply_to_id">· {{ t('scheduled.reply') }}</span>
					<span v-if="post.poll">· {{ t('scheduled.poll') }}</span>
				</p>

				<p v-if="post.state === 'failed' && post.failure_reason" class="a-form-error" role="alert">
					{{ post.failure_reason }}
				</p>

				<form v-if="editingId === post.id" class="a-management-inline-form" @submit.prevent="saveEdit(post)">
					<label class="a-profile-edit__label" for="a-scheduled-time">{{ t('scheduled.publishAt') }}</label>
					<input id="a-scheduled-time" v-model="editingAt" type="datetime-local">
					<label class="a-profile-edit__label" for="a-scheduled-content">{{ t('scheduled.content') }}</label>
					<textarea id="a-scheduled-content" v-model="editingContent" rows="3" />
					<label class="a-profile-edit__label" for="a-scheduled-warning">{{ t('scheduled.contentWarningLabel') }} <span class="a-muted">{{ t('common.optional') }}</span></label>
					<input id="a-scheduled-warning" v-model="editingWarning" type="text" maxlength="200">
					<div class="a-scheduled-edit__poll">
						<label class="a-composer__check">
							<input v-model="editingPollEnabled" type="checkbox" />
							<span>{{ post.poll != null ? t('scheduled.keepPoll') : t('scheduled.addPoll') }}</span>
						</label>
						<template v-if="editingPollEnabled">
							<div v-for="(option, index) in editingPollOptions" :key="index" class="a-scheduled-edit__poll-option">
								<input v-model="editingPollOptions[index]" type="text" :placeholder="t('scheduled.optionPlaceholder', { n: index + 1 })" maxlength="100">
								<button
									type="button"
									class="a-icon-btn"
									:aria-label="t('scheduled.removeOption')"
									:disabled="editingPollOptions.length <= 2"
									@click="removePollOption(index)"
								>
									<AppIcon name="trash" :size="14" />
								</button>
							</div>
							<button v-if="editingPollOptions.length < POLL_MAX_OPTIONS" type="button" class="a-btn a-btn--ghost a-btn--small" @click="addPollOption">
								<AppIcon name="plus" :size="14" /> {{ t('scheduled.addOption') }}
							</button>
							<div class="a-composer__poll-settings">
								<label v-if="post.poll?.expires_at == null" class="a-composer__field">
									<span>{{ t('scheduled.duration') }}</span>
									<select v-model="editingPollExpiresIn">
										<option v-for="option in POLL_EXPIRY_OPTIONS" :key="option.value" :value="option.value">{{ t(option.labelKey) }}</option>
									</select>
								</label>
								<label class="a-composer__check">
									<input v-model="editingPollMultiple" type="checkbox" />
									<span>{{ t('poll.multiple') }}</span>
								</label>
								<label class="a-composer__check">
									<input v-model="editingPollHideTotals" type="checkbox" />
									<span>{{ t('poll.hideTotals') }}</span>
								</label>
							</div>
						</template>
					</div>
					<div class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="savingId === post.id" @click="cancelEdit">
							{{ t('common.cancel') }}
						</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="savingId === post.id">
							{{ savingId === post.id ? t('common.saving') : t('common.save') }}
						</button>
					</div>
				</form>
			</li>
		</ul>
	</div>
</template>
