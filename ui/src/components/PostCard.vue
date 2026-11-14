<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import MediaGrid from './MediaGrid.vue';
import RichText from './RichText.vue';
import { accountName, handleLabel, isRemoteId, postRouteId, relativeTime, visibilityLabel } from '../lib/format';
import { hostDisplayName, resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { announcePostDeleted } from '../lib/events';
import { appState, openComposer, showToast } from '../lib/state';
import type { TimelineItem } from '../lib/types';

const props = withDefaults(defineProps<{
	post: TimelineItem;
	detail?: boolean;
}>(), {
	detail: false,
});

const authorId = computed(() => props.post.object_author_id ?? props.post.author_id);
const author = computed(() => {
	const cached = appState.session.profile;
	return cached?.id === authorId.value ? cached : null;
});
const localAuthor = ref<Awaited<ReturnType<typeof resolveAccount>>>(null);

watch(authorId, async (id) => {
	localAuthor.value = await resolveAccount(id);
}, { immediate: true });

const resolvedAuthor = computed(() => {
	if (author.value !== null && author.value !== undefined) {
		return author.value;
	}

	return localAuthor.value;
});

const displayName = computed(() => {
	const session = appState.session;
	if (session.user?.handle === authorId.value) {
		return session.user.displayName || session.profile?.display_name || handleLabel(authorId.value);
	}

	return hostDisplayName(authorId.value) || accountName(resolvedAuthor.value, authorId.value);
});
const handle = computed(() => handleLabel(authorId.value));
const showHandle = computed(() => displayName.value !== handle.value);
const isRemoteAuthor = computed(() => isRemoteId(authorId.value));
const profileHref = computed(() => isRemoteAuthor.value
	? (resolvedAuthor.value?.url || authorId.value)
	: `#/u/${encodeURIComponent(authorId.value)}`);
const time = computed(() => relativeTime(props.post.published_at));
const absolute = computed(() => new Date(props.post.published_at).toLocaleString('de-DE'));
const isMine = computed(() => appState.session.user?.handle === props.post.author_id);
const localId = computed(() => postRouteId(props.post));
const detailHref = computed(() => localId.value === null ? null : `#/p/${encodeURIComponent(localId.value)}`);
const external = computed(() => (props.post.object_id && isRemoteId(props.post.object_id) ? props.post.object_id : null));
const shareUrl = computed(() => {
	if (localId.value !== null) {
		return `${window.location.origin}/app#/p/${encodeURIComponent(localId.value)}`;
	}

	return external.value ?? '';
});

const replyContext = computed(() => props.post.in_reply_to_id
	? `#/p/${encodeURIComponent(props.post.in_reply_to_id)}`
	: null);

const boosted = ref(false);
const boosting = ref(false);
const sharing = ref(false);
const deleting = ref(false);
const confirmingDelete = ref(false);
const menuOpen = ref(false);

function storageKey(): string {
	return `aloha.boosted.${localId.value ?? props.post.id}`;
}

function readBoosted(): boolean {
	try {
		return sessionStorage.getItem(storageKey()) === '1';
	} catch {
		return false;
	}
}

watch(localId, () => {
	boosted.value = readBoosted();
}, { immediate: true });

const pollTotal = computed(() => {
	const poll = props.post.poll;
	if (!poll) {
		return 0;
	}

	return Math.max(poll.votes_count, poll.options.reduce((sum, option) => sum + option.votes_count, 0));
});

function pollPercent(votes: number): number {
	return pollTotal.value === 0 ? 0 : Math.round((votes / pollTotal.value) * 100);
}

function reply(): void {
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	openComposer(props.post);
}

async function toggleBoost(): Promise<void> {
	if (localId.value === null || boosting.value) {
		return;
	}
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	boosting.value = true;
	try {
		if (boosted.value) {
			await getApi().unboostPost(localId.value);
			boosted.value = false;
			showToast('Boost entfernt.', 'success');
		} else {
			await getApi().boostPost(localId.value);
			boosted.value = true;
			showToast('Beitrag geboostet.', 'success');
		}
		try {
			sessionStorage.setItem(storageKey(), boosted.value ? '1' : '0');
		} catch {
			// The in-memory state is enough when storage is unavailable.
		}
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'Der Beitrag konnte nicht geboostet werden.', 'error');
	} finally {
		boosting.value = false;
	}
}

