<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import { getAdminApi, type AdminJob, type JobList } from '../../lib/admin';
import { absoluteTime, relativeTime } from '../../lib/format';
import { showToast } from '../../lib/state';
import { t } from '../../lib/i18n';

type FilterState = 'all' | 'pending' | 'running' | 'dead';

const data = ref<JobList | null>(null);
const state = ref<FilterState>('all');
const loading = ref(false);
const error = ref('');
const busy = ref('');
let timer = 0;

const confirmOpen = ref(false);
const confirmKind = ref<'purge' | 'retryAll'>('retryAll');

const filters: { id: FilterState; label: string }[] = [
	{ id: 'all', label: 'aq.filter.all' },
	{ id: 'pending', label: 'aq.filter.pending' },
	{ id: 'running', label: 'aq.filter.running' },
	{ id: 'dead', label: 'aq.filter.dead' },
];

const jobs = computed(() => data.value?.jobs ?? []);
const counts = computed(() => data.value?.counts ?? { pending: 0, running: 0, dead: 0, total: 0, oldest_pending: null });
const errorClasses = computed(() => Object.entries(data.value?.error_classes ?? {}));

const confirmCopy = computed(() => (
	confirmKind.value === 'purge'
		? {
			title: t('aq.confirmPurgeTitle'),
			message: t('aq.confirmPurgeMessage'),
			label: t('aq.confirmPurgeLabel'),
		}
		: {
			title: t('aq.confirmRetryTitle'),
			message: t('aq.confirmRetryMessage'),
			label: t('aq.confirmRetryLabel'),
		}
));

async function load(quiet = false): Promise<void> {
	if (!quiet) {
		loading.value = true;
	}
	error.value = '';
	try {
		data.value = await getAdminApi().jobs(state.value);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('aq.loadFailed');
	} finally {
		loading.value = false;
	}
}

function selectState(next: FilterState): void {
	state.value = next;
	void load();
}

function stateTone(value: string): string {
	switch (value) {
		case 'pending':
			return 'amber';
		case 'running':
			return 'violet';
		case 'dead':
			return 'danger';
		default:
			return 'muted';
	}
}

function stateLabel(value: string): string {
	const key = `aq.state.${value}`;
	const translated = t(key);
	return translated === key ? value : translated;
}

