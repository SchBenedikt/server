<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { absoluteTime, formatCount, relativeTime } from '../../lib/format';
import { getAdminApi, type CommunicationData } from '../../lib/admin';
import { t } from '../../lib/i18n';

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
		error.value = caught instanceof Error ? caught.message : t('aerr.loadFailed');
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
				<p class="a-admin-eyebrow">{{ t('aq.eyebrow') }}</p>
				<h2>{{ t('aerr.title') }}</h2>
				<p class="a-admin-lead">{{ t('aerr.lead') }}</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				{{ t('common.refresh') }}
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
						<p class="a-admin-stat__label">{{ t('aerr.last24h') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.last_24h) }}</strong>
						<p class="a-admin-stat__meta">{{ t('aerr.recorded') }}</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="alert" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aerr.warnings') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.warnings) }}</strong>
						<p class="a-admin-stat__meta">{{ t('aerr.keptReview') }}</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aerr.failures') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.failures) }}</strong>
						<p class="a-admin-stat__meta">{{ t('aerr.excluded') }}</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aerr.deadJobs') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.dead_jobs) }}</strong>
						<p class="a-admin-stat__meta">{{ t('aerr.awaitingRetry') }}</p>
					</div>
				</article>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>{{ t('aerr.recent') }}</h3>
						<p>{{ t('aerr.recentHint') }}</p>
					</div>
					<a class="a-admin-link" href="#/admin/queue">{{ t('aerr.openQueue') }}</a>
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
						{{ t('aerr.emptyErrors') }}
					</li>
				</ul>
			</section>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>{{ t('aerr.deadTitle') }}</h3>
						<p>{{ t('aerr.deadHint') }}</p>
					</div>
				</header>
				<ul class="a-admin-list">
					<li v-for="job in data.dead_jobs" :key="job.id" class="a-admin-list__row">
						<div>
							<strong class="a-admin-mono">{{ job.type }}</strong>
							<small>
								{{ t('aerr.attemptsFailed', { attempts: job.attempts, time: relativeTime(job.dead_at ?? job.available_at) }) }}
								· {{ job.id }}
							</small>
						</div>
						<span class="a-admin-badge a-admin-badge--danger">{{ t('aerr.deadBadge') }}</span>
					</li>
					<li v-if="data.dead_jobs.length === 0" class="a-admin-empty">{{ t('aerr.emptyJobs') }}</li>
				</ul>
			</section>
		</template>
	</section>
</template>