async function share(): Promise<void> {
	if (shareUrl.value === '' || sharing.value) {
		return;
	}

	sharing.value = true;
	try {
		if (navigator.clipboard?.writeText !== undefined) {
			await navigator.clipboard.writeText(shareUrl.value);
			showToast('Link in die Zwischenablage kopiert.', 'success');
		} else {
			window.prompt('Link zum Beitrag', shareUrl.value);
		}
	} catch {
		window.prompt('Link zum Beitrag', shareUrl.value);
	} finally {
		sharing.value = false;
		menuOpen.value = false;
	}
}

async function remove(): Promise<void> {
	if (localId.value === null || deleting.value) {
		return;
	}

	deleting.value = true;
	try {
		await getApi().deletePost(localId.value);
		announcePostDeleted(localId.value);
		showToast('Beitrag gelöscht.', 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'Der Beitrag konnte nicht gelöscht werden.', 'error');
	} finally {
		deleting.value = false;
		confirmingDelete.value = false;
		menuOpen.value = false;
	}
}

function onDocumentClick(event: MouseEvent): void {
	if (!menuOpen.value) {
		return;
	}
	const target = event.target as HTMLElement | null;
	if (target?.closest('.a-post__menu-wrap') === null) {
		menuOpen.value = false;
	}
}

watch(menuOpen, (open) => {
	if (open) {
		document.addEventListener('click', onDocumentClick);
	} else {
		document.removeEventListener('click', onDocumentClick);
	}
});

