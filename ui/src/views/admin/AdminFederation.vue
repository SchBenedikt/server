<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { getAdminApi, type FederationData } from '../../lib/admin';
import { absoluteTime, formatCount, relativeTime } from '../../lib/format';

const data = ref<FederationData | null>(null);
const loading = ref(false);
const error = ref('');

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		data.value = await getAdminApi().federation();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Federation data could not be loaded.';
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
				<p class="a-admin-eyebrow">Network</p>
				<h2>Federation and blocks</h2>
				<p class="a-admin-lead">
					Which peers deliver posts, how many remote actors are known and
					which accounts are blocked. Remote posts are cached read-only.
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
					<span class="a-admin-stat__icon"><AppIcon name="server" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Peers</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.peers) }}</strong>
						<p class="a-admin-stat__meta">Domains with cached posts</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="message" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Remote posts</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.remote_posts) }}</strong>
						<p class="a-admin-stat__meta">{{ formatCount(data.counts.remote_actors) }} actors</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="users" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Connections</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.followers + data.counts.following) }}</strong>
						<p class="a-admin-stat__meta">{{ data.counts.followers }} followers · {{ data.counts.following }} following</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="ban" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">blocks</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.blocks) }}</strong>
						<p class="a-admin-stat__meta">Active blocks of local accounts</p>
					</div>
				</article>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Top peers</h3>
							<p>Domains with the most cached posts.</p>
						</div>
					</header>
					<div class="a-admin-table-wrap a-admin-table-wrap--flush">
						<table class="a-admin-table a-admin-table--compact">
							<thead>
								<tr>
									<th scope="col">Domain</th>
									<th scope="col">Posts</th>
									<th scope="col">actors</th>
									<th scope="col">Share</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="peer in data.peers" :key="peer.domain">
									<td><strong>{{ peer.domain }}</strong></td>
									<td class="a-admin-num">{{ formatCount(peer.posts) }}</td>
									<td class="a-admin-num">{{ formatCount(peer.actors) }}</td>
									<td>
										<span class="a-admin-meter">
											<span :style="{ width: `${Math.min(100, (peer.posts / Math.max(1, data.counts.remote_posts)) * 100)}%` }" />
										</span>
									</td>
								</tr>
								<tr v-if="data.peers.length === 0">
									<td colspan="4" class="a-admin-empty">No peers seen yet.</td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Blocked actors</h3>
							<p>Blocks are stored locally and apply in both directions.</p>
						</div>
					</header>
					<ul class="a-admin-list">
						<li v-for="block in data.blocks" :key="block.id" class="a-admin-list__row">
							<div>
								<strong>{{ block.actor }}</strong>
								<small>{{ block.domain }} · by @{{ block.local_user_id }} · {{ relativeTime(block.blocked_at) }}</small>
							</div>
							<span class="a-admin-badge a-admin-badge--danger">{{ block.domain }}</span>
						</li>
						<li v-if="data.blocks.length === 0" class="a-admin-empty">No active blocks.</li>
					</ul>
				</section>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Recently seen remote posts</h3>
						<p>An excerpt from the read-only cache.</p>
					</div>
				</header>
				<ul class="a-admin-list">
					<li v-for="post in data.recent" :key="post.id" class="a-admin-list__row a-admin-list__row--post">
						<a class="a-admin-post" :href="post.id" target="_blank" rel="noopener noreferrer">
							<strong>{{ post.author }}</strong>
							<span>{{ post.content }}</span>
							<small>{{ post.domain }} · {{ absoluteTime(post.published_at) }}</small>
						</a>
					</li>
					<li v-if="data.recent.length === 0" class="a-admin-empty">No remote posts in the cache yet.</li>
				</ul>
			</section>
		</template>
	</section>
</template>
