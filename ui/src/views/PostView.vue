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
import { onPostCreated, onPostDeleted, onPostUpdated } from '../lib/events';
import { handleLabel, isRemoteId, postRouteId, truncate } from '../lib/format';
import { navigate, route } from '../lib/router';
import { appState, openComposer } from '../lib/state';
import type { Account, TimelineItem } from '../lib/types';

const PAGE_SIZE = 40;
const id = computed(() => route.value.name === 'post' ? route.value.id : '');
const isRemoteTarget = computed(() => isRemoteId(id.value) || id.value.startsWith('at://'));

const post = ref<TimelineItem | null>(null);
const parent = ref<TimelineItem | null>(null);
const replies = ref<TimelineItem[]>([]);
const loading = ref(false);
const repliesLoading = ref(false);
const error = ref('');
const remoteNetwork = ref<'activitypub' | 'bluesky' | null>(null);
const remoteProfile = ref<Account | null>(null);
let generation = 0;

const externalUrl = computed(() => {
	const item = post.value;
	if (item === null) {
		return '';
	}

	const candidate = item.external_url ?? item.object_id ?? item.id;
	return isRemoteId(candidate) ? candidate : '';
});
const remoteHostName = computed(() => {
	if (remoteNetwork.value === 'bluesky') {
		return 'bsky.app';
	}

	const url = externalUrl.value !== '' ? externalUrl.value : id.value;
	try {
		return new URL(url).hostname.replace(/^www\./, '');
	} catch {
		return 'Fediverse';
	}
});
const remoteNetworkLabel = computed(() => (
	remoteNetwork.value === 'bluesky' ? 'Bluesky · AT Protocol' : `Fediverse · ${remoteHostName.value}`
));
const replyTotal = computed(() => post.value?.replies_count ?? replies.value.length);
const emptyRepliesText = computed(() => (
	isRemoteTarget.value
		? 'Dieser Beitrag hat noch keine öffentlichen Antworten.'
		: 'Beginne die Unterhaltung – deine Antwort erscheint direkt hier.'
));

watch(id, () => {
	void load();
}, { immediate: true });

