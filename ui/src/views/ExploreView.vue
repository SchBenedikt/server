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
import type {
	Account,
	FollowedTag,
	RemoteSearchData,
	RemoteSearchNetwork,
	RemoteSearchType,
	TrendsData,
} from '../lib/types';

const query = ref('');
const trends = ref<TrendsData>({ tags: [], links: [] });
const followedTags = ref<FollowedTag[]>([]);
const followedLoading = ref(false);
const tagBusy = ref('');

const remote = ref<RemoteSearchData | null>(null);
const remoteLoading = ref(false);
const remoteError = ref('');
const remoteType = ref<RemoteSearchType>('all');
const remoteNetwork = ref<RemoteSearchNetwork>('all');
const remoteOpen = ref(false);
let remoteGeneration = 0;

const signedIn = computed(() => appState.session.user !== null);
const cleanedQuery = computed(() => query.value.trim());
const lowercaseQuery = computed(() => cleanedQuery.value.toLowerCase());
const isHashtagQuery = computed(() => cleanedQuery.value.startsWith('#') && normalizeTag(cleanedQuery.value) !== '');
const suggestedTag = computed(() => normalizeTag(cleanedQuery.value));
const looksLikeProfile = computed(() => {
	const value = cleanedQuery.value;
	if (value === '' || isHashtagQuery.value) {
		return false;
	}
	if (/^https?:\/\//i.test(value) || /^did:/i.test(value)) {
		return true;
	}

	const address = value.replace(/^@/, '');
	if (/^[^@\s]+@[^@\s]+$/.test(address)) {
		return true;
	}
	if (/^(?:www\.)?bsky\.app\/profile\//i.test(value)) {
		return true;
	}

	return /^[^@\s/]+\.[A-Za-z]{2,}$/.test(address);
});

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
const videoPosts = computed(() => discover.posts
	.filter((post) => post.attachments?.some((attachment) => (
		attachment.media_type === 'video'
		|| attachment.media_type === 'gifv'
		|| attachment.mime_type.startsWith('video/')
	)))
	.slice(0, 6));

watch(discoveryPeople, (people) => {
	for (const person of people) {
		void resolveAccount(person.id);
	}
}, { immediate: true, deep: true });

onMounted(async () => {
	void loadDiscover();
	void loadTrends();
	if (signedIn.value) {
		await loadFollowedTags();
	}
});

async function loadTrends(): Promise<void> {
	try {
		trends.value = await getApi().trends(7, 12);
	} catch {
		trends.value = { tags: [], links: [] };
	}
}

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
		return;
	}

	if (looksLikeProfile.value) {
		openProfile();
		return;
	}

	void searchRemote(true);
}

async function searchRemote(reset: boolean): Promise<void> {
	const term = cleanedQuery.value;
	if (term === '') {
		return;
	}

	if (!reset && (remoteLoading.value || remote.value?.cursor == null)) {
		return;
	}

	const generation = ++remoteGeneration;
	remoteOpen.value = true;
	remoteLoading.value = true;
	if (reset) {
		remoteError.value = '';
		remote.value = null;
	}

	try {
		const data = await getApi().remoteSearch(
			term,
			remoteType.value,
			remoteNetwork.value,
			reset ? null : remote.value?.cursor ?? null,
		);
		if (generation !== remoteGeneration) {
			return;
		}

		if (reset) {
			remote.value = data;
		} else if (remote.value !== null) {
			remote.value = {
				...data,
				accounts: dedupeAccounts([...remote.value.accounts, ...data.accounts]),
				posts: dedupePosts([...remote.value.posts, ...data.posts]),
			};
		}
	} catch (error) {
		if (generation !== remoteGeneration) {
			return;
		}
		remoteError.value = error instanceof Error ? error.message : 'Die Suche ist fehlgeschlagen.';
	} finally {
		if (generation === remoteGeneration) {
			remoteLoading.value = false;
		}
	}
}

function dedupeAccounts(accounts: Account[]): Account[] {
	const seen = new Set<string>();
	return accounts.filter((account) => {
		if (seen.has(account.id)) {
			return false;
		}
		seen.add(account.id);
		return true;
	});
}

function dedupePosts(posts: RemoteSearchData['posts']): RemoteSearchData['posts'] {
	const seen = new Set<string>();
	return posts.filter((post) => {
		if (seen.has(post.id)) {
			return false;
		}
		seen.add(post.id);
		return true;
	});
}

function setRemoteFilters(type: RemoteSearchType, network: RemoteSearchNetwork): void {
	if (remoteType.value === type && remoteNetwork.value === network) {
		return;
	}

	remoteType.value = type;
	remoteNetwork.value = network;
	void searchRemote(true);
}

function openRemoteAccount(account: Account): void {
	const target = account.handle !== '' ? account.handle : account.id;
	navigate(`/u/${encodeURIComponent(target)}`);
}

function remoteAccountSubtitle(account: Account): string {
	const network = account.network === 'bluesky' ? 'Bluesky' : 'Fediverse';
	if (account.followers_count !== null && account.followers_count !== undefined) {
		return `${network} · ${account.followers_count} Follower`;
	}

	return network;
}

