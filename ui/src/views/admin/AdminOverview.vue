<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { bytesLabel, getAdminApi, type AdminOverview } from '../../lib/admin';
import { absoluteTime, formatCount, relativeTime } from '../../lib/format';
import { showToast } from '../../lib/state';

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
		error.value = caught instanceof Error ? caught.message : 'The overview could not be loaded.';
	} finally {
		loading.value = false;
	}
}

function refresh(): void {
	void load();
	showToast('Overview updated.', 'success');
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
				<p class="a-admin-eyebrow">Overview</p>
				<h2>What's happening on this instance right now</h2>
				<p class="a-admin-lead">
					Metrics, system health and recent changes. The view refreshes
					every 30 seconds without reloading the page.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="refresh">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				Refresh
			</button>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && overview === null" class="a-admin-skeleton-grid">
			<span v-for="index in 6" :key="index" class="a-admin-skeleton" />
		</div>

		<template v-else-if="overview">
			<div class="a-admin-stat-grid">
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="users" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">accounts</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.accounts.total) }}</strong>
						<p class="a-admin-stat__meta">
							{{ overview.stats.accounts.active }} active · {{ overview.stats.accounts.admins }} admins
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="message" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Posts</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.posts.total) }}</strong>
						<p class="a-admin-stat__meta">
							{{ overview.stats.posts.today }} today · {{ overview.stats.posts.public }} public
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="globe" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Federation</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.federation.peer_domains) }}</strong>
						<p class="a-admin-stat__meta">
							{{ overview.stats.federation.remote_posts }} remote posts
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">queue</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.queue.pending) }}</strong>
						<p class="a-admin-stat__meta">
							{{ overview.stats.queue.running }} running · {{ overview.stats.queue.dead }} dead
						</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="flag" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Open Reports</p>
						<strong class="a-admin-stat__value">{{ formatCount(overview.stats.reports.open) }}</strong>
						<p class="a-admin-stat__meta">{{ overview.stats.reports.total }} total</p>
					</div>
				</article>

				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="image" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Media storage</p>
						<strong class="a-admin-stat__value">{{ bytesLabel(overview.stats.media.blob_bytes) }}</strong>
						<p class="a-admin-stat__meta">
							{{ overview.stats.media.blobs }} files · {{ overview.stats.media.attachments }} Attachments
						</p>
					</div>
				</article>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>System health</h3>
							<p>Database, worker, storage and configuration.</p>
						</div>
						<span class="a-admin-pill" :class="`a-admin-pill--${summaryTone}`">
							{{ healthCounts.ok }} OK<span v-if="healthCounts.warn > 0"> · {{ healthCounts.warn }} Warning</span><span v-if="healthCounts.error > 0"> · {{ healthCounts.error }} Error</span>
						</span>
					</header>
					<ul class="a-admin-health">
						<li v-for="check in overview.health" :key="check.id" class="a-admin-health__item">
							<span class="a-admin-dot" :class="`a-admin-dot--${check.status}`" aria-hidden="true" />
							<div>
								<strong>{{ check.label }}</strong>
								<small>{{ check.detail }}</small>
							</div>
							<span class="a-admin-health__state">{{ check.status === 'ok' ? 'OK' : check.status === 'warn' ? 'Warning' : 'Error' }}</span>
						</li>
					</ul>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Worker</h3>
							<p>The process that runs deliveries and cleanup jobs.</p>
						</div>
						<span class="a-admin-pill" :class="overview.worker.online ? 'a-admin-pill--ok' : 'a-admin-pill--warn'">
							{{ overview.worker.online ? 'Online' : 'No signal' }}
						</span>
					</header>
					<dl class="a-admin-facts">
						<div>
							<dt>Last heartbeat</dt>
							<dd>{{ overview.worker.last_beat ?? 'None yet' }}</dd>
						</div>
						<div>
							<dt>Timestamp</dt>
							<dd>{{ overview.worker.updated_at ? absoluteTime(overview.worker.updated_at) : '–' }}</dd>
						</div>
						<div>
							<dt>Scheduled posts</dt>
							<dd>{{ overview.stats.scheduled.pending }} pending · {{ overview.stats.scheduled.published }} published</dd>
						</div>
						<div>
							<dt>Notifications</dt>
							<dd>{{ overview.stats.notifications.total }} total · {{ overview.stats.notifications.unread }} unread</dd>
						</div>
					</dl>
					<p v-if="!overview.worker.online" class="a-admin-hint">
						Start the worker with <code>php bin/worker.php</code> or a cron entry for
						<code>--once</code>.
					</p>
					<div class="a-admin-actions">
						<a class="a-btn a-btn--ghost a-btn--small" href="#/admin/queue">Open queue</a>
						<a class="a-btn a-btn--ghost a-btn--small" href="#/admin/settings">Check instance</a>
					</div>
				</section>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>New accounts</h3>
							<p>The most recently created accounts on this instance.</p>
						</div>
						<a class="a-admin-link" href="#/admin/users">All accounts</a>
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
								{{ user.role === 'admin' ? 'Admin' : 'Member' }}
							</span>
						</li>
						<li v-if="overview.recent_users.length === 0" class="a-admin-empty">No accounts yet.</li>
					</ul>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Recent posts</h3>
							<p>Public and internal posts, newest first.</p>
						</div>
					</header>
					<ul class="a-admin-list">
						<li v-for="post in overview.recent_posts" :key="post.id" class="a-admin-list__row a-admin-list__row--post">
							<a :href="`#/p/${encodeURIComponent(post.id)}`" class="a-admin-post">
								<strong>{{ post.author_name }}</strong>
								<span>{{ post.content }}</span>
								<small>
									{{ relativeTime(post.published_at) }} ·
									{{ post.visibility }}{{ post.reply ? ' · Reply' : '' }}
								</small>
							</a>
						</li>
						<li v-if="overview.recent_posts.length === 0" class="a-admin-empty">No posts yet.</li>
					</ul>
				</section>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Recent administrative changes</h3>
						<p>An excerpt from the audit log.</p>
					</div>
					<a class="a-admin-link" href="#/admin/audit">Full audit log</a>
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
					<li v-if="overview.audit.length === 0" class="a-admin-empty">No logged actions yet.</li>
				</ul>
			</section>
		</template>
	</section>
</template>
