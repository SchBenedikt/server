<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import FollowButton from '../components/FollowButton.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { hostDisplayName, setAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { onPostCreated, onPostDeleted } from '../lib/events';
import { accountName, avatarStyle, formatCount, handleLabel, isRemoteId } from '../lib/format';
import { route } from '../lib/router';
import { appState, openComposer, showToast } from '../lib/state';
import type { Account, TimelineItem } from '../lib/types';

const PAGE_SIZE = 40;

const handle = computed(() => route.value.name === 'profile' ? route.value.handle : '');
const isRemote = computed(() => isRemoteId(handle.value));
const isSelf = computed(() => appState.session.user?.handle === handle.value);
const signedIn = computed(() => appState.session.user !== null);

const account = ref<Account | null>(null);
const loading = ref(false);
const error = ref('');
const tab = ref<'posts' | 'media'>('posts');

const posts = ref<TimelineItem[]>([]);
const postsLoading = ref(false);
const postsError = ref('');
const cursor = ref<string | null>(null);
const hasMore = ref(true);
const loadedOnce = ref(false);

const displayName = computed(() => {
	if (isSelf.value) {
		return appState.session.user?.displayName
			|| appState.session.profile?.display_name
			|| handleLabel(handle.value);
	}

	return hostDisplayName(handle.value) || accountName(account.value, handle.value);
});
const handleText = computed(() => handleLabel(handle.value));
const showHandle = computed(() => displayName.value !== handleText.value);
const coverStyle = computed(() => avatarStyle(handle.value || 'aloha'));
const profileUrl = computed(() => `${window.location.origin}/app#/u/${encodeURIComponent(handle.value)}`);
const relationship = computed(() => account.value?.relationship ?? null);
const visiblePosts = computed(() => tab.value === 'media'
	? posts.value.filter((post) => (post.attachments?.length ?? 0) > 0)
	: posts.value);

let generation = 0;

watch(handle, () => {
	void loadProfile();
}, { immediate: true });

async function loadProfile(): Promise<void> {
	const current = ++generation;
	account.value = null;
	posts.value = [];
	error.value = '';
	postsError.value = '';
	cursor.value = null;
	hasMore.value = true;
	loadedOnce.value = false;

	if (handle.value === '' || isRemote.value) {
		return;
	}

	loading.value = true;
	try {
		const loaded = await getApi().account(handle.value);
		if (current !== generation) {
			return;
		}
		account.value = loaded;
		setAccount(loaded);
	} catch (caught) {
		if (current === generation) {
			error.value = caught instanceof Error ? caught.message : 'Das Profil konnte nicht geladen werden.';
		}
	} finally {
		if (current === generation) {
			loading.value = false;
		}
	}

	if (current === generation && error.value === '') {
		await loadPosts(true);
	}
}

async function loadPosts(reset: boolean): Promise<void> {
	if (handle.value === '' || isRemote.value) {
		return;
	}
	if (!reset && (postsLoading.value || !hasMore.value)) {
		return;
	}

	const current = generation;
	postsLoading.value = true;
	postsError.value = '';

	if (reset) {
		posts.value = [];
		cursor.value = null;
		hasMore.value = true;
	}

	try {
		const pages = reset ? 3 : 1;
		let collected = reset ? [] : [...posts.value];
		const seen = new Set(collected.map((post) => post.id));
		let nextCursor = reset ? null : cursor.value;

		for (let page = 0; page < pages; page += 1) {
			const batch = await getApi().publicTimeline(PAGE_SIZE, nextCursor);
			if (current !== generation) {
				return;
			}

			for (const post of batch) {
				const author = post.object_author_id ?? post.author_id;
				if (author === handle.value && !seen.has(post.id)) {
					seen.add(post.id);
					collected.push(post);
				}
			}

			nextCursor = batch.length > 0 ? batch[batch.length - 1].id : nextCursor;
			if (batch.length < PAGE_SIZE) {
				hasMore.value = false;
				break;
			}
		}

		cursor.value = nextCursor;

		if (reset && isSelf.value && signedIn.value) {
			try {
				const home = await getApi().homeTimeline(40, null);
				for (const post of home) {
					const author = post.object_author_id ?? post.author_id;
					if (author === handle.value && !seen.has(post.id)) {
						seen.add(post.id);
						collected.push(post);
					}
				}
			} catch {
				// The public scan above already provides the profile's public posts.
			}
		}

		posts.value = collected.sort((left, right) => (
			new Date(right.published_at).getTime() - new Date(left.published_at).getTime()
		));
		loadedOnce.value = true;
	} catch (caught) {
		postsError.value = caught instanceof Error ? caught.message : 'Die Beiträge konnten nicht geladen werden.';
	} finally {
		postsLoading.value = false;
	}
}

async function share(): Promise<void> {
	try {
		await navigator.clipboard.writeText(profileUrl.value);
		showToast('Profil-Link kopiert.', 'success');
	} catch {
		window.prompt('Profil-Link', profileUrl.value);
	}
}

function compose(): void {
	openComposer();
}

function onFollowChanged(following: boolean): void {
	if (account.value !== null) {
		account.value.followers_count = Math.max(0, account.value.followers_count + (following ? 1 : -1));
	}
}

