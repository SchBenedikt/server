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
		error.value = caught instanceof Error ? caught.message : 'Die Föderationsdaten konnten nicht geladen werden.';
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
				<p class="a-admin-eyebrow">Netzwerk</p>
				<h2>Föderation und Blockierungen</h2>
				<p class="a-admin-lead">
					Welche Gegenstellen Beiträge liefern, wie viele entfernte Akteure bekannt sind und
					welche Konten blockiert wurden. Entfernte Beiträge liegen nur als Lesezugriff im Cache.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				Aktualisieren
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
						<p class="a-admin-stat__label">Gegenstellen</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.peers) }}</strong>
						<p class="a-admin-stat__meta">Domains mit zwischengespeicherten Beiträgen</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="message" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Entfernte Beiträge</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.remote_posts) }}</strong>
						<p class="a-admin-stat__meta">{{ formatCount(data.counts.remote_actors) }} Akteure</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="users" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Verbindungen</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.followers + data.counts.following) }}</strong>
						<p class="a-admin-stat__meta">{{ data.counts.followers }} Follower · {{ data.counts.following }} folgt</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="ban" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Blockierungen</p>
						<strong class="a-admin-stat__value">{{ formatCount(data.counts.blocks) }}</strong>
						<p class="a-admin-stat__meta">Aktive Blockierungen lokaler Konten</p>
					</div>
				</article>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Stärkste Gegenstellen</h3>
							<p>Domains mit den meisten zwischengespeicherten Beiträgen.</p>
						</div>
					</header>
					<div class="a-admin-table-wrap a-admin-table-wrap--flush">
						<table class="a-admin-table a-admin-table--compact">
							<thead>
								<tr>
									<th scope="col">Domain</th>
									<th scope="col">Beiträge</th>
									<th scope="col">Akteure</th>
									<th scope="col">Anteil</th>
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
									<td colspan="4" class="a-admin-empty">Noch keine Gegenstellen gesehen.</td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Blockierte Akteure</h3>
							<p>Blockierungen werden lokal gespeichert und wirken in beide Richtungen.</p>
						</div>
					</header>
					<ul class="a-admin-list">
						<li v-for="block in data.blocks" :key="block.id" class="a-admin-list__row">
							<div>
								<strong>{{ block.actor }}</strong>
								<small>{{ block.domain }} · von @{{ block.local_user_id }} · {{ relativeTime(block.blocked_at) }}</small>
							</div>
							<span class="a-admin-badge a-admin-badge--danger">{{ block.domain }}</span>
						</li>
						<li v-if="data.blocks.length === 0" class="a-admin-empty">Keine aktiven Blockierungen.</li>
					</ul>
				</section>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>Zuletzt gesehene entfernte Beiträge</h3>
						<p>Ein Auszug aus dem Lesezugriff-Cache.</p>
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
					<li v-if="data.recent.length === 0" class="a-admin-empty">Noch keine entfernten Beiträge im Cache.</li>
				</ul>
			</section>
		</template>
	</section>
</template>
