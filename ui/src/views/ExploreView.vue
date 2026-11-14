<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import FollowButton from '../components/FollowButton.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { accountFor, displayNameFor, hasDistinctDisplayName, resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { discover, discoveryPeople, loadDiscover, popularTags } from '../lib/discover';
import { handleLabel, normalizeTag } from '../lib/format';
import { navigate } from '../lib/router';
import { appState, showToast } from '../lib/state';
import type { FollowedTag } from '../lib/types';

const query = ref('');
const followedTags = ref<FollowedTag[]>([]);
const followedLoading = ref(false);
const tagBusy = ref('');

const signedIn = computed(() => appState.session.user !== null);
const cleanedQuery = computed(() => query.value.trim());
const lowercaseQuery = computed(() => cleanedQuery.value.toLowerCase());
const isHashtagQuery = computed(() => cleanedQuery.value.startsWith('#') && normalizeTag(cleanedQuery.value) !== '');
const suggestedTag = computed(() => normalizeTag(cleanedQuery.value));

const results = computed(() => {
	if (lowercaseQuery.value === '') {
		return [];
	}

	return discover.posts
		.filter((post) => (
			post.content.toLowerCase().includes(lowercaseQuery.value)
			|| post.author_id.toLowerCase().includes(lowercaseQuery.value)
			|| (post.object_author_id ?? '').toLowerCase().includes(lowercaseQuery.value)
		))
		.slice(0, 20);
});

const recent = computed(() => discover.posts.slice(0, 4));

watch(discoveryPeople, (people) => {
	for (const person of people) {
		void resolveAccount(person.id);
	}
}, { immediate: true, deep: true });

onMounted(async () => {
	void loadDiscover();
	if (signedIn.value) {
		await loadFollowedTags();
	}
});

watch(signedIn, async (value) => {
	if (value) {
		await loadFollowedTags();
	} else {
		followedTags.value = [];
	}
});

async function loadFollowedTags(): Promise<void> {
	followedLoading.value = true;
	try {
		followedTags.value = await getApi().followedTags();
	} catch {
		followedTags.value = [];
	} finally {
		followedLoading.value = false;
	}
}

function submitSearch(): void {
	if (isHashtagQuery.value) {
		navigate(`/tag/${encodeURIComponent(suggestedTag.value)}`);
	}
}

function openTag(tag: string): void {
	navigate(`/tag/${encodeURIComponent(tag)}`);
}

async function toggleTag(tag: string): Promise<void> {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	tagBusy.value = tag;
	const isFollowing = followedTags.value.some((entry) => entry.tag === tag);
	try {
		if (isFollowing) {
			await getApi().unfollowTag(tag);
			followedTags.value = followedTags.value.filter((entry) => entry.tag !== tag);
			showToast(`#${tag} wird nicht mehr verfolgt.`, 'success');
		} else {
			await getApi().followTag(tag);
			followedTags.value = [{ tag, created_at: new Date().toISOString() }, ...followedTags.value];
			showToast(`Du folgst jetzt #${tag}.`, 'success');
		}
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'Der Hashtag konnte nicht geändert werden.', 'error');
	} finally {
		tagBusy.value = '';
	}
}

function isFollowingTag(tag: string): boolean {
	return followedTags.value.some((entry) => entry.tag === tag);
}
</script>

