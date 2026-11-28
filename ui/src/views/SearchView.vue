<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AccountRow from '../components/AccountRow.vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import FollowButton from '../components/FollowButton.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { ApiError, getApi, loadErrorMessage } from '../lib/api';
import { t } from '../lib/i18n';
import { appState, showToast } from '../lib/state';
import type { Account, HashtagResult, SearchResults, TimelineItem } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const query = ref(appState.searchSeed);
const searching = ref(false);
const searched = ref(false);
const error = ref('');
const results = ref<SearchResults>({ accounts: [], statuses: [], hashtags: [] });
const remote = ref<Account | null>(null);
const remoteTried = ref(false);
const remoteError = ref('');

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
	remoteError.value = '';

	if (signedIn.value) {
		try {
			results.value = await getApi().search(value, 30);
		} catch (caught) {
			results.value = { accounts: [], statuses: [], hashtags: [] };
			error.value = loadErrorMessage(caught, t('search.localFailed'));
		}
	} else {
		results.value = { accounts: [], statuses: [], hashtags: [] };
		if (!looksRemote(value)) {
			error.value = t('search.guestNotice');
		}
	}

	if (!looksRemote(value)) {
		searching.value = false;
		return;
	}

	remoteTried.value = true;
	try {
		const profile = await getApi().remoteProfile(value.replace(/^@/, ''), null);
		remote.value = profile.profile;
	} catch (caught) {
		remote.value = null;
		if (caught instanceof ApiError && (caught.status === 404 || caught.code === 'bluesky_handle_unknown')) {
			remoteError.value = t('search.errNotFound');
		} else if (caught instanceof ApiError && caught.status === 401) {
			remoteError.value = t('search.errSignin');
		} else if (caught instanceof ApiError && caught.status === 403) {
			remoteError.value = t('search.errForbidden');
		} else if (caught instanceof ApiError && caught.status === 429) {
			remoteError.value = t('search.errRateLimit');
		} else if (caught instanceof ApiError && caught.status >= 500) {
			remoteError.value = t('search.errRemoteDown');
		} else if (!(caught instanceof ApiError)) {
			remoteError.value = t('search.errNetwork');
		} else {
			remoteError.value = loadErrorMessage(caught, caught.message);
		}
	} finally {
		searching.value = false;
	}
}

// The shell's search box seeds this view before navigating here. Running the
// search on mount makes that box a real shortcut instead of a dead field.
onMounted(() => {
	if (query.value.trim() !== '') {
		void search();
	}
});

watch(() => appState.searchSeed, (value) => {
	if (value.trim() === '' || value === query.value) {
		return;
	}

	query.value = value;
	void search();
});

function hashtagHref(tag: HashtagResult): string {
	return `#/tag/${encodeURIComponent(tag.name)}`;
}
</script>

<template>
	<div class="a-page">
		<PageHeader :title="t('nav.search')" :subtitle="t('search.subtitle')" />

		<form class="a-search" role="search" @submit.prevent="search">
			<AppIcon name="search" :size="19" />
			<input
				v-model="query"
				type="search"
				:placeholder="t('search.placeholder')"
				:aria-label="t('search.aria')"
				autocomplete="off"
			/>
			<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="searching || query.trim() === ''">
				<AppIcon v-if="searching" name="refresh" :size="15" class="a-spin" />
				{{ searching ? t('common.searching') : t('common.search') }}
			</button>
		</form>

		<p v-if="!signedIn" class="a-search-hint">
			<AppIcon name="lock" :size="14" />
			<span>{{ t('search.signInHint') }}</span>
			<a href="/login">{{ t('common.signIn') }}</a>
		</p>

		<p v-if="error" class="a-form-error" role="alert">
			{{ error }}
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="search">
				{{ t('common.tryAgain') }}
			</button>
		</p>

		<TimelineSkeleton v-if="searching && !hasResults" :count="3" />

		<template v-else-if="searched">
			<EmptyState
				v-if="!hasResults && error === '' && remoteError === ''"
				icon="search"
				:title="t('search.noResults')"
				:text="t('search.noResultsText')"
			/>

			<section v-if="remote" class="a-explore-section">
				<div class="a-section-head">
					<h2>{{ t('search.remoteProfile') }}</h2>
					<span>{{ remote.network === 'bluesky' ? 'Bluesky' : 'ActivityPub' }}</span>
				</div>
				<AccountRow :account="remote" :href="`#/u/${encodeURIComponent(remote.handle || remote.id)}`" />
			</section>
			<p v-if="remoteTried && remoteError" class="a-form-error" role="status">{{ remoteError }}</p>

			<section v-if="results.accounts.length > 0" class="a-explore-section">
				<div class="a-section-head">
					<h2>{{ t('search.onInstance') }}</h2>
					<span>{{ results.accounts.length }}</span>
				</div>
				<div class="a-people">
					<AccountRow v-for="account in results.accounts" :key="account.id" :account="account">
						<template #actions>
							<FollowButton :account="account" compact @changed="showToast($event ? t('search.followed', { handle: account.handle }) : t('search.unfollowed', { handle: account.handle }), 'success')" />
						</template>
					</AccountRow>
				</div>
			</section>

			<section v-if="results.hashtags.length > 0" class="a-explore-section">
				<div class="a-section-head">
					<h2>{{ t('search.hashtags') }}</h2>
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
					<h2>{{ t('search.postsOnInstance') }}</h2>
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
			:title="t('search.emptyTitle')"
			:text="t('search.emptyText')"
		/>
	</div>
</template>
