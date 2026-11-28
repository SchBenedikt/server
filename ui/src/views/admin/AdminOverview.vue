<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { bytesLabel, getAdminApi, type AdminOverview, type ChecklistItem } from '../../lib/admin';
import { absoluteTime, formatCount, relativeTime } from '../../lib/format';
import { showToast } from '../../lib/state';
import { t } from '../../lib/i18n';
import { loadErrorMessage } from '../../lib/api';

const overview = ref<AdminOverview | null>(null);
const loading = ref(false);
const error = ref('');
let timer = 0;

const healthCounts = computed(() => {
	const counts = { ok: 0, warn: 0, error: 0 };
	for (const check of overview.value?.health ?? []) {
		counts[check.status] += 1;
	}

	return counts;
});

const pendingChecklist = computed<ChecklistItem[]>(() => (
	(overview.value?.checklist ?? []).filter((item) => item.status !== 'done')
));

const summaryTone = computed(() => {
	if (healthCounts.value.error > 0) {
		return 'error';
	}
	if (healthCounts.value.warn > 0) {
		return 'warn';
	}

	return 'ok';
});

async function load(quiet = false): Promise<void> {
	if (!quiet) {
		loading.value = true;
	}
	error.value = '';
	try {
		overview.value = await getAdminApi().overview();
	} catch (caught) {
		error.value = loadErrorMessage(caught, t('aov.loadFailed'));
	} finally {
		loading.value = false;
	}
}

function refresh(): void {
	void load();
	showToast(t('aov.updated'), 'success');
}

onMounted(() => {
	void load();
	timer = window.setInterval(() => void load(true), 30_000);
});

