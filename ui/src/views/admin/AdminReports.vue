<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import {
	getAdminApi,
	reportCategoryLabel,
	reportStateLabel,
	type AdminReport,
	type ReportList,
} from '../../lib/admin';
import { absoluteTime, relativeTime } from '../../lib/format';
import { appState, showToast } from '../../lib/state';

type FilterState = 'open' | 'resolved' | 'dismissed' | 'all';

const data = ref<ReportList | null>(null);
const state = ref<FilterState>('open');
const loading = ref(false);
const error = ref('');
const busy = ref('');

const resolveOpen = ref(false);
const resolveTarget = ref<AdminReport | null>(null);
const resolveState = ref<'resolved' | 'dismissed'>('resolved');
const resolveNote = ref('');
const resolveSaving = ref(false);
const resolveError = ref('');

const confirmOpen = ref(false);
const confirmKind = ref<'post' | 'account'>('post');
const confirmTarget = ref<AdminReport | null>(null);

const myId = computed(() => appState.session.user?.id ?? '');

async function assign(report: AdminReport): Promise<void> {
	if (busy.value !== '') {
		return;
	}
	busy.value = 'assign';
	try {
		await getAdminApi().assignReport(report.id, report.assigned_to === myId.value ? null : myId.value);
		showToast('Report assignment updated.', 'success');
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The report could not be assigned.', 'error');
	} finally {
		busy.value = '';
	}
}

async function reopen(report: AdminReport): Promise<void> {
	if (busy.value !== '') {
		return;
	}
	busy.value = 'reopen';
	try {
		await getAdminApi().reopenReport(report.id);
		showToast('Report reopened.', 'success');
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The report could not be reopened.', 'error');
	} finally {
		busy.value = '';
	}
}

const filters: { id: FilterState; label: string }[] = [
	{ id: 'open', label: 'Open' },
	{ id: 'resolved', label: 'Resolved' },
	{ id: 'dismissed', label: 'Dismissed' },
	{ id: 'all', label: 'All' },
];

const reports = computed(() => data.value?.reports ?? []);
const counts = computed(() => data.value?.counts ?? { open: 0, resolved: 0, dismissed: 0 });

const resolveTitle = computed(() => (
	resolveState.value === 'resolved' ? 'Resolve report' : 'Dismiss report'
));

const confirmCopy = computed(() => {
	const report = confirmTarget.value;
	if (report === null) {
		return { title: '', message: '', label: '' };
	}

	if (confirmKind.value === 'post') {
		return {
			title: 'Delete reported post',
			message: 'The post is removed and — for a local post — a federated delete is queued. This cannot be undone.',
			label: 'Delete post',
		};
	}

	return {
		title: 'Deactivate reported account',
		message: `The local account @${report.target.id} will no longer be able to sign in. Posts are kept and the deactivation can be reversed.`,
		label: 'account deactivate',
	};
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		data.value = await getAdminApi().reports(state.value);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Reports could not be loaded.';
	} finally {
		loading.value = false;
	}
}

function selectState(next: FilterState): void {
	state.value = next;
	void load();
}

function categoryTone(category: string): string {
	switch (category) {
		case 'spam':
			return 'amber';
		case 'abuse':
		case 'harassment':
			return 'danger';
		case 'misinformation':
			return 'violet';
		default:
			return 'muted';
	}
}

function openResolve(report: AdminReport, next: 'resolved' | 'dismissed'): void {
	resolveTarget.value = report;
	resolveState.value = next;
	resolveNote.value = '';
	resolveError.value = '';
	resolveOpen.value = true;
}

function closeResolve(): void {
	if (!resolveSaving.value) {
		resolveOpen.value = false;
	}
}

async function saveResolve(): Promise<void> {
	const target = resolveTarget.value;
	if (target === null || resolveSaving.value) {
		return;
	}
	if (resolveNote.value.length > 500) {
		resolveError.value = 'The note may be at most 500 characters long.';
		return;
	}

	resolveSaving.value = true;
	resolveError.value = '';
	try {
		await getAdminApi().resolveReport(target.id, resolveState.value, resolveNote.value.trim());
		resolveOpen.value = false;
		showToast(
			resolveState.value === 'resolved' ? 'Report marked as resolved.' : 'Report dismissed.',
			'success',
		);
		await load();
	} catch (caught) {
		resolveError.value = caught instanceof Error ? caught.message : 'The report could not be updated.';
	} finally {
		resolveSaving.value = false;
	}
}