function openProfile(): void {
	if (cleanedQuery.value === '') {
		return;
	}

	navigate(`/u/${encodeURIComponent(cleanedQuery.value)}`);
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
		<PageHeader title="Entdecken" subtitle="Suche in den öffentlichen Beiträgen, öffne Profile aus dem Fediverse und Bluesky oder finde Hashtags." />

		<form class="a-search" role="search" @submit.prevent="submitSearch">
			<AppIcon name="search" :size="21" />
			<input
				v-model="query"
				type="search"
				placeholder="Worte, @name@server, bsky.app/profile/…, #Tags …"
				aria-label="Öffentliche Beiträge durchsuchen oder ein Profil öffnen"
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

		<p v-else-if="looksLikeProfile" class="a-search-hint">
			<AppIcon name="globe" :size="15" />
			<button type="button" @click="openProfile">{{ cleanedQuery }} als Profil öffnen</button>
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

			<section class="a-explore-section a-remote-search">
				<header class="a-section-head">
					<h2>
						<AppIcon name="globe" :size="16" />
						Im ganzen Netz
					</h2>
					<button
						v-if="!remoteOpen"
						type="button"
						class="a-btn a-btn--small"
						@click="searchRemote(true)"
					>
						Fediverse &amp; Bluesky durchsuchen
					</button>
					<span v-else>Profile und Beiträge außerhalb dieser Instanz</span>
				</header>

				<div v-if="remoteOpen" class="a-remote-search__filters">
					<div class="a-chip-row" role="group" aria-label="Art der Treffer">
						<button
							v-for="option in ([['all', 'Alles'], ['accounts', 'Profile'], ['posts', 'Beiträge']] as const)"
							:key="option[0]"
							type="button"
							class="a-tag-chip"
							:class="{ 'a-tag-chip--active': remoteType === option[0] }"
							@click="setRemoteFilters(option[0], remoteNetwork)"
						>
							{{ option[1] }}
						</button>
					</div>
					<div class="a-chip-row" role="group" aria-label="Netzwerk">
						<button
							v-for="option in ([['all', 'Alle Netze'], ['activitypub', 'Fediverse'], ['bluesky', 'Bluesky']] as const)"
							:key="option[0]"
							type="button"
							class="a-tag-chip"
							:class="{ 'a-tag-chip--active': remoteNetwork === option[0] }"
							@click="setRemoteFilters(remoteType, option[0])"
						>
							{{ option[1] }}
						</button>
					</div>
				</div>

				<TimelineSkeleton v-if="remoteLoading && remote === null" :count="2" />

				<p v-if="remoteError !== ''" class="a-feed__inline-error">{{ remoteError }}</p>

				<template v-if="remote !== null">
					<ul v-if="remote.accounts.length > 0" class="a-people a-people--wide">
						<li v-for="account in remote.accounts" :key="account.id" class="a-person">
							<a
								:href="`#/u/${encodeURIComponent(account.handle !== '' ? account.handle : account.id)}`"
								class="a-person__link"
								@click.prevent="openRemoteAccount(account)"
							>
								<Avatar :id="account.id" :name="account.display_name" :src="account.avatar" :size="42" />
								<span class="a-person__copy">
									<strong>{{ account.display_name }}</strong>
									<small>{{ account.handle }}</small>
									<small class="a-person__network">{{ remoteAccountSubtitle(account) }}</small>
								</span>
							</a>
						</li>
					</ul>

					<ol v-if="remote.posts.length > 0" class="a-feed__list">
						<li v-for="post in remote.posts" :key="post.id">
							<PostCard :post="post" />
						</li>
					</ol>

					<EmptyState
						v-if="!remoteLoading && remote.accounts.length === 0 && remote.posts.length === 0"
						icon="search"
						title="Im Netz nichts gefunden"
						:text="remote.notes.length > 0 ? remote.notes.join(' ') : 'Versuche ein anderes Wort oder eine vollständige Adresse wie @name@server.'"
					/>

					<p v-for="note in remote.notes" :key="note" class="a-remote-search__note">
						<AppIcon name="info" :size="13" />
						{{ note }}
					</p>

					<div class="a-feed__more">
						<button
							v-if="remote.cursor !== null"
							type="button"
							class="a-btn a-btn--small"
							:disabled="remoteLoading"
							@click="searchRemote(false)"
						>
							{{ remoteLoading ? 'Lädt …' : 'Mehr Treffer laden' }}
						</button>
						<span v-else-if="remoteLoading">Suche läuft …</span>
					</div>
				</template>
			</section>
		</template>

		<template v-else>
			<section v-if="trends.tags.length > 0 || trends.links.length > 0" class="a-explore-section">
				<header class="a-section-head">
					<h2>
						<AppIcon name="chart" :size="16" />
						Trends dieser Woche
					</h2>
					<span>aus öffentlichen Beiträgen dieser Instanz</span>
				</header>

				<div v-if="trends.tags.length > 0" class="a-tag-cloud">
					<button
						v-for="(trend, index) in trends.tags"
						:key="trend.tag"
						type="button"
						class="a-tag-chip"
						:class="`a-tag-chip--${(index % 4) + 1}`"
						@click="openTag(trend.tag)"
					>
						<span class="a-tag-chip__hash">#</span>
						<span>{{ trend.tag }}</span>
						<small>{{ trend.posts }}</small>
					</button>
				</div>

				<ul v-if="trends.links.length > 0" class="a-trend-links">
					<li v-for="link in trends.links.slice(0, 6)" :key="link.url">
						<a :href="link.url" target="_blank" rel="noopener noreferrer nofollow">
							<AppIcon name="external" :size="14" />
							<span>{{ link.url }}</span>
							<small>{{ link.host }} · {{ link.posts }}×</small>
						</a>
					</li>
				</ul>
			</section>

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

			<section v-if="videoPosts.length > 0" class="a-explore-section">
				<header class="a-section-head">
					<h2>
						<AppIcon name="play" :size="16" />
						Videos
					</h2>
					<span>Bewegtbild aus den neuesten Beiträgen</span>
				</header>

				<ol class="a-feed__list">
					<li v-for="post in videoPosts" :key="post.id">
						<PostCard :post="post" />
					</li>
				</ol>
			</section>
		</template>
	</div>
</template>