async function load(): Promise<void> {
	const current = ++generation;
	post.value = null;
	parent.value = null;
	replies.value = [];
	error.value = '';
	remoteNetwork.value = null;
	remoteProfile.value = null;

	if (id.value === '') {
		error.value = 'Dieser Beitrag existiert nicht.';
		return;
	}

	if (isRemoteTarget.value) {
		await loadRemote(current);
		return;
	}

	loading.value = true;
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

async function loadRemote(current: number): Promise<void> {
	loading.value = true;
	try {
		const data = await getApi().remotePost(id.value);
		if (current !== generation) {
			return;
		}

		post.value = data.post;
		parent.value = data.parent;
		replies.value = data.replies;
		remoteNetwork.value = data.network;
		remoteProfile.value = data.profile;
	} catch (caught) {
		if (current === generation) {
			error.value = caught instanceof Error ? caught.message : 'Der entfernte Beitrag konnte nicht geladen werden.';
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

function itemAuthor(item: TimelineItem): string {
	return item.object_author_id ?? item.author_id;
}

function itemDisplayName(item: TimelineItem): string {
	const remote = item.author_display_name?.trim() ?? '';
	if (remote !== '') {
		return remote;
	}

	const remoteHandle = item.author_handle?.trim() ?? '';
	return remoteHandle !== '' ? remoteHandle : displayNameFor(itemAuthor(item));
}

function itemHandle(item: TimelineItem): string {
	const remote = item.author_handle?.trim() ?? '';
	return remote !== '' ? remote : handleLabel(itemAuthor(item));
}

function itemHref(item: TimelineItem): string | null {
	const local = postRouteId(item);
	const target = local ?? item.object_id ?? item.id;
	if (target === null || target === '') {
		return null;
	}
	if (!isRemoteId(target) && !target.startsWith('at://') && local === null) {
		return null;
	}

	return `#/p/${encodeURIComponent(target)}`;
}

function reply(): void {
	if (post.value === null || isRemoteTarget.value) {
		return;
	}
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	openComposer(post.value);
}

const stopCreated = onPostCreated((created) => {
	if (created.in_reply_to_id === id.value && !replies.value.some((entry) => entry.id === created.id)) {
		replies.value = [...replies.value, created];
	}
});

const stopUpdated = onPostUpdated((updated) => {
	if (post.value?.id === updated.id) {
		post.value = { ...post.value, ...updated, interaction: post.value.interaction ?? updated.interaction };
	}
	if (parent.value?.id === updated.id) {
		parent.value = { ...parent.value, ...updated, interaction: parent.value.interaction ?? updated.interaction };
	}
	replies.value = replies.value.map((entry) => (
		entry.id === updated.id
			? { ...entry, ...updated, interaction: entry.interaction ?? updated.interaction }
			: entry
	));
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
	stopUpdated();
	stopDeleted();
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Beitrag"
			:subtitle="post !== null ? `von ${itemHandle(post)}` : 'Unterhaltung'"
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
				<a :href="itemHref(parent) ?? '#'" class="a-parent__link">
					<Avatar :id="itemAuthor(parent)" :name="itemDisplayName(parent)" :src="parent.author_avatar" :size="34" />
					<div>
						<p>
							<strong>{{ itemDisplayName(parent) }}</strong>
							<span v-if="parent.author_display_name || hasDistinctDisplayName(itemAuthor(parent))">
								{{ itemHandle(parent) }}
							</span>
						</p>
						<p class="a-parent__text">{{ truncate(parent.content, 180) }}</p>
					</div>
					<AppIcon name="arrowLeft" :size="15" class="a-parent__arrow" />
				</a>
			</div>

			<PostCard :post="post" detail />

			<section v-if="isRemoteTarget" class="a-remote-detail">
				<div class="a-remote-detail__identity">
					<p class="a-remote-detail__eyebrow">
						<AppIcon :name="remoteNetwork === 'bluesky' ? 'sparkles' : 'globe'" :size="15" />
						{{ remoteNetworkLabel }}
					</p>
					<p v-if="remoteProfile" class="a-remote-detail__author">
						Profil von <strong>{{ remoteProfile.display_name }}</strong>
						<span>{{ remoteProfile.handle }}</span>
					</p>
					<p class="a-remote-detail__hint">
						Dieser Beitrag wird live vom Ursprungs-Server gelesen. Antworten und Reaktionen bleiben dort.
					</p>
				</div>
				<a
					v-if="externalUrl !== ''"
					class="a-btn a-btn--ghost a-btn--small"
					:href="externalUrl"
					target="_blank"
					rel="noopener noreferrer"
				>
					<AppIcon name="external" :size="15" />
					Original öffnen
				</a>
			</section>

			<section class="a-replies">
				<header class="a-section-head">
					<h2>Antworten</h2>
					<span>{{ replyTotal }} {{ replyTotal === 1 ? 'Antwort' : 'Antworten' }}</span>
				</header>

				<button v-if="!isRemoteTarget" type="button" class="a-inline-reply" @click="reply">
					<AppIcon name="reply" :size="18" />
					<span>Antwort an {{ itemHandle(post) }} schreiben …</span>
				</button>
				<a
					v-else-if="externalUrl !== ''"
					class="a-inline-reply"
					:href="externalUrl"
					target="_blank"
					rel="noopener noreferrer"
				>
					<AppIcon name="reply" :size="18" />
					<span>Auf {{ remoteHostName }} antworten …</span>
				</a>

				<TimelineSkeleton v-if="repliesLoading && replies.length === 0" :count="1" />

				<EmptyState
					v-else-if="replies.length === 0"
					icon="message"
					title="Noch keine Antworten"
					:text="emptyRepliesText"
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