onUnmounted(() => {
	document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
	<article
		class="a-post"
		:class="{ 'a-post--detail': detail, 'a-post--boost': post.kind === 'boost' }"
		:aria-label="`Beitrag von ${handle}`"
	>
		<p v-if="post.kind === 'boost' && post.boosted_by" class="a-post__boosted-by">
			<AppIcon name="repeat" :size="15" />
			<span><a :href="`#/u/${encodeURIComponent(post.boosted_by)}`">{{ handleLabel(post.boosted_by) }}</a> hat geboostet</span>
		</p>

		<header class="a-post__header">
			<a :href="profileHref" class="a-post__avatar-link" :aria-label="`Profil von ${displayName}`">
				<Avatar :id="authorId" :name="displayName" :size="detail ? 52 : 44" />
			</a>

			<div class="a-post__identity">
				<a
					:href="profileHref"
					class="a-post__name"
					:target="isRemoteAuthor ? '_blank' : undefined"
					:rel="isRemoteAuthor ? 'noopener noreferrer' : undefined"
				>{{ displayName }}</a>
				<span class="a-post__meta">
					<template v-if="showHandle">
						<a :href="profileHref" class="a-post__handle">{{ handle }}</a>
						<span aria-hidden="true">·</span>
					</template>
					<a
						v-if="detailHref"
						:href="detailHref"
						class="a-post__time"
						:title="absolute"
					>{{ time }}</a>
					<time v-else class="a-post__time" :datetime="post.published_at" :title="absolute">{{ time }}</time>
					<span v-if="detail" class="a-post__visibility">
						<AppIcon :name="post.visibility === 'public' ? 'globe' : post.visibility === 'followers' ? 'users' : 'lock'" :size="13" />
						{{ visibilityLabel(post.visibility) }}
					</span>
				</span>
			</div>

			<div class="a-post__menu-wrap">
				<button
					type="button"
					class="a-icon-btn a-post__menu-button"
					aria-label="Weitere Aktionen"
					:aria-expanded="menuOpen"
					@click.stop="menuOpen = !menuOpen"
				>
					<AppIcon name="more" :size="19" />
				</button>

				<div v-if="menuOpen" class="a-post__menu" role="menu">
					<button type="button" role="menuitem" @click.stop="share">
						<AppIcon name="share" :size="15" /> Link kopieren
					</button>
					<a
						v-if="external"
						:href="external"
						target="_blank"
						rel="noopener noreferrer"
						role="menuitem"
						@click="menuOpen = false"
					>
						<AppIcon name="external" :size="15" /> Original öffnen
					</a>
					<button
						v-if="isMine"
						type="button"
						role="menuitem"
						class="a-post__menu-danger"
						@click.stop="confirmingDelete = true; menuOpen = false"
					>
						<AppIcon name="trash" :size="15" /> Beitrag löschen
					</button>
				</div>
			</div>
		</header>

		<p v-if="replyContext" class="a-post__reply-context">
			<AppIcon name="reply" :size="14" />
			<a :href="replyContext">Antwort auf einen Beitrag</a>
		</p>

		<div v-if="confirmingDelete" class="a-post__confirm" role="alert">
			<span>Diesen Beitrag wirklich löschen?</span>
			<div>
				<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="deleting" @click="remove">
					{{ deleting ? 'Löschen…' : 'Löschen' }}
				</button>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="confirmingDelete = false">Abbrechen</button>
			</div>
		</div>

		<details v-if="post.content_warning" class="a-post__cw">
			<summary>
				<AppIcon name="info" :size="14" />
				Inhaltswarnung: <strong>{{ post.content_warning }}</strong>
			</summary>
			<RichText :text="post.content" />
		</details>
		<RichText v-else :text="post.content" class="a-post__content" />

		<MediaGrid
			v-if="post.attachments && post.attachments.length > 0"
			:attachments="post.attachments"
			:author-handle="handle"
		/>

		<div v-if="post.poll" class="a-post__poll">
			<p class="a-post__poll-head">
				<AppIcon name="hash" :size="15" />
				{{ post.poll.multiple ? 'Umfrage · Mehrfachauswahl' : 'Umfrage' }}
				<span>{{ pollTotal }} {{ pollTotal === 1 ? 'Stimme' : 'Stimmen' }}</span>
			</p>
			<ul>
				<li v-for="(option, index) in post.poll.options" :key="index">
					<div class="a-post__poll-row">
						<span>{{ option.title }}</span>
						<strong>{{ pollPercent(option.votes_count) }}%</strong>
					</div>
					<span class="a-post__poll-bar" aria-hidden="true">
						<span :style="{ width: `${pollPercent(option.votes_count)}%` }" />
					</span>
				</li>
			</ul>
		</div>

		<footer class="a-post__actions">
			<button type="button" class="a-post__action" aria-label="Antworten" title="Antworten" @click="reply">
				<AppIcon name="reply" :size="18" />
				<span>Antworten</span>
			</button>

			<button
				v-if="localId"
				type="button"
				class="a-post__action"
				:class="{ 'a-post__action--active': boosted }"
				:aria-label="boosted ? 'Boost entfernen' : 'Beitrag boosten'"
				:title="boosted ? 'Boost entfernen' : 'Boosten'"
				:disabled="boosting"
				@click="toggleBoost"
			>
				<AppIcon name="repeat" :size="18" />
				<span>{{ boosted ? 'Geboostet' : 'Boosten' }}</span>
			</button>

			<button type="button" class="a-post__action" aria-label="Link teilen" title="Link teilen" @click="share">
				<AppIcon name="share" :size="18" />
				<span>Teilen</span>
			</button>

			<a
				v-if="detailHref && !detail"
				:href="detailHref"
				class="a-post__action a-post__action--link"
				aria-label="Beitrag öffnen"
				title="Beitrag öffnen"
			>
				<AppIcon name="external" :size="17" />
				<span>Öffnen</span>
			</a>
		</footer>
	</article>
</template>
