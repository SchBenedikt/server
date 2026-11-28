<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineFeed from '../components/TimelineFeed.vue';
import { getApi } from '../lib/api';
import { route } from '../lib/router';
import { appState, showToast } from '../lib/state';
import type { TimelineItem } from '../lib/types';

const tag = computed(() => route.value.name === 'tag' ? route.value.tag : '');
const signedIn = computed(() => appState.session.user !== null);
const following = ref(false);
const busy = ref(false);
const checking = ref(false);

const remotePosts = ref<TimelineItem[]>([]);
const remoteCursor = ref<{ before: string; beforeId: string } | null>(null);
const remoteHasMore = ref(false);
const remoteLoading = ref(false);
const remoteLoaded = ref(false);
const remoteError = ref('');
let remoteGeneration = 0;

async function checkFollowing(): Promise<void> {
	if (!signedIn.value || tag.value === '') {
		following.value = false;
		return;
	}

	checking.value = true;
	try {
		const tags = await getApi().followedTags();
		following.value = tags.some((entry) => entry.tag === tag.value.toLowerCase());
	} catch {
		following.value = false;
	} finally {
		checking.value = false;
	}
}

/**
 * The local tag timeline comes from the core and only knows posts stored
 * here. A federated hashtag page also shows what other servers delivered, so
 * the remote section reads the cached federated timeline with the same tag
 * filter instead of waiting for a followed account.
 */
async function loadRemote(more = false): Promise<void> {
	if (tag.value === '' || remoteLoading.value || (more && remoteCursor.value === null)) {
		return;
	}

	const current = ++remoteGeneration;
	remoteLoading.value = true;
	remoteError.value = '';

	try {
		const page = await getApi().federatedTimelinePage(
			20,
			more ? remoteCursor.value?.before ?? null : null,
			more ? remoteCursor.value?.beforeId ?? null : null,
			tag.value,
		);
		if (current !== remoteGeneration) {
			return;
		}

		const seen = new Set(more ? remotePosts.value.map((post) => post.id) : []);
		const collected = more ? [...remotePosts.value] : [];
		for (const post of page.posts) {
			if (!seen.has(post.id)) {
				seen.add(post.id);
				collected.push(post);
			}
		}

		remotePosts.value = collected;
		remoteCursor.value = page.nextCursor;
		remoteHasMore.value = page.hasMore;
		remoteLoaded.value = true;
	} catch (error) {
		if (current === remoteGeneration) {
			remoteError.value = error instanceof Error ? error.message : 'Federated posts could not be loaded.';
		}
	} finally {
		if (current === remoteGeneration) {
			remoteLoading.value = false;
		}
	}
}

watch(tag, () => {
	remoteGeneration += 1;
	remotePosts.value = [];
	remoteCursor.value = null;
	remoteHasMore.value = false;
	remoteLoaded.value = false;
	remoteError.value = '';
	void checkFollowing();
	void loadRemote(false);
}, { immediate: true });

watch(signedIn, () => {
	void checkFollowing();
});

async function toggleFollow(): Promise<void> {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}
	if (tag.value === '' || busy.value) {
		return;
	}

	busy.value = true;
	try {
		if (following.value) {
			await getApi().unfollowTag(tag.value);
			following.value = false;
			showToast(`#${tag.value} is no longer followed.`, 'success');
		} else {
			await getApi().followTag(tag.value);
			following.value = true;
			showToast(`You now follow #${tag.value}.`, 'success');
		}
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The hashtag could not be changed.', 'error');
	} finally {
		busy.value = false;
	}
}
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="`#${tag}`"
			subtitle="Public posts with this hashtag, newest first."
			back-href="#/explore"
			back-label="Back to Explore"
		>
			<template #actions>
				<button
					v-if="signedIn"
					type="button"
					class="a-btn a-btn--small"
					:class="following ? 'a-btn--ghost' : 'a-btn--primary'"
					:disabled="busy || checking"
					:aria-pressed="following"
					@click="toggleFollow"
				>
					<AppIcon :name="following ? 'check' : 'plus'" :size="15" />
					{{ following ? 'Following' : 'Follow hashtag' }}
				</button>
				<a v-else class="a-btn a-btn--primary a-btn--small" href="/login">Sign in to follow</a>
			</template>
		</PageHeader>

		<TimelineFeed
			kind="tag"
			:tag="tag"
			empty-icon="hash"
			:empty-title="`Nothing under #${tag} yet`"
			:empty-text="tag !== '' ? `Be the first person to use #${tag}.` : 'This hashtag is empty.'"
		/>

		<section
			v-if="remoteLoaded || remoteLoading || remoteError !== ''"
			class="a-explore-section a-federated-tag"
		>
			<header class="a-section-head">
				<h2>
					<AppIcon name="globe" :size="16" />
					From the fediverse
				</h2>
				<span>Public posts other servers delivered to this instance</span>
			</header>

			<p v-if="remoteError !== ''" class="a-feed__inline-error">
				{{ remoteError }}
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="loadRemote()">
					Try again
				</button>
			</p>

			<ol v-if="remotePosts.length > 0" class="a-feed__list">
				<li v-for="post in remotePosts" :key="post.id">
					<PostCard :post="post" />
				</li>
			</ol>

			<p v-else-if="remoteLoaded && !remoteLoading && remoteError === ''" class="a-muted">
				No federated posts under #{{ tag }} have reached this instance yet.
			</p>

			<button
				v-if="remoteHasMore"
				type="button"
				class="a-btn a-btn--ghost a-feed__more"
				:disabled="remoteLoading"
				@click="loadRemote(true)"
			>
				<AppIcon v-if="remoteLoading" name="refresh" :size="16" class="a-spin" />
				{{ remoteLoading ? 'Loading…' : 'More federated posts' }}
			</button>
			<p v-else-if="remoteLoading" class="a-muted">Loading federated posts…</p>
		</section>
	</div>
</template>
