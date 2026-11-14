<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { displayNameFor, hasDistinctDisplayName, resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { onPostCreated, onPostDeleted } from '../lib/events';
import { handleLabel, truncate } from '../lib/format';
import { navigate, route } from '../lib/router';
import { appState, openComposer } from '../lib/state';
import type { TimelineItem } from '../lib/types';

const PAGE_SIZE = 40;
const id = computed(() => route.value.name === 'post' ? route.value.id : '');

const post = ref<TimelineItem | null>(null);
const parent = ref<TimelineItem | null>(null);
const replies = ref<TimelineItem[]>([]);
const loading = ref(false);
const repliesLoading = ref(false);
const error = ref('');
let generation = 0;

watch(id, () => {
	void load();
}, { immediate: true });

async function load(): Promise<void> {
	const current = ++generation;
	post.value = null;
	parent.value = null;
	replies.value = [];
	error.value = '';
	loading.value = true;

	if (id.value === '') {
		error.value = 'Dieser Beitrag existiert nicht.';
		loading.value = false;
		return;
	}

	try {
		const loaded = await getApi().post(id.value);
		if (current !== generation) {
			return;
		}
		post.value = loaded;

		if (loaded.in_reply_to_id) {
			try {
				parent.value = await getApi().post(loaded.in_reply_to_id);
				void resolveAccount(parent.value.author_id);
			} catch {
				parent.value = null;
			}
		}

		await loadReplies(current);
	} catch (caught) {
		if (current === generation) {
			error.value = caught instanceof Error ? caught.message : 'Der Beitrag konnte nicht geladen werden.';
		}
	} finally {
		if (current === generation) {
			loading.value = false;
		}
	}
}

async function loadReplies(current: number): Promise<void> {
	repliesLoading.value = true;
	const seen = new Set<string>();
	const found: TimelineItem[] = [];
	let cursor: string | null = null;

	try {
		for (let page = 0; page < 3; page += 1) {
			const batch = await getApi().publicTimeline(PAGE_SIZE, cursor);
			if (current !== generation) {
				return;
			}

			for (const entry of batch) {
				const parentId = entry.in_reply_to_id ?? null;
				if (parentId === id.value && !seen.has(entry.id)) {
					seen.add(entry.id);
					found.push(entry);
				}
			}

			cursor = batch.length > 0 ? batch[batch.length - 1].id : cursor;
			if (batch.length < PAGE_SIZE) {
				break;
			}
		}

		if (appState.session.user !== null) {
			try {
				const home = await getApi().homeTimeline(40, null);
				for (const entry of home) {
					if (entry.in_reply_to_id === id.value && !seen.has(entry.id)) {
						seen.add(entry.id);
						found.push(entry);
					}
				}
			} catch {
				// Replies from the home timeline are an extra, not a requirement.
			}
		}

		replies.value = found.sort((left, right) => (
			new Date(left.published_at).getTime() - new Date(right.published_at).getTime()
		));
	} catch {
		// The post itself stays readable even when the reply scan fails.
	} finally {
		repliesLoading.value = false;
	}
}

function reply(): void {
	if (post.value === null) {
		return;
	}
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	openComposer(post.value);
}

function parentLink(): string {
	return parent.value === null ? '' : `#/p/${encodeURIComponent(parent.value.id)}`;
}

const stopCreated = onPostCreated((created) => {
	if (created.in_reply_to_id === id.value && !replies.value.some((entry) => entry.id === created.id)) {
		replies.value = [...replies.value, created];
	}
});

const stopDeleted = onPostDeleted((deletedId) => {
	if (deletedId === id.value) {
		navigate('/');
		return;
	}

	replies.value = replies.value.filter((entry) => entry.id !== deletedId);
	if (parent.value?.id === deletedId) {
		parent.value = null;
	}
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
			title="Beitrag"
			:subtitle="post !== null ? `von ${handleLabel(post.author_id)}` : 'Unterhaltung'"
			back-href="#/"
			back-label="Zurück zur Startseite"
		/>

		<TimelineSkeleton v-if="loading" :count="2" />

		<div v-else-if="error !== '' || post === null" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>Beitrag nicht gefunden</strong><p>{{ error || 'Dieser Beitrag ist nicht mehr verfügbar.' }}</p></div>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/">Zur Startseite</a>
		</div>

		<template v-else>
			<div v-if="parent" class="a-parent">
				<a :href="parentLink()" class="a-parent__link">
					<Avatar :id="parent.author_id" :name="displayNameFor(parent.author_id)" :size="34" />
					<div>
						<p>
							<strong>{{ displayNameFor(parent.author_id) }}</strong>
							<span v-if="hasDistinctDisplayName(parent.author_id)">{{ handleLabel(parent.author_id) }}</span>
						</p>
						<p class="a-parent__text">{{ truncate(parent.content, 180) }}</p>
					</div>
					<AppIcon name="arrowLeft" :size="15" class="a-parent__arrow" />
				</a>
			</div>

			<PostCard :post="post" detail />

			<section class="a-replies">
				<header class="a-section-head">
					<h2>Antworten</h2>
					<span>{{ replies.length }} geladen</span>
				</header>

				<button type="button" class="a-inline-reply" @click="reply">
					<AppIcon name="reply" :size="18" />
					<span>Antwort an {{ handleLabel(post.author_id) }} schreiben …</span>
				</button>

				<TimelineSkeleton v-if="repliesLoading && replies.length === 0" :count="1" />

				<EmptyState
					v-else-if="replies.length === 0"
					icon="message"
					title="Noch keine Antworten"
					text="Beginne die Unterhaltung – deine Antwort erscheint direkt hier."
				/>

				<ol v-else class="a-feed__list">
					<li v-for="entry in replies" :key="entry.id">
						<PostCard :post="entry" />
					</li>
				</ol>
			</section>
		</template>
	</div>
</template>
