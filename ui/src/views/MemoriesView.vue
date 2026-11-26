<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import { getApi } from '../lib/api';
import { navigate } from '../lib/router';
import { appState } from '../lib/state';
import type { MemoriesData, TimelineItem } from '../lib/types';

const data = ref<MemoriesData | null>(null);
const posts = ref<TimelineItem[]>([]);
const loading = ref(false);
const error = ref('');

const signedIn = appState.session.user !== null;

function dateLabel(): string {
	if (data.value === null || data.value.date === '') {
		return 'Today';
	}

	const [month, day] = data.value.date.split('-');
	const names = [
		'January', 'February', 'March', 'April', 'May', 'June',
		'July', 'August', 'September', 'October', 'November', 'December',
	];
	return `${names[Number(month) - 1] ?? ''} ${Number(day)}`;
}

async function load(): Promise<void> {
	if (!signedIn) {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		data.value = await getApi().memories();
		const ids = data.value.years.flatMap((year) => year.posts.map((post) => post.id));
		posts.value = ids.length === 0 ? [] : Object.values(await getApi().posts(ids));
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Memories could not be loaded.';
	} finally {
		loading.value = false;
	}
}

onMounted(load);
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Memories"
			:subtitle="`What you shared on this day in earlier years — ${dateLabel()}.`"
			back-href="#/"
			back-label="Back to home"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" aria-label="Reload memories" title="Refresh" @click="load">
					<AppIcon name="refresh" :size="19" />
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>Sign-in required</strong>
				<p>Memories are private and visible only to you.</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">Sign in</a>
		</div>

		<p v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Try again</button>
		</p>

		<div v-else-if="loading && data === null" class="a-muted">Loading memories…</div>

		<template v-else-if="data !== null">
			<section v-if="data.total === 0" class="a-explore-section">
				<EmptyState
					icon="clock"
					title="No memories yet"
					text="Once you have posted on a day in an earlier year, the post reappears here."
				/>
			</section>

			<section v-for="year in data.years" v-else :key="year.year" class="a-memories-year">
				<header class="a-section-head">
					<h2>{{ year.year }}</h2>
					<span>{{ year.posts.length }} {{ year.posts.length === 1 ? 'Post' : 'Posts' }}</span>
				</header>
				<ol class="a-feed__list">
					<li v-for="post in posts.filter((item) => year.posts.some((entry) => entry.id === item.id))" :key="post.id">
						<PostCard :post="post" />
					</li>
				</ol>
			</section>

			<div class="a-feed__more">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="navigate(`/u/${encodeURIComponent(appState.session.user?.handle ?? '')}`)">
					View all your posts
				</button>
			</div>
		</template>
	</div>
</template>
