<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref } from 'vue';
import AccountRow from '../components/AccountRow.vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import FollowButton from '../components/FollowButton.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { getApi } from '../lib/api';
import { appState, showToast } from '../lib/state';
import type { Account, HashtagResult, SearchResults, TimelineItem } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const query = ref('');
const searching = ref(false);
const searched = ref(false);
const error = ref('');
const results = ref<SearchResults>({ accounts: [], statuses: [], hashtags: [] });
const remote = ref<Account | null>(null);
const remoteTried = ref(false);

const hasResults = computed(() => (
	results.value.accounts.length > 0
	|| results.value.statuses.length > 0
	|| results.value.hashtags.length > 0
	|| remote.value !== null
));

function looksRemote(value: string): boolean {
	return value.includes('@') || /^[^\s@]+\.[^\s@]+$/.test(value);
}

async function search(): Promise<void> {
	const value = query.value.trim();
	if (value === '') {
		return;
	}

	searching.value = true;
	searched.value = true;
	error.value = '';
	remote.value = null;
	remoteTried.value = false;

	if (!signedIn.value) {
		error.value = 'Melde dich an, um Beiträge und Konten zu durchsuchen.';
		searching.value = false;
		return;
	}

	try {
		results.value = await getApi().search(value, 30);
	} catch (caught) {
		results.value = { accounts: [], statuses: [], hashtags: [] };
		error.value = caught instanceof Error ? caught.message : 'Die Suche ist fehlgeschlagen.';
	} finally {
		searching.value = false;
	}

	if (!looksRemote(value)) {
		return;
	}

	remoteTried.value = true;
	try {
		const profile = await getApi().remoteProfile(value.replace(/^@/, ''), null);
		remote.value = profile.profile;
	} catch {
		// A query that looks like a handle but cannot be resolved is simply a
		// local search with no remote hit.
		remote.value = null;
	}
}

function hashtagHref(tag: HashtagResult): string {
	return `#/tag/${encodeURIComponent(tag.name)}`;
}
</script>

<template>
	<div class="a-page">
		<PageHeader title="Suchen" subtitle="Konten, Beiträge und Hashtags auf dieser Instanz." />

		<form class="a-search" role="search" @submit.prevent="search">
			<AppIcon name="search" :size="19" />
			<input
				v-model="query"
				type="search"
				placeholder="Suche nach @konto, #hashtag oder einem Wort …"
				aria-label="Suchbegriff"
				autocomplete="off"
			/>
			<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="searching || query.trim() === ''">
				<AppIcon v-if="searching" name="refresh" :size="15" class="a-spin" />
				{{ searching ? 'Suche…' : 'Suchen' }}
			</button>
		</form>

		<p v-if="!signedIn" class="a-search-hint">
			<AppIcon name="lock" :size="14" />
			<span>Die lokale Suche ist nur für angemeldete Konten verfügbar.</span>
			<a href="/login">Anmelden</a>
		</p>

		<p v-if="error" class="a-form-error" role="alert">{{ error }}</p>

		<TimelineSkeleton v-if="searching && !hasResults" :count="3" />

		<template v-else-if="searched">
			<EmptyState
				v-if="!hasResults && error === ''"
				icon="search"
				title="Keine Treffer"
				text="Versuche einen anderen Begriff, ein Konto mit @ oder einen Hashtag mit #."
			/>

			<section v-if="remote" class="a-explore-section">
				<div class="a-section-head">
					<h2>Profil im Fediverse</h2>
					<span>{{ remote.network === 'bluesky' ? 'Bluesky' : 'ActivityPub' }}</span>
				</div>
				<AccountRow :account="remote" :href="`#/u/${encodeURIComponent(remote.handle || remote.id)}`" />
			</section>

			<section v-if="results.accounts.length > 0" class="a-explore-section">
				<div class="a-section-head">
					<h2>Konten</h2>
					<span>{{ results.accounts.length }}</span>
				</div>
				<div class="a-people">
					<AccountRow v-for="account in results.accounts" :key="account.id" :account="account">
						<template #actions>
							<FollowButton :account="account" compact @changed="showToast($event ? `Du folgst jetzt @${account.handle}.` : `Du folgst @${account.handle} nicht mehr.`, 'success')" />
						</template>
					</AccountRow>
				</div>
			</section>

			<section v-if="results.hashtags.length > 0" class="a-explore-section">
				<div class="a-section-head">
					<h2>Hashtags</h2>
					<span>{{ results.hashtags.length }}</span>
				</div>
				<div class="a-tag-cloud">
					<a v-for="tag in results.hashtags" :key="tag.name" :href="hashtagHref(tag)" class="a-tag-chip">
						<span class="a-tag-chip__hash">#</span>{{ tag.name }}
					</a>
				</div>
			</section>

			<section v-if="results.statuses.length > 0" class="a-explore-section">
				<div class="a-section-head">
					<h2>Beiträge</h2>
					<span>{{ results.statuses.length }}</span>
				</div>
				<div class="a-feed__list">
					<PostCard v-for="post in results.statuses" :key="post.id" :post="post" />
				</div>
			</section>
		</template>

		<EmptyState
			v-else
			icon="search"
			title="Finde Menschen und Gespräche"
			text="Die Suche findet lokale Konten, öffentliche Beiträge und Hashtags. Ein @konto@server wird zusätzlich im Fediverse aufgelöst."
		/>
	</div>
</template>
