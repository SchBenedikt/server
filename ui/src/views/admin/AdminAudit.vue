<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { getAdminApi, type AuditEntry } from '../../lib/admin';
import { absoluteTime, relativeTime } from '../../lib/format';

const entries = ref<AuditEntry[]>([]);
const loading = ref(false);
const error = ref('');
const query = ref('');
const limit = ref(100);

const filtered = computed(() => {
	const needle = query.value.trim().toLowerCase();
	if (needle === '') {
		return entries.value;
	}

	return entries.value.filter((entry) => (
		entry.action.toLowerCase().includes(needle)
		|| entry.actor_id.toLowerCase().includes(needle)
		|| (entry.target_id ?? '').toLowerCase().includes(needle)
		|| JSON.stringify(entry.details).toLowerCase().includes(needle)
	));
});

const grouped = computed(() => {
	const groups = new Map<string, AuditEntry[]>();
	for (const entry of filtered.value) {
		const day = entry.created_at.slice(0, 10);
		const list = groups.get(day) ?? [];
		list.push(entry);
		groups.set(day, list);
	}

	return [...groups.entries()];
});

function actionTone(action: string): string {
	if (action.includes('delete') || action.includes('disable') || action.includes('dismissed')) {
		return 'danger';
	}
	if (action.includes('role') || action.includes('password') || action.includes('sessions')) {
		return 'amber';
	}
	if (action.includes('create') || action.includes('enable') || action.includes('resolved')) {
		return 'mint';
	}

	return 'muted';
}

function actionLabel(action: string): string {
	const labels: Record<string, string> = {
		'user.create': 'Account created',
		'user.role': 'Role changed',
		'user.display_name': 'Display name changed',
		'user.disable': 'account deactivated',
		'user.enable': 'account activated',
		'user.password': 'Password changed',
		'user.sessions': 'Sessions ended',
		'report.create': 'Report received',
		'report.resolved': 'Report resolved',
		'report.dismissed': 'Report dismissed',
		'post.delete': 'Post deleted',
		'job.retry': 'Job retried',
		'job.delete': 'job deleted',
		'job.retry_all': 'Dead jobs retried',
		'job.purge': 'Old jobs deleted',
		'queue.run': 'Worker run started',
	};

	return labels[action] ?? action;
}

function detailsText(details: Record<string, unknown>): string {
	const parts = Object.entries(details).map(([key, value]) => {
		if (typeof value === 'string') {
			return `${key}: ${value}`;
		}
		if (typeof value === 'number' || typeof value === 'boolean') {
			return `${key}: ${String(value)}`;
		}

		return `${key}: ${JSON.stringify(value)}`;
	});

	return parts.join(' · ');
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		entries.value = await getAdminApi().audit(limit.value);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The audit log could not be loaded.';
	} finally {
		loading.value = false;
	}
}

function changeLimit(): void {
	void load();
}

onMounted(() => {
	void load();
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">Traceability</p>
				<h2>Audit log</h2>
				<p class="a-admin-lead">
					Every privileged change is stored with actor, target, time and details.
					The log is immutable and serves as an audit trail.
				</p>
			</div>
			<div class="a-admin-actions">
				<select v-model.number="limit" class="a-admin-select a-admin-select--small" aria-label="Number of entries" @change="changeLimit">
					<option :value="50">50 entries</option>
					<option :value="100">100 entries</option>
					<option :value="250">250 entries</option>
					<option :value="500">500 entries</option>
				</select>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
					Refresh
				</button>
			</div>
		</div>

		<label class="a-admin-search a-admin-search--wide">
			<AppIcon name="search" :size="17" />
			<input v-model="query" type="search" placeholder="Search action, account, target or detail" autocomplete="off">
		</label>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && entries.length === 0" class="a-admin-skeleton-grid a-admin-skeleton-grid--rows">
			<span v-for="index in 6" :key="index" class="a-admin-skeleton" />
		</div>

		<div v-else-if="filtered.length === 0" class="a-admin-empty-panel">
			<AppIcon name="shield" :size="24" />
			<div>
				<strong>No matching entries</strong>
				<p>{{ query !== '' ? 'Adjust your search.' : 'As soon as an administrative action is performed, it appears here.' }}</p>
			</div>
		</div>

		<div v-else class="a-admin-audit">
			<section v-for="[day, dayEntries] in grouped" :key="day" class="a-admin-audit__day">
				<h3 class="a-admin-audit__date">{{ absoluteTime(`${day}T00:00:00Z`).split(',')[0] }}</h3>
				<ul>
					<li v-for="entry in dayEntries" :key="entry.id" class="a-admin-audit__entry">
						<span class="a-admin-audit__line" aria-hidden="true" />
						<div class="a-admin-audit__body">
							<div class="a-admin-audit__head">
								<span class="a-admin-badge" :class="`a-admin-badge--${actionTone(entry.action)}`">
									{{ actionLabel(entry.action) }}
								</span>
								<span class="a-admin-audit__actor">@{{ entry.actor_id }}</span>
								<span class="a-admin-audit__time" :title="absoluteTime(entry.created_at)">
									{{ relativeTime(entry.created_at) }}
								</span>
							</div>
							<p v-if="entry.target_type || entry.target_id" class="a-admin-audit__target">
								<AppIcon name="external" :size="13" />
								{{ entry.target_type ?? 'Target' }}
								<code v-if="entry.target_id" class="a-admin-mono">{{ entry.target_id }}</code>
							</p>
							<p v-if="Object.keys(entry.details).length > 0" class="a-admin-audit__details">
								{{ detailsText(entry.details) }}
							</p>
						</div>
					</li>
				</ul>
			</section>
		</div>
	</section>
</template>