const stopCreated = onPostCreated((post) => {
	const author = post.object_author_id ?? post.author_id;
	if (author !== handle.value || posts.value.some((existing) => existing.id === post.id)) {
		return;
	}

	posts.value = [post, ...posts.value].sort((left, right) => (
		new Date(right.published_at).getTime() - new Date(left.published_at).getTime()
	));
});

const stopDeleted = onPostDeleted((postId) => {
	posts.value = posts.value.filter((post) => post.id !== postId && post.object_id !== postId);
});

onUnmounted(() => {
	generation += 1;
	stopCreated();
	stopDeleted();
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Profil"
			:subtitle="handleText"
			back-href="#/"
			back-label="Zurück zur Startseite"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" aria-label="Profil-Link teilen" title="Profil teilen" @click="share">
					<AppIcon name="share" :size="19" />
				</button>
			</template>
		</PageHeader>

		<div v-if="isRemote" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="globe" :size="26" /></span>
			<h2>Profil auf einem anderen Server</h2>
			<p>Dieses Profil gehört nicht zu dieser Instanz. Du kannst es an seinem Ursprungsort öffnen.</p>
			<a class="a-btn a-btn--primary" :href="handle" target="_blank" rel="noopener noreferrer">
				Profil öffnen <AppIcon name="external" :size="15" />
			</a>
		</div>

		<TimelineSkeleton v-else-if="loading" :count="2" />

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>Profil nicht gefunden</strong><p>{{ error }}</p></div>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/explore">Zurück zu Entdecken</a>
		</div>

		<template v-else-if="account">
			<section class="a-profile">
				<div class="a-profile__cover" :style="coverStyle" aria-hidden="true">
					<span class="a-profile__cover-grid" />
				</div>
				<div class="a-profile__body">
					<div class="a-profile__top">
						<Avatar :id="handle" :name="displayName" :size="94" ring class="a-profile__avatar" />
						<div class="a-profile__actions">
							<button v-if="isSelf" type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
								<AppIcon name="pen" :size="15" /> Beitrag schreiben
							</button>
							<FollowButton v-else :account="account" @changed="onFollowChanged" />
						</div>
					</div>

					<div class="a-profile__identity">
						<h1>{{ displayName }}</h1>
						<p v-if="showHandle" class="a-profile__handle">{{ handleText }}</p>
						<p v-if="relationship?.followed_by" class="a-profile__follows-you">
							<AppIcon name="users" :size="14" /> Folgt dir
						</p>
					</div>

					<p class="a-profile__note">
						<AppIcon name="globe" :size="15" />
						Öffentliches Profil auf {{ appState.instance?.name ?? 'Aloha Social' }}
					</p>

					<dl class="a-profile__stats">
						<div>
							<dt>{{ formatCount(account.following_count) }}</dt>
							<dd>folgt</dd>
						</div>
						<div>
							<dt>{{ formatCount(account.followers_count) }}</dt>
							<dd>Follower</dd>
						</div>
						<div>
							<dt>{{ posts.length }}+</dt>
							<dd>Beiträge geladen</dd>
						</div>
					</dl>
				</div>
			</section>

			<div class="a-tabs a-profile__tabs" role="tablist" aria-label="Profilansicht">
				<button
					type="button"
					class="a-tabs__item"
					:class="{ 'a-tabs__item--active': tab === 'posts' }"
					role="tab"
					:aria-selected="tab === 'posts'"
					@click="tab = 'posts'"
				>
					Beiträge
				</button>
				<button
					type="button"
					class="a-tabs__item"
					:class="{ 'a-tabs__item--active': tab === 'media' }"
					role="tab"
					:aria-selected="tab === 'media'"
					@click="tab = 'media'"
				>
					Medien
				</button>
			</div>

			<TimelineSkeleton v-if="postsLoading && posts.length === 0" :count="2" />

			<div v-else-if="postsError !== '' && posts.length === 0" class="a-error-panel">
				<AppIcon name="info" :size="22" />
				<div><strong>Beiträge nicht erreichbar</strong><p>{{ postsError }}</p></div>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="loadPosts(true)">Erneut versuchen</button>
			</div>

			<EmptyState
				v-else-if="loadedOnce && visiblePosts.length === 0"
				:icon="tab === 'media' ? 'image' : 'pen'"
				:title="tab === 'media' ? 'Noch keine Bilder' : 'Noch keine öffentlichen Beiträge'"
				:text="isSelf
					? 'Schreib den ersten Beitrag – er erscheint dann hier.'
					: 'Diese Person hat in den zuletzt geladenen öffentlichen Beiträgen noch nichts geschrieben.'"
			>
				<button v-if="isSelf" type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
					Beitrag schreiben
				</button>
			</EmptyState>

			<ol v-else-if="visiblePosts.length > 0" class="a-feed__list">
				<li v-for="post in visiblePosts" :key="post.id">
					<PostCard :post="post" />
				</li>
			</ol>

			<button
				v-if="hasMore && visiblePosts.length > 0"
				type="button"
				class="a-btn a-btn--ghost a-feed__more"
				:disabled="postsLoading"
				@click="loadPosts(false)"
			>
				{{ postsLoading ? 'Wird gesucht…' : 'Weitere Beiträge suchen' }}
			</button>

			<p v-if="postsError !== '' && posts.length > 0" class="a-feed__inline-error">{{ postsError }}</p>
		</template>
	</div>
</template>