function askPost(report: AdminReport): void {
	confirmKind.value = 'post';
	confirmTarget.value = report;
	confirmOpen.value = true;
}

function askAccount(report: AdminReport): void {
	confirmKind.value = 'account';
	confirmTarget.value = report;
	confirmOpen.value = true;
}

async function confirmAction(): Promise<void> {
	const report = confirmTarget.value;
	if (report === null || busy.value !== '') {
		return;
	}

	busy.value = 'confirm';
	try {
		if (confirmKind.value === 'post') {
			await getAdminApi().deletePost(report.target.id);
			showToast('Post deleted.', 'success');
		} else {
			await getAdminApi().setUserDisabled(report.target.id, true);
			showToast('account deactivated.', 'success');
		}
		confirmOpen.value = false;
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The action could not be completed.', 'error');
	} finally {
		busy.value = '';
	}
}

onMounted(() => {
	void load();
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">Moderation</p>
				<h2>Reported Content</h2>
				<p class="a-admin-lead">
					Each report shows the target, reason and context. Resolve or dismiss it with
					an internal note; if needed, remove the post or deactivate the account.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				Refresh
			</button>
		</div>

		<div class="a-admin-filterbar" role="tablist" aria-label="Report status">
			<button
				v-for="filter in filters"
				:key="filter.id"
				type="button"
				role="tab"
				class="a-admin-filter"
				:class="{ 'a-admin-filter--active': state === filter.id }"
				:aria-selected="state === filter.id"
				@click="selectState(filter.id)"
			>
				{{ filter.label }}
				<span v-if="filter.id !== 'all'" class="a-admin-filter__count">{{ counts[filter.id] }}</span>
			</button>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && data === null" class="a-admin-skeleton-grid a-admin-skeleton-grid--rows">
			<span v-for="index in 4" :key="index" class="a-admin-skeleton" />
		</div>

		<div v-else-if="reports.length === 0" class="a-admin-empty-panel">
			<AppIcon name="flag" :size="24" />
			<div>
				<strong>{{ state === 'open' ? 'No open reports' : 'No reports in this view' }}</strong>
				<p>
					{{ state === 'open'
						? 'As soon as someone reports a post or profile, the case appears here.'
						: 'Change the filter to see other reports.' }}
				</p>
			</div>
		</div>

		<div v-else class="a-admin-report-list">
			<article v-for="report in reports" :key="report.id" class="a-admin-report">
				<header class="a-admin-report__head">
					<span class="a-admin-badge" :class="`a-admin-badge--${categoryTone(report.category)}`">
						{{ reportCategoryLabel(report.category) }}
					</span>
					<span class="a-admin-badge" :class="{
						'a-admin-badge--ok': report.state === 'open',
						'a-admin-badge--mint': report.state === 'resolved',
						'a-admin-badge--muted': report.state === 'dismissed',
					}">
						{{ reportStateLabel(report.state) }}
					</span>
					<span class="a-admin-report__meta">
						By @{{ report.reporter_handle }} · {{ relativeTime(report.created_at) }}
						<template v-if="report.assigned_to !== null">
							· Assigned to @{{ report.assignee_handle ?? report.assigned_to }}
						</template>
					</span>
				</header>

				<div class="a-admin-report__target">
					<span class="a-admin-report__type">
						<AppIcon :name="report.target_type === 'post' ? 'message' : 'user'" :size="15" />
						{{ report.target_type === 'post' ? 'Post' : 'account' }}
					</span>
					<div class="a-admin-report__target-body">
						<strong>{{ report.target.author_name ?? report.target.id }}</strong>
						<p v-if="report.target.content">{{ report.target.content }}</p>
						<p v-else-if="report.target_type === 'post'" class="a-admin-muted">
							The post is no longer in the local or cached store.
						</p>
						<small>
							<span v-if="report.target.scope === 'local'">local</span>
							<span v-else-if="report.target.scope === 'remote'">Remote</span>
							<span v-else>unknown origin</span>
							<template v-if="report.target.published_at"> · {{ absoluteTime(report.target.published_at) }}</template>
						</small>
					</div>
					<a
						v-if="report.target.href"
						class="a-admin-link"
						:href="report.target.href"
						target="_blank"
						rel="noopener noreferrer"
					>Open <AppIcon name="external" :size="13" /></a>
				</div>

				<p v-if="report.comment" class="a-admin-report__comment">
					<AppIcon name="message" :size="14" />
					{{ report.comment }}
				</p>

				<p v-if="report.resolution_note" class="a-admin-report__resolution">
					<strong>Internal note:</strong> {{ report.resolution_note }}
					<span v-if="report.resolved_at"> · {{ relativeTime(report.resolved_at) }}</span>
				</p>

				<footer class="a-admin-report__actions">
					<template v-if="report.state === 'open'">
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busy !== ''"
							@click="assign(report)"
						>
							<AppIcon name="user" :size="15" />
							{{ report.assigned_to === myId ? 'Unassign me' : report.assigned_to === null ? 'Assign to me' : 'Take over' }}
						</button>
						<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openResolve(report, 'resolved')">
							<AppIcon name="check" :size="15" /> Resolve
						</button>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="openResolve(report, 'dismissed')">
							<AppIcon name="x" :size="15" /> Dismiss
						</button>
					</template>
					<button
						v-else
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="busy !== ''"
						@click="reopen(report)"
					>
						<AppIcon name="refresh" :size="15" /> Reopen
					</button>
					<button
						v-if="report.target_type === 'post' && report.target.exists"
						type="button"
						class="a-btn a-btn--danger a-btn--small"
						@click="askPost(report)"
					>
						<AppIcon name="trash" :size="15" /> Delete post
					</button>
					<button
						v-if="report.target_type === 'account' && report.target.scope === 'local' && !report.target.disabled"
						type="button"
						class="a-btn a-btn--danger a-btn--small"
						@click="askAccount(report)"
					>
						<AppIcon name="ban" :size="15" /> account deactivate
					</button>
					<a
						v-if="report.target_type === 'account' && report.target.scope === 'local'"
						class="a-btn a-btn--ghost a-btn--small"
						:href="`#/u/${encodeURIComponent(report.target.id)}`"
					>
						<AppIcon name="user" :size="15" /> profiles
					</a>
					<span class="a-admin-report__id">Fall {{ report.id.slice(0, 8) }}</span>
				</footer>
			</article>
		</div>

		<Teleport to="body">
			<div v-if="resolveOpen" class="a-modal-overlay" role="presentation" @click.self="closeResolve">
				<form class="a-management-dialog" role="dialog" aria-modal="true" aria-labelledby="a-resolve-title" @submit.prevent="saveResolve">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">Case handling</p>
							<h2 id="a-resolve-title">{{ resolveTitle }}</h2>
						</div>
						<button type="button" class="a-icon-btn" aria-label="Close dialog" :disabled="resolveSaving" @click="closeResolve">
							<AppIcon name="x" :size="19" />
						</button>
					</header>

					<p class="a-admin-muted">
						{{ resolveState === 'resolved'
							? 'The report is considered handled. The note is visible only to the administration.'
							: 'The report is closed without further action. The note is visible only to the administration.' }}
					</p>

					<label class="a-profile-edit__label" for="a-resolve-note">Internal note <span class="a-admin-muted">(optional)</span></label>
					<textarea
						id="a-resolve-note"
						v-model="resolveNote"
						class="a-admin-textarea"
						rows="4"
						maxlength="500"
						placeholder="What was reviewed and decided?"
					/>
					<p class="a-profile-edit__hint">{{ resolveNote.length }} / 500 characters</p>
					<p v-if="resolveError !== ''" class="a-form-error" role="alert">{{ resolveError }}</p>

					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="resolveSaving" @click="closeResolve">Cancel</button>
						<button
							type="submit"
							class="a-btn a-btn--small"
							:class="resolveState === 'resolved' ? 'a-btn--primary' : 'a-btn--danger'"
							:disabled="resolveSaving"
						>
							{{ resolveSaving ? 'Saving…' : resolveTitle }}
						</button>
					</footer>
				</form>
			</div>
		</Teleport>

		<ConfirmDialog
			:open="confirmOpen"
			:title="confirmCopy.title"
			:message="confirmCopy.message"
			:confirm-label="confirmCopy.label"
			:busy="busy === 'confirm'"
			tone="danger"
			@confirm="confirmAction"
			@cancel="confirmOpen = false"
		/>
	</section>
</template>
