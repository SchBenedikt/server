<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AccountRow from '../components/AccountRow.vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { getApi } from '../lib/api';
import { onPostDeleted } from '../lib/events';
import { route } from '../lib/router';
import { appState, showToast } from '../lib/state';
import type { Account, AccountList, TimelineItem } from '../lib/types';

const listId = computed(() => route.value.name === 'list' ? route.value.id : '');
const signedIn = computed(() => appState.session.user !== null);

const list = ref<AccountList | null>(null);
const loading = ref(false);
const error = ref('');
const notFound = ref(false);

const posts = ref<TimelineItem[]>([]);
const postsLoading = ref(false);
const postsError = ref('');
const cursor = ref<string | null>(null);
const hasMore = ref(true);

const members = ref<Account[]>([]);
const membersLoading = ref(false);
const membersError = ref('');
const memberQuery = ref('');
const memberResults = ref<Account[]>([]);
const memberSearching = ref(false);
const memberBusy = ref('');
const showMembers = ref(false);

const memberIds = computed(() => new Set(members.value.map((account) => account.id)));
const addable = computed(() => memberResults.value.filter((account) => !memberIds.value.has(account.id)));

onMounted(() => {
	onPostDeleted((id) => {
		posts.value = posts.value.filter((post) => post.id !== id);
	});
	void load();
});

watch(listId, () => {
	void load();
});

async function load(): Promise<void> {
	if (listId.value === '') {
		notFound.value = true;
		return;
	}

	loading.value = true;
	error.value = '';
	notFound.value = false;
	list.value = null;
	posts.value = [];
	members.value = [];
	showMembers.value = false;
	cursor.value = null;
	hasMore.value = true;

	try {
		const all = await getApi().lists();
		const found = all.find((entry) => entry.id === listId.value) ?? null;
		if (found === null) {
			notFound.value = true;
			return;
		}

		list.value = found;
		await Promise.all([loadPosts(true), loadMembers()]);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The list could not be loaded.';
	} finally {
		loading.value = false;
	}
}

async function loadPosts(reset: boolean): Promise<void> {
	if (listId.value === '' || (!reset && (postsLoading.value || !hasMore.value))) {
		return;
	}

	postsLoading.value = true;
	postsError.value = '';
	if (reset) {
		posts.value = [];
		cursor.value = null;
		hasMore.value = true;
	}

	try {
		const page = await getApi().listTimeline(listId.value, 20, reset ? null : cursor.value);
		const seen = new Set(posts.value.map((post) => post.id));
		const collected = [...posts.value];
		for (const post of page) {
			if (!seen.has(post.id)) {
				seen.add(post.id);
				collected.push(post);
			}
		}

		posts.value = collected;
		cursor.value = page.length > 0 ? page[page.length - 1].id : cursor.value;
		hasMore.value = page.length === 20;
	} catch (caught) {
		postsError.value = caught instanceof Error ? caught.message : 'The timeline could not be loaded.';
	} finally {
		postsLoading.value = false;
	}
}

async function loadMembers(): Promise<void> {
	membersLoading.value = true;
	membersError.value = '';
	try {
		members.value = await getApi().listAccounts(listId.value);
	} catch (caught) {
		membersError.value = caught instanceof Error ? caught.message : 'Members could not be loaded.';
	} finally {
		membersLoading.value = false;
	}
}

