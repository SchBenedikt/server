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
	{ id: 'all', label: 'Alle' },
	{ id: 'pending', label: 'Wartend' },
	{ id: 'running', label: 'Laufend' },
	{ id: 'dead', label: 'Fehlgeschlagen' },
];

const jobs = computed(() => data.value?.jobs ?? []);
const counts = computed(() => data.value?.counts ?? { pending: 0, running: 0, dead: 0, total: 0, oldest_pending: null });

const confirmCopy = computed(() => (
	confirmKind.value === 'purge'
		? {
			title: 'Fehlgeschlagene Jobs aufräumen',
			message: 'Alle endgültig fehlgeschlagenen Jobs, die älter als sieben Tage sind, werden gelöscht. Neuere Fehlversuche bleiben zur Untersuchung erhalten.',
			label: 'Alte Jobs löschen',
		}
		: {
			title: 'Alle fehlgeschlagenen Jobs wiederholen',
			message: 'Jeder tote Job wird zurückgesetzt und sofort erneut versucht. Fehlerhafte Zustellungen können dadurch erneut an Gegenstellen gehen.',
			label: 'Alle wiederholen',
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
		error.value = caught instanceof Error ? caught.message : 'Die Warteschlange konnte nicht geladen werden.';
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
	switch (value) {
		case 'pending':
			return 'Wartend';
		case 'running':
			return 'Laufend';
		case 'dead':
			return 'Tot';
		default:
			return value;
	}
}

async function retry(job: AdminJob): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = `retry:${job.id}`;
	try {
		await getAdminApi().retryJob(job.id);
		showToast('Job wiederholt.', 'success');
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Der Job konnte nicht wiederholt werden.', 'error');
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
		showToast('Job gelöscht.', 'success');
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Der Job konnte nicht gelöscht werden.', 'error');
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
		showToast(handled === 0 ? 'Keine fälligen Jobs.' : `${handled} Job${handled === 1 ? '' : 's'} ausgeführt.`, 'success');
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Der Worker-Lauf ist fehlgeschlagen.', 'error');
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
			showToast(`${purged} alte Job${purged === 1 ? '' : 's'} gelöscht.`, 'success');
		} else {
			const retried = await getAdminApi().retryDeadJobs();
			showToast(`${retried} Job${retried === 1 ? '' : 's'} zurückgesetzt.`, 'success');
		}
		confirmOpen.value = false;
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Aktion konnte nicht ausgeführt werden.', 'error');
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
				<p class="a-admin-eyebrow">Betrieb</p>
				<h2>Jobs und Zustellungen</h2>
				<p class="a-admin-lead">
					Der Worker verarbeitet föderierte Zustellungen, Aufräumläufe und geplante Beiträge.
					Hier siehst du hängende, laufende und endgültig fehlgeschlagene Jobs.
				</p>
			</div>
			<div class="a-admin-actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
					Aktualisieren
				</button>
				<button type="button" class="a-btn a-btn--primary a-btn--small" :disabled="busy !== ''" @click="runQueue">
					<AppIcon name="activity" :size="15" />
					Worker-Lauf starten
				</button>
			</div>
		</div>

		<div class="a-admin-stat-grid a-admin-stat-grid--compact">
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="clock" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">Wartend</p>
					<strong class="a-admin-stat__value">{{ counts.pending }}</strong>
					<p class="a-admin-stat__meta">
						{{ counts.oldest_pending ? `Ältester: ${relativeTime(counts.oldest_pending)}` : 'Nichts geplant' }}
					</p>
				</div>
			</article>
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="activity" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">Laufend</p>
					<strong class="a-admin-stat__value">{{ counts.running }}</strong>
					<p class="a-admin-stat__meta">Reserviert durch einen Worker</p>
				</div>
			</article>
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="alert" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">Fehlgeschlagen</p>
					<strong class="a-admin-stat__value">{{ counts.dead }}</strong>
					<p class="a-admin-stat__meta">Endgültig nach allen Versuchen</p>
				</div>
			</article>
			<article class="a-admin-stat">
				<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
				<div>
					<p class="a-admin-stat__label">Gesamt</p>
					<strong class="a-admin-stat__value">{{ counts.total }}</strong>
					<p class="a-admin-stat__meta">Alle Einträge der Warteschlange</p>
				</div>
			</article>
		</div>

		<div class="a-admin-toolbar a-admin-toolbar--wrap">
			<div class="a-admin-filterbar" role="tablist" aria-label="Job-Status">
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
				</button>
			</div>
			<div class="a-admin-actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="busy !== '' || counts.dead === 0" @click="ask('retryAll')">
					<AppIcon name="refresh" :size="15" /> Tote Jobs wiederholen
				</button>
				<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="busy !== '' || counts.dead === 0" @click="ask('purge')">
					<AppIcon name="trash" :size="15" /> Alte Jobs löschen
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
						<th scope="col">Typ</th>
						<th scope="col">Status</th>
						<th scope="col">Versuche</th>
						<th scope="col">Fällig</th>
						<th scope="col">Details</th>
						<th scope="col"><span class="a-admin-sr">Aktionen</span></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="job in jobs" :key="job.id">
						<td>
							<strong class="a-admin-mono">{{ job.type }}</strong>
							<small class="a-admin-mono a-admin-muted">{{ job.id.slice(0, 12) }}</small>
						</td>
						<td>
							<span class="a-admin-badge" :class="`a-admin-badge--${stateTone(job.state)}`">{{ stateLabel(job.state) }}</span>
						</td>
						<td class="a-admin-num">{{ job.attempts }}</td>
						<td class="a-admin-nowrap" :title="absoluteTime(job.available_at)">
							{{ relativeTime(job.available_at) }}
							<small v-if="job.dead_at" class="a-admin-muted">tot seit {{ relativeTime(job.dead_at) }}</small>
						</td>
						<td>
							<details class="a-admin-details">
								<summary>Payload</summary>
								<pre>{{ JSON.stringify(job.payload, null, 2) }}</pre>
							</details>
						</td>
						<td>
							<div class="a-admin-row-actions">
								<button type="button" class="a-icon-btn" title="Job wiederholen" aria-label="Job wiederholen" :disabled="busy !== ''" @click="retry(job)">
									<AppIcon name="refresh" :size="16" />
								</button>
								<button type="button" class="a-icon-btn a-icon-btn--danger" title="Job löschen" aria-label="Job löschen" :disabled="busy !== ''" @click="remove(job)">
									<AppIcon name="trash" :size="16" />
								</button>
							</div>
						</td>
					</tr>
					<tr v-if="jobs.length === 0">
						<td colspan="6" class="a-admin-empty">
							{{ state === 'all' ? 'Die Warteschlange ist leer.' : 'Keine Jobs in diesem Status.' }}
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