async function retry(job: AdminJob): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = `retry:${job.id}`;
	try {
		await getAdminApi().retryJob(job.id);
		showToast(t('aq.jobRetried'), 'success');
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('aq.retryFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

async function remove(job: AdminJob): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = `delete:${job.id}`;
	try {
		await getAdminApi().deleteJob(job.id);
		showToast(t('aq.jobDeleted'), 'success');
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('aq.deleteFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

async function runQueue(): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = 'run';
	try {
		const handled = await getAdminApi().runQueue();
		showToast(handled === 0 ? t('aq.noDue') : t('aq.processed', { count: handled }), 'success');
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('aq.runFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

function ask(kind: 'purge' | 'retryAll'): void {
	confirmKind.value = kind;
	confirmOpen.value = true;
}

async function confirmAction(): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = 'confirm';
	try {
		if (confirmKind.value === 'purge') {
			const purged = await getAdminApi().purgeDeadJobs();
			showToast(t('aq.purged', { count: purged }), 'success');
		} else {
			const retried = await getAdminApi().retryDeadJobs();
			showToast(t('aq.retried', { count: retried }), 'success');
		}
		confirmOpen.value = false;
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('aq.actionFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

onMounted(() => {
	void load();
	timer = window.setInterval(() => void load(true), 20_000);
});

onBeforeUnmount(() => {
	window.clearInterval(timer);
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">{{ t('aq.eyebrow') }}</p>
				<h2>{{ t('aq.title') }}</h2>
				<p class="a-admin-lead">{{ t('aq.lead') }}</p>
			</div>
			<div class="a-admin-actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
					{{ t('common.refresh') }}
				</button>
				<button type="button" class="a-btn a-btn--primary a-btn--small" :disabled="busy !== ''" @click="runQueue">
					<AppIcon name="activity" :size="15" />
					{{ t('aq.startWorker') }}
				</button>
			</div>
		</div>

		<div class="a-admin-stat-grid a-admin-stat-grid--compact">
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="clock" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">{{ t('aq.pending') }}</p>
					<strong class="a-admin-stat__value">{{ counts.pending }}</strong>
					<p class="a-admin-stat__meta">
						{{ counts.oldest_pending ? t('aq.oldest', { time: relativeTime(counts.oldest_pending) }) : t('aq.nothingScheduled') }}
					</p>
				</div>
			</article>
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="activity" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">{{ t('aq.running') }}</p>
					<strong class="a-admin-stat__value">{{ counts.running }}</strong>
					<p class="a-admin-stat__meta">{{ t('aq.reserved') }}</p>
				</div>
			</article>
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="alert" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">{{ t('aq.failed') }}</p>
					<strong class="a-admin-stat__value">{{ counts.dead }}</strong>
					<p class="a-admin-stat__meta">{{ t('aq.permanent') }}</p>
				</div>
			</article>
			<article v-if="errorClasses.length > 0" class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="alert" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">{{ t('aq.failureClasses') }}</p>
					<strong class="a-admin-stat__value">{{ errorClasses.length }}</strong>
					<p class="a-admin-stat__meta">{{ errorClasses.map(([name, count]) => `${name} ×${count}`).join(', ') }}</p>
				</div>
			</article>
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">{{ t('aq.total') }}</p>
					<strong class="a-admin-stat__value">{{ counts.total }}</strong>
					<p class="a-admin-stat__meta">{{ t('aq.allEntries') }}</p>
				</div>
			</article>
		</div>

		<div class="a-admin-toolbar a-admin-toolbar--wrap">
			<div class="a-admin-filterbar" role="tablist" :aria-label="t('aq.filterAria')">
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
					{{ t(filter.label) }}
				</button>
			</div>
			<div class="a-admin-actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="busy !== '' || counts.dead === 0" @click="ask('retryAll')">
					<AppIcon name="refresh" :size="15" /> {{ t('aq.retryDead') }}
				</button>
				<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="busy !== '' || counts.dead === 0" @click="ask('purge')">
					<AppIcon name="trash" :size="15" /> {{ t('aq.deleteOld') }}
				</button>
			</div>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && data === null" class="a-admin-skeleton-grid a-admin-skeleton-grid--rows">
			<span v-for="index in 5" :key="index" class="a-admin-skeleton" />
		</div>

		<div v-else class="a-admin-table-wrap">
			<table class="a-admin-table">
				<thead>
					<tr>
						<th scope="col">{{ t('aq.colType') }}</th>
						<th scope="col">{{ t('aq.colStatus') }}</th>
						<th scope="col">{{ t('aq.colAttempts') }}</th>
						<th scope="col">{{ t('aq.colDue') }}</th>
						<th scope="col">{{ t('aq.colDetails') }}</th>
						<th scope="col"><span class="a-admin-sr">{{ t('aq.colActions') }}</span></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="job in jobs" :key="job.id">
						<td>
							<strong class="a-admin-mono">{{ job.type }}</strong>
							<small class="a-admin-mono a-admin-muted">{{ job.id.slice(0, 12) }}</small>
							<small v-if="job.last_error" class="a-admin-muted a-admin-job-error">{{ job.last_error }}</small>
						</td>
						<td>
							<span class="a-admin-badge" :class="`a-admin-badge--${stateTone(job.state)}`">{{ stateLabel(job.state) }}</span>
						</td>
						<td class="a-admin-num">{{ job.attempts }}</td>
						<td class="a-admin-nowrap" :title="absoluteTime(job.available_at)">
							{{ relativeTime(job.available_at) }}
							<small v-if="job.dead_at" class="a-admin-muted">{{ t('aq.deadSince', { time: relativeTime(job.dead_at) }) }}</small>
						</td>
						<td>
							<details class="a-admin-details">
								<summary>{{ t('aq.payload') }}</summary>
								<pre>{{ JSON.stringify(job.payload, null, 2) }}</pre>
							</details>
						</td>
						<td>
							<div class="a-admin-row-actions">
								<button type="button" class="a-icon-btn" :title="t('aq.retryAria')" :aria-label="t('aq.retryAria')" :disabled="busy !== '' || !job.retryable" @click="retry(job)">
									<AppIcon name="refresh" :size="16" />
								</button>
								<button type="button" class="a-icon-btn a-icon-btn--danger" :title="t('aq.deleteAria')" :aria-label="t('aq.deleteAria')" :disabled="busy !== ''" @click="remove(job)">
									<AppIcon name="trash" :size="16" />
								</button>
							</div>
						</td>
					</tr>
					<tr v-if="jobs.length === 0">
						<td colspan="6" class="a-admin-empty">
							{{ t(state === 'all' ? 'aq.emptyAll' : 'aq.emptyStatus') }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

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