async function searchMembers(): Promise<void> {
	const query = memberQuery.value.trim();
	if (query === '') {
		memberResults.value = [];
		return;
	}

	memberSearching.value = true;
	try {
		const results = await getApi().search(query, 20);
		memberResults.value = results.accounts.filter((account) => account.is_remote !== true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The search failed.', 'error');
	} finally {
		memberSearching.value = false;
	}
}

async function addMember(account: Account): Promise<void> {
	if (memberBusy.value !== '') {
		return;
	}

	memberBusy.value = account.id;
	try {
		await getApi().addListAccounts(listId.value, [account.id]);
		members.value = [...members.value, account];
		memberResults.value = memberResults.value.filter((entry) => entry.id !== account.id);
		showToast('Account added to the list.', 'success');
		void loadPosts(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The account could not be added.', 'error');
	} finally {
		memberBusy.value = '';
	}
}

async function removeMember(account: Account): Promise<void> {
	if (memberBusy.value !== '') {
		return;
	}

	memberBusy.value = account.id;
	try {
		await getApi().removeListAccounts(listId.value, [account.id]);
		members.value = members.value.filter((entry) => entry.id !== account.id);
		showToast('Account removed from the list.', 'success');
		void loadPosts(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The account could not be removed.', 'error');
	} finally {
		memberBusy.value = '';
	}
}
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="list?.title ?? 'list'"
			:subtitle="list ? 'Private timeline for this list.' : 'Loading list…'"
			back-href="#/lists"
			back-label="Back to lists"
		>
			<template #actions>
				<button
					v-if="list"
					type="button"
					class="a-btn a-btn--ghost a-btn--small"
					@click="showMembers = !showMembers"
				>
					<AppIcon name="users" :size="15" />
					{{ showMembers ? 'timeline' : `Members (${members.length})` }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="lock" :size="26" /></span>
			<h2>Private list</h2>
			<p>Sign in to see this list and its timeline.</p>
			<a class="a-btn a-btn--primary" href="/login">Sign in</a>
		</div>

		<TimelineSkeleton v-else-if="loading && list === null && !notFound" :count="3" />

		<div v-else-if="notFound" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>List not found</strong><p>This list does not belong to your account or has been deleted.</p></div>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/lists">Zu den lists</a>
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>List unavailable</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Try again</button>
		</div>

		<template v-else-if="list">
			<section v-if="showMembers" class="a-management-panel">
				<form class="a-search" role="search" @submit.prevent="searchMembers">
					<AppIcon name="search" :size="18" />
					<input
						v-model="memberQuery"
						type="search"
						placeholder="Search for and add a local account…"
						aria-label="Search accounts"
						autocomplete="off"
					>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="memberSearching || memberQuery.trim() === ''">
						{{ memberSearching ? 'Searching…' : 'Search' }}
					</button>
				</form>

				<div v-if="addable.length > 0" class="a-management-results">
					<p class="a-management-label">Found accounts</p>
					<AccountRow v-for="account in addable" :key="account.id" :account="account" compact>
						<template #actions>
							<button
								type="button"
								class="a-btn a-btn--ghost a-btn--small"
								:disabled="memberBusy === account.id"
								@click="addMember(account)"
							>
								<AppIcon name="plus" :size="14" /> Add
							</button>
						</template>
					</AccountRow>
				</div>

				<p v-if="membersError !== ''" class="a-form-error" role="alert">{{ membersError }}</p>

				<p class="a-management-label">Members</p>
				<div v-if="membersLoading && members.length === 0" class="a-management-skeleton" aria-hidden="true">
					<div v-for="index in 2" :key="index" class="a-skeleton a-management-skeleton__row" />
				</div>
				<EmptyState
					v-else-if="members.length === 0"
					icon="users"
					title="No members yet"
					text="Search for a local account above and add it to this list."
				/>
				<ul v-else class="a-management-people">
					<li v-for="account in members" :key="account.id">
						<AccountRow :account="account">
							<template #actions>
								<button
									type="button"
									class="a-btn a-btn--ghost a-btn--small"
									:disabled="memberBusy === account.id"
									@click="removeMember(account)"
								>
									<AppIcon name="x" :size="14" /> Remove
								</button>
							</template>
						</AccountRow>
					</li>
				</ul>
			</section>

			<section v-else>
				<TimelineSkeleton v-if="postsLoading && posts.length === 0" :count="3" />

				<div v-else-if="postsError !== '' && posts.length === 0" class="a-error-panel">
					<AppIcon name="info" :size="22" />
					<div><strong>Timeline unavailable</strong><p>{{ postsError }}</p></div>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="loadPosts(true)">Try again</button>
				</div>

				<EmptyState
					v-else-if="posts.length === 0"
					icon="list"
					title="No posts yet"
					text="As soon as members of this list publish something, it appears here."
				>
					<button type="button" class="a-btn a-btn--primary a-btn--small" @click="showMembers = true">
						Manage members
					</button>
				</EmptyState>

				<div v-else class="a-feed">
					<div class="a-feed__list">
						<PostCard v-for="post in posts" :key="post.id" :post="post" />
					</div>

					<button
						v-if="hasMore"
						type="button"
						class="a-btn a-btn--ghost a-feed__more"
						:disabled="postsLoading"
						@click="loadPosts(false)"
					>
						<AppIcon v-if="postsLoading" name="refresh" :size="15" class="a-spin" />
						{{ postsLoading ? 'Loading…' : 'More posts' }}
					</button>
				</div>
			</section>
		</template>
	</div>
</template>