onBeforeUnmount(() => {
	window.clearInterval(timer);
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">{{ t('aov.eyebrow') }}</p>
				<h2>{{ t('aov.title') }}</h2>
				<p class="a-admin-lead">{{ t('aov.lead') }}</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="refresh">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				{{ t('common.refresh') }}
			</button>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && overview === null" class="a-admin-skeleton-grid">
			<span v-for="index in 6" :key="index" class="a-admin-skeleton" />
		</div>

		<template v-else-if="overview">
			<section v-if="pendingChecklist.length > 0" class="a-admin-card a-admin-card--checklist">
				<header class="a-admin-card__head">
					<div>
						<h3>{{ t('aov.gettingStarted') }}</h3>
						<p class="a-admin-card__hint">{{ t('aov.stepsLeft', { count: pendingChecklist.length }) }}</p>
					</div>
				</header>
				<ul class="a-admin-checklist">
					<li v-for="item in pendingChecklist" :key="item.id" class="a-admin-checklist__item">
						<span
							class="a-admin-checklist__mark"
							:class="item.status === 'warn' ? 'a-admin-checklist__mark--warn' : 'a-admin-checklist__mark--todo'"
							aria-hidden="true"
						/>
						<div class="a-admin-checklist__copy">
							<strong>{{ item.label }}</strong>
							<small>{{ item.hint }}</small>
						</div>
						<a class="a-btn a-btn--ghost a-btn--small" :href="item.href">{{ t('aov.open') }}</a>
					</li>
				</ul>
			</section>

			<div class="a-admin-stat-grid">
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="users" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aov.accounts') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.accounts.total) }}</strong>
						<p class="a-admin-stat__meta">
							{{ t('aov.accountsMeta', { active: formatCount(overview.stats.accounts.active), admins: formatCount(overview.stats.accounts.admins) }) }}
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="message" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aov.posts') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.posts.total) }}</strong>
						<p class="a-admin-stat__meta">
							{{ t('aov.postsMeta', { today: formatCount(overview.stats.posts.today), public: formatCount(overview.stats.posts.public) }) }}
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="globe" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aov.federation') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.federation.peer_domains) }}</strong>
						<p class="a-admin-stat__meta">
							{{ t('aov.federationMeta', { count: formatCount(overview.stats.federation.remote_posts) }) }}
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aov.queue') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.queue.pending) }}</strong>
						<p class="a-admin-stat__meta">
							{{ t('aov.queueMeta', { running: formatCount(overview.stats.queue.running), dead: formatCount(overview.stats.queue.dead) }) }}
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="flag" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aov.openReports') }}</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.reports.open) }}</strong>
						<p class="a-admin-stat__meta">{{ t('aov.reportsMeta', { count: formatCount(overview.stats.reports.total) }) }}</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="image" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('aov.media') }}</p>
						<strong class="a-admin-stat__value">{{ overview.stats.media.blob_bytes === null ? 'S3 / MinIO' : bytesLabel(overview.stats.media.blob_bytes) }}</strong>
						<p class="a-admin-stat__meta">
							<template v-if="overview.stats.media.blobs === null">{{ t('aov.mediaRemote', { attachments: formatCount(overview.stats.media.attachments) }) }}</template>
							<template v-else>{{ t('aov.mediaLocal', { blobs: formatCount(overview.stats.media.blobs), attachments: formatCount(overview.stats.media.attachments) }) }}</template>
						</p>
					</div>
				</article>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('aov.health') }}</h3>
							<p>{{ t('aov.healthHint') }}</p>
						</div>
						<span class="a-admin-pill" :class="`a-admin-pill--${summaryTone}`">
							{{ t('aov.pillOk', { count: healthCounts.ok }) }}<span v-if="healthCounts.warn > 0"> · {{ t('aov.pillWarn', { count: healthCounts.warn }) }}</span><span v-if="healthCounts.error > 0"> · {{ t('aov.pillError', { count: healthCounts.error }) }}</span>
						</span>
					</header>
					<ul class="a-admin-health">
						<li v-for="check in overview.health" :key="check.id" class="a-admin-health__item">
							<span class="a-admin-dot" :class="`a-admin-dot--${check.status}`" aria-hidden="true" />
							<div>
								<strong>{{ check.label }}</strong>
								<small>{{ check.detail }}</small>
							</div>
							<span class="a-admin-health__state">{{ t(check.status === 'ok' ? 'aov.stateOk' : check.status === 'warn' ? 'aov.stateWarn' : 'aov.stateError') }}</span>
						</li>
					</ul>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('aov.worker') }}</h3>
							<p>{{ t('aov.workerHint') }}</p>
						</div>
						<span class="a-admin-pill" :class="overview.worker.online ? 'a-admin-pill--ok' : 'a-admin-pill--warn'">
							{{ t(overview.worker.online ? 'common.online' : 'common.noSignal') }}
						</span>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>{{ t('aov.lastHeartbeat') }}</dt>
							<dd>{{ overview.worker.last_beat ?? t('aov.noneYet') }}</dd>
						</div>
						<div>
							<dt>{{ t('aov.timestamp') }}</dt>
							<dd>{{ overview.worker.updated_at ? absoluteTime(overview.worker.updated_at) : '–' }}</dd>
						</div>
						<div>
							<dt>{{ t('aov.scheduledPosts') }}</dt>
							<dd>{{ t('aov.scheduledMeta', { pending: formatCount(overview.stats.scheduled.pending), published: formatCount(overview.stats.scheduled.published) }) }}</dd>
						</div>
						<div>
							<dt>{{ t('aov.notifications') }}</dt>
							<dd>{{ t('aov.notificationsMeta', { total: formatCount(overview.stats.notifications.total), unread: formatCount(overview.stats.notifications.unread) }) }}</dd>
						</div>
					</dl>
					<p v-if="!overview.worker.online" class="a-admin-hint">
						{{ t('aov.workerHintStart') }} <code>php bin/worker.php</code> {{ t('aov.workerHintEnd') }}
						<code>--once</code>.
					</p>
					<div class="a-admin-actions">
						<a class="a-btn a-btn--ghost a-btn--small" href="#/admin/queue">{{ t('aov.openQueue') }}</a>
						<a class="a-btn a-btn--ghost a-btn--small" href="#/admin/settings">{{ t('aov.checkInstance') }}</a>
					</div>
				</section>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('aov.newAccounts') }}</h3>
							<p>{{ t('aov.newAccountsHint') }}</p>
						</div>
						<a class="a-admin-link" href="#/admin/users">{{ t('aov.allAccounts') }}</a>
					</header>
					<ul class="a-admin-list">
						<li v-for="user in overview.recent_users" :key="user.id" class="a-admin-list__row">
							<a class="a-admin-user" :href="`#/u/${encodeURIComponent(user.handle)}`">
								<span class="a-admin-avatar">{{ user.handle.slice(0, 2).toUpperCase() }}</span>
								<span>
									<strong>{{ user.display_name ?? `@${user.handle}` }}</strong>
									<small>@{{ user.handle }} · {{ user.email }}</small>
								</span>
							</a>
							<span class="a-admin-badge" :class="user.role === 'admin' ? 'a-admin-badge--primary' : ''">
								{{ t(user.role === 'admin' ? 'aov.admin' : 'aov.member') }}
							</span>
						</li>
						<li v-if="overview.recent_users.length === 0" class="a-admin-empty">{{ t('aov.noAccounts') }}</li>
					</ul>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('aov.recentPosts') }}</h3>
							<p>{{ t('aov.recentPostsHint') }}</p>
						</div>
					</header>
					<ul class="a-admin-list">
						<li v-for="post in overview.recent_posts" :key="post.id" class="a-admin-list__row a-admin-list__row--post">
							<a :href="`#/p/${encodeURIComponent(post.id)}`" class="a-admin-post">
								<strong>{{ post.author_name }}</strong>
								<span>{{ post.content }}</span>
								<small>
									{{ relativeTime(post.published_at) }} ·
									{{ t(`visibility.${post.visibility}`) }}{{ post.reply ? ` · ${t('aov.reply')}` : '' }}
								</small>
							</a>
						</li>
						<li v-if="overview.recent_posts.length === 0" class="a-admin-empty">{{ t('aov.noPosts') }}</li>
					</ul>
				</section>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>{{ t('aov.changes') }}</h3>
						<p>{{ t('aov.changesHint') }}</p>
					</div>
					<a class="a-admin-link" href="#/admin/audit">{{ t('aov.fullAudit') }}</a>
				</header>
				<ul class="a-admin-timeline">
					<li v-for="entry in overview.audit" :key="entry.id">
						<span class="a-admin-timeline__dot" aria-hidden="true" />
						<div>
							<strong>{{ entry.action }}</strong>
							<small>
								{{ entry.actor_id }}
								<template v-if="entry.target_id"> · {{ entry.target_type }} {{ entry.target_id }}</template>
								· {{ relativeTime(entry.created_at) }}
							</small>
						</div>
					</li>
					<li v-if="overview.audit.length === 0" class="a-admin-empty">{{ t('aov.noActions') }}</li>
				</ul>
			</section>
		</template>
	</section>
</template>