<template>
	<div class="a-page">
		<PageHeader title="Entdecken" subtitle="Suche in den öffentlichen Beiträgen dieser Instanz, finde Hashtags und neue Stimmen." />

		<form class="a-search" role="search" @submit.prevent="submitSearch">
			<AppIcon name="search" :size="21" />
			<input
				v-model="query"
				type="search"
				placeholder="Suche nach Worten, Menschen oder #hashtags …"
				aria-label="Öffentliche Beiträge durchsuchen"
			/>
			<button v-if="query !== ''" type="button" class="a-icon-btn" aria-label="Suche leeren" @click="query = ''">
				<AppIcon name="x" :size="18" />
			</button>
			<button type="submit" class="a-btn a-btn--primary a-btn--small">Suchen</button>
		</form>

		<p v-if="isHashtagQuery" class="a-search-hint">
			<AppIcon name="hash" :size="15" />
			<button type="button" @click="openTag(suggestedTag)">#{{ suggestedTag }} als Hashtag öffnen</button>
		</p>

		<template v-if="cleanedQuery !== ''">
			<header class="a-section-head">
				<h2>Suchergebnisse</h2>
				<span>{{ results.length }} {{ results.length === 1 ? 'Treffer' : 'Treffer' }}</span>
			</header>

			<div v-if="discover.loading && discover.posts.length === 0">
				<TimelineSkeleton :count="2" />
			</div>

			<ol v-else-if="results.length > 0" class="a-feed__list">
				<li v-for="post in results" :key="post.id">
					<PostCard :post="post" />
				</li>
			</ol>

			<EmptyState
				v-else
				icon="search"
				title="Dazu nichts gefunden"
				text="Versuche es mit einem anderen Wort oder öffne den Hashtag direkt."
			>
				<button v-if="suggestedTag" type="button" class="a-btn a-btn--primary a-btn--small" @click="openTag(suggestedTag)">
					#{{ suggestedTag }} ansehen
				</button>
			</EmptyState>
		</template>

		<template v-else>
			<section class="a-explore-section">
				<header class="a-section-head">
					<h2>In aller Munde</h2>
					<span>aus den zuletzt geladenen Beiträgen</span>
				</header>

				<div v-if="popularTags.length > 0" class="a-tag-cloud">
					<button
						v-for="(tag, index) in popularTags"
						:key="tag.tag"
						type="button"
						class="a-tag-chip"
						:class="`a-tag-chip--${(index % 4) + 1}`"
						@click="openTag(tag.tag)"
					>
						<span class="a-tag-chip__hash">#</span>
						<span>{{ tag.tag }}</span>
						<small>{{ tag.count }}</small>
					</button>
				</div>
				<p v-else-if="!discover.loading" class="a-muted">Noch keine Hashtags in den öffentlichen Beiträgen.</p>
			</section>

			<section v-if="signedIn" class="a-explore-section">
				<header class="a-section-head">
					<h2>Deine Hashtags</h2>
					<span>{{ followedTags.length }} verfolgt</span>
				</header>

				<div v-if="followedTags.length > 0" class="a-chip-row">
					<span v-for="entry in followedTags" :key="entry.tag" class="a-followed-tag">
						<a :href="`#/tag/${encodeURIComponent(entry.tag)}`">#{{ entry.tag }}</a>
						<button
							type="button"
							:disabled="tagBusy === entry.tag"
							aria-label="Hashtag nicht mehr verfolgen"
							@click="toggleTag(entry.tag)"
						>
							<AppIcon name="x" :size="13" />
						</button>
					</span>
				</div>
				<p v-else class="a-muted">Folge Hashtags auf ihrer Seite, um sie hier zu versammeln.</p>
			</section>

			<section class="a-explore-section">
				<header class="a-section-head">
					<h2>Menschen</h2>
					<span>von dieser Instanz</span>
				</header>

				<ul v-if="discoveryPeople.length > 0" class="a-people a-people--wide">
					<li v-for="person in discoveryPeople.slice(0, 6)" :key="person.id" class="a-person">
						<a :href="`#/u/${encodeURIComponent(person.id)}`" class="a-person__link">
							<Avatar :id="person.id" :name="displayNameFor(person.id)" :size="42" />
							<span class="a-person__copy">
								<strong>{{ displayNameFor(person.id) }}</strong>
								<small v-if="hasDistinctDisplayName(person.id)">{{ handleLabel(person.id) }}</small>
							</span>
						</a>
						<FollowButton :account="accountFor(person.id)" compact />
					</li>
				</ul>
				<p v-else-if="!discover.loading" class="a-muted">Sobald andere schreiben, lernst du sie hier kennen.</p>
			</section>

			<section class="a-explore-section">
				<header class="a-section-head">
					<h2>Frisch aus dem Netz</h2>
					<span>neueste öffentliche Beiträge</span>
				</header>

				<TimelineSkeleton v-if="discover.loading && recent.length === 0" :count="2" />
				<ol v-else-if="recent.length > 0" class="a-feed__list">
					<li v-for="post in recent" :key="post.id">
						<PostCard :post="post" />
					</li>
				</ol>
				<EmptyState
					v-else
					icon="globe"
					title="Noch ist es still"
					text="Die öffentliche Timeline füllt sich, sobald jemand den ersten Beitrag schreibt."
				/>
				<p v-if="discover.error !== ''" class="a-feed__inline-error">{{ discover.error }}</p>
			</section>
		</template>
	</div>
</template>
