<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import TimelineFeed from '../components/TimelineFeed.vue';
import { getApi } from '../lib/api';
import { route } from '../lib/router';
import { appState, showToast } from '../lib/state';

const tag = computed(() => route.value.name === 'tag' ? route.value.tag : '');
const signedIn = computed(() => appState.session.user !== null);
const following = ref(false);
const busy = ref(false);
const checking = ref(false);

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

watch([tag, signedIn], () => {
	void checkFollowing();
}, { immediate: true });

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
	</div>
</template>
