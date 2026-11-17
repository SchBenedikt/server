<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { absoluteTime, formatCount, relativeTime } from '../../lib/format';
import { getAdminApi, type CommunicationData } from '../../lib/admin';

const data = ref<CommunicationData | null>(null);
const loading = ref(false);
const error = ref('');

function levelTone(level: string): string {
	return level === 'warning' ? 'warn' : 'danger';
}

function contextLabel(context: Record<string, unknown>): string {
	const parts: string[] = [];
	for (const [key, value] of Object.entries(context)) {
		if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
			parts.push(`${key}: ${String(value)}`);
		}
	}

	return parts.join(' · ');
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		data.value = await getAdminApi().communications();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Communication errors could not be loaded.';
	} finally {
		loading.value = false;
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
				<p class="a-admin-eyebrow">Operations</p>
				<h2>Delivery, federation and background errors</h2>
				<p class="a-admin-lead">
					Warnings and failures the worker and the federation layer recorded,
					plus jobs that exhausted their retries. The PHP error log still receives
					the full detail; this view keeps the operational picture in the app.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				Refresh
			</button>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && data === null" class="a-admin-skeleton-grid">
			<span v-for="index in 4" :key="index" class="a-admin-skeleton" />
		</div>

		<template v-else-if="data">
			<div class="a-admin-stat-grid a-admin-stat-grid--compact">
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="alert" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Last 24 hours</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.last_24h) }}</strong>
						<p class="a-admin-stat__meta">Recorded errors</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="alert" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Warnings</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.warnings) }}</strong>
						<p class="a-admin-stat__meta">Kept for review</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Failures</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.failures) }}</strong>
						<p class="a-admin-stat__meta">Warnings excluded</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Dead jobs</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.dead_jobs) }}</strong>
						<p class="a-admin-stat__meta">Awaiting a retry</p>
					</div>
				</article>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Recent errors</h3>
						<p>Newest first. A warning is a delivery attempt that was skipped or retried.</p>
					</div>
					<a class="a-admin-link" href="#/admin/queue">Open queue</a>
				</header>
				<ul class="a-admin-list">
					<li v-for="entry in data.errors" :key="entry.id" class="a-admin-list__row a-admin-list__row--error">
						<div>
							<p class="a-admin-error__head">
								<span class="a-admin-badge" :class="`a-admin-badge--${levelTone(entry.level)}`">{{ entry.level }}</span>
								<strong v-if="entry.source !== null" class="a-admin-mono">{{ entry.source }}</strong>
								<span class="a-admin-muted">{{ relativeTime(entry.created_at) }}</span>
							</p>
							<p class="a-admin-error__message">{{ entry.message }}</p>
							<p v-if="contextLabel(entry.context) !== ''" class="a-admin-error__context">
								{{ contextLabel(entry.context) }}
							</p>
							<small class="a-admin-muted">{{ absoluteTime(entry.created_at) }}</small>
						</div>
					</li>
					<li v-if="data.errors.length === 0" class="a-admin-empty">
						No runtime or communication errors recorded.
					</li>
				</ul>
			</section>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Dead delivery jobs</h3>
						<p>Jobs that exhausted their attempts. Retry them from the queue view.</p>
					</div>
				</header>
				<ul class="a-admin-list">
					<li v-for="job in data.dead_jobs" :key="job.id" class="a-admin-list__row">
						<div>
							<strong class="a-admin-mono">{{ job.type }}</strong>
							<small>
								{{ job.attempts }} attempts · failed {{ relativeTime(job.dead_at ?? job.available_at) }}
								· {{ job.id }}
							</small>
						</div>
						<span class="a-admin-badge a-admin-badge--danger">dead</span>
					</li>
					<li v-if="data.dead_jobs.length === 0" class="a-admin-empty">No dead jobs.</li>
				</ul>
			</section>
		</template>
	</section>
</template>
