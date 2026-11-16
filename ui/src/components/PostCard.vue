<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AddToCollectionDialog from './AddToCollectionDialog.vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import EditHistoryDialog from './EditHistoryDialog.vue';
import MediaGrid from './MediaGrid.vue';
import ReportDialog from './ReportDialog.vue';
import RichText from './RichText.vue';
import { accountName, handleLabel, isLocalPostId, isRemoteId, postRouteId, relativeTime, visibilityLabel } from '../lib/format';
import { hostDisplayName, resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { announcePostDeleted } from '../lib/events';
import { ensureInteractions, interactionFor, patchInteraction, rememberInteraction } from '../lib/interactions';
import { cachedQuote, loadQuote } from '../lib/quotes';
import { appState, openComposer, openEditor, openQuote, showToast } from '../lib/state';
import type { Poll, TimelineItem } from '../lib/types';

const props = withDefaults(defineProps<{
	post: TimelineItem;
	detail?: boolean;
}>(), {
	detail: false,
});

const emit = defineEmits<{ bookmark: [bookmarked: boolean] }>();

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
	const remoteName = props.post.author_display_name?.trim() ?? '';
	if (remoteName !== '') {
		return remoteName;
	}

	const remoteHandle = props.post.author_handle?.trim() ?? '';
	if (remoteHandle !== '') {
		return remoteHandle;
	}

	const session = appState.session;
	if (session.user?.handle === authorId.value) {
		return session.user.displayName || session.profile?.display_name || handleLabel(authorId.value);
	}

	return hostDisplayName(authorId.value) || accountName(resolvedAuthor.value, authorId.value);
});
const handle = computed(() => {
	const remoteHandle = props.post.author_handle?.trim() ?? '';
	return remoteHandle !== '' ? remoteHandle : handleLabel(authorId.value);
});
const showHandle = computed(() => displayName.value !== handle.value);
const isRemoteAuthor = computed(() => props.post.is_remote === true || isRemoteId(authorId.value));
const profileTarget = computed(() => {
	const remoteHandle = props.post.author_handle?.trim() ?? '';
	return remoteHandle !== '' ? remoteHandle : authorId.value;
});
const profileHref = computed(() => `#/u/${encodeURIComponent(profileTarget.value)}`);
const time = computed(() => relativeTime(props.post.published_at));
const absolute = computed(() => new Date(props.post.published_at).toLocaleString('de-DE'));
const isMine = computed(() => !isRemoteAuthor.value && appState.session.user?.handle === authorId.value);
const canEdit = computed(() => (
	isMine.value
	&& props.post.kind !== 'boost'
	&& props.post.deleted_at === null
	&& postRouteId(props.post) !== null
));
const editedAbsolute = computed(() => (
	props.post.edited_at ? new Date(props.post.edited_at).toLocaleString('de-DE') : ''
));
const localId = computed(() => postRouteId(props.post));
const remoteTarget = computed(() => {
	const candidate = props.post.object_id ?? props.post.id;
	return isRemoteId(candidate) || candidate.startsWith('at://') ? candidate : null;
});
const detailTarget = computed(() => localId.value ?? remoteTarget.value);
const detailHref = computed(() => detailTarget.value === null ? null : `#/p/${encodeURIComponent(detailTarget.value)}`);
const isRemotePost = computed(() => localId.value === null);
const reportTargetId = computed(() => localId.value ?? remoteTarget.value ?? '');
const canReport = computed(() => (
	appState.session.user !== null
	&& !isMine.value
	&& reportTargetId.value !== ''
));

// --- Quote posts ---------------------------------------------------------
const quoteObjectId = computed(() => props.post.quoted_object_id ?? '');
const quotedPost = ref<TimelineItem | null>(null);
const quoteLoading = ref(false);
const quoteStateLabel = computed(() => {
	switch (props.post.quote_state) {
		case 'pending':
			return 'Freigabe des Zitats steht noch aus';
		case 'rejected':
			return 'Zitat wurde abgelehnt';
		case 'revoked':
			return 'Zitatfreigabe wurde entzogen';
		default:
			return '';
	}
});
const canQuote = computed(() => {
	if (appState.session.user === null || props.post.deleted_at !== null || props.post.kind === 'boost') {
		return false;
	}
	if (localId.value === null) {
		return true;
	}

	return props.post.visibility === 'public' || props.post.visibility === 'unlisted';
});

watch(quoteObjectId, async (objectId) => {
	if (objectId === '') {
		quotedPost.value = null;
		return;
	}

	const hit = cachedQuote(objectId);
	if (hit !== undefined) {
		quotedPost.value = hit;
		return;
	}

	quoteLoading.value = true;
	quotedPost.value = await loadQuote(objectId);
	quoteLoading.value = false;
}, { immediate: true });

function quoteAuthor(post: TimelineItem): string {
	const name = post.author_display_name?.trim() ?? '';
	if (name !== '') {
		return name;
	}

	const remoteHandle = post.author_handle?.trim() ?? '';
	if (remoteHandle !== '') {
		return remoteHandle;
	}

	return handleLabel(post.object_author_id ?? post.author_id);
}

function quoteAuthorHandle(post: TimelineItem): string {
	const remoteHandle = post.author_handle?.trim() ?? '';
	return remoteHandle !== '' ? remoteHandle : handleLabel(post.object_author_id ?? post.author_id);
}

function quoteAuthorHref(post: TimelineItem): string {
	const target = post.author_handle?.trim() || post.object_author_id || post.author_id;
	return `#/u/${encodeURIComponent(target)}`;
}

function quotedHref(post: TimelineItem): string | null {
	const local = postRouteId(post);
	const target = local ?? (isRemoteId(post.object_id ?? '') ? post.object_id : null)
		?? (isRemoteId(post.id) || post.id.startsWith('at://') ? post.id : null);
	return target === null ? null : `#/p/${encodeURIComponent(target)}`;
}

function quote(): void {
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	openQuote(props.post);
}

function blueskyPostUrl(id: string): string | null {
	if (!id.startsWith('at://')) {
		return null;
	}

	const parts = id.slice('at://'.length).split('/');
	if (parts.length < 3 || parts[0] === '' || parts[2] === '') {
		return null;
	}

	return `https://bsky.app/profile/${encodeURIComponent(parts[0])}/post/${encodeURIComponent(parts[2])}`;
}

const external = computed(() => {
	const objectId = props.post.object_id ?? '';
	if (isRemoteId(objectId)) {
		return objectId;
	}
	if (isRemoteId(props.post.id)) {
		return props.post.id;
	}

	return blueskyPostUrl(props.post.id);
});
const shareUrl = computed(() => {
	if (localId.value !== null) {
		return `${window.location.origin}/app#/p/${encodeURIComponent(localId.value)}`;
	}

	return external.value ?? '';
});

function postDetailHref(value: string | null | undefined): string | null {
	if (value === null || value === undefined || value === '') {
		return null;
	}
	if (isLocalPostId(value) || isRemoteId(value) || value.startsWith('at://')) {
		return `#/p/${encodeURIComponent(value)}`;
	}

	return null;
}

const replyContext = computed(() => postDetailHref(props.post.in_reply_to_id));

const interaction = computed(() => interactionFor(localId.value));
const liked = computed(() => interaction.value?.favourited === true);
const likeCount = computed(() => interaction.value?.favourites_count ?? props.post.likes_count ?? 0);
const boostCount = computed(() => interaction.value?.reblogs_count ?? props.post.boosts_count ?? 0);
const replyCount = computed(() => interaction.value?.replies_count ?? props.post.replies_count ?? 0);
const emojiReactions = computed(() => interaction.value?.emoji_reactions ?? []);

const boosted = ref(false);
const boosting = ref(false);
const liking = ref(false);
const sharing = ref(false);
const deleting = ref(false);
const confirmingDelete = ref(false);
const menuOpen = ref(false);
const bookmarked = ref(props.post.bookmarked === true);
const bookmarking = ref(false);
const reportOpen = ref(false);
const historyOpen = ref(false);
const collectionOpen = ref(false);

watch(() => props.post.bookmarked, (value) => {
	if (value !== undefined) {
		bookmarked.value = value;
	}
});

const pollState = ref<Poll | null>(props.post.poll ?? null);
const selectedChoices = ref<number[]>([]);
const voting = ref(false);
const pollError = ref('');

watch(() => props.post.poll, (value) => {
	pollState.value = value ?? null;
	selectedChoices.value = [];
}, { deep: true });

const pollOpen = computed(() => {
	const poll = pollState.value;
	if (poll === null) {
		return false;
	}

	return poll.expired !== true && poll.expires_at !== '' && new Date(poll.expires_at).getTime() > Date.now();
});

const canVote = computed(() => (
	localId.value !== null
	&& pollState.value !== null
	&& pollState.value.voted !== true
	&& pollOpen.value
	&& appState.session.user !== null
));

async function loadPollState(): Promise<void> {
	if (localId.value === null || pollState.value === null) {
		return;
	}

	try {
		const poll = await getApi().poll(localId.value);
		if (poll !== null) {
			pollState.value = { ...poll, options: poll.options.length > 0 ? poll.options : pollState.value.options };
		}
	} catch {
		// Anonymous readers and hosts without the compatibility API keep the
		// counts from the post; only the viewer-scoped vote state is missing.
	}
}

onMounted(() => {
	void loadPollState();
});

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

watch(localId, (id) => {
	boosted.value = readBoosted();
	ensureInteractions([id]);
}, { immediate: true });

watch(() => interaction.value?.reblogged, (value) => {
	if (value !== undefined) {
		boosted.value = value;
	}
});

const pollTotal = computed(() => {
	const poll = pollState.value;
	if (!poll) {
		return 0;
	}

	return Math.max(poll.votes_count, poll.options.reduce((sum, option) => sum + option.votes_count, 0));
});

function pollPercent(votes: number): number {
	return pollTotal.value === 0 ? 0 : Math.round((votes / pollTotal.value) * 100);
}

function toggleChoice(index: number): void {
	const poll = pollState.value;
	if (poll === null || !canVote.value) {
		return;
	}

	if (poll.multiple) {
		selectedChoices.value = selectedChoices.value.includes(index)
			? selectedChoices.value.filter((choice) => choice !== index)
			: [...selectedChoices.value, index];
		return;
	}

	selectedChoices.value = [index];
}

async function submitVote(): Promise<void> {
	const poll = pollState.value;
	if (poll === null || !canVote.value || selectedChoices.value.length === 0 || voting.value) {
		return;
	}

	voting.value = true;
	pollError.value = '';
	try {
		const pollId = poll.id ?? (localId.value === null ? '' : `poll-${localId.value}`);
		if (pollId === '') {
			throw new Error('Diese Umfrage kann hier nicht abgestimmt werden.');
		}

		const saved = await getApi().votePoll(pollId, selectedChoices.value);
		if (saved !== null) {
			pollState.value = {
				...saved,
				options: saved.options.length > 0 ? saved.options : poll.options,
			};
		} else {
			pollState.value = { ...poll, voted: true, own_votes: [...selectedChoices.value] };
		}
		showToast('Stimme gespeichert.', 'success');
	} catch (error) {
		pollError.value = error instanceof Error ? error.message : 'Die Stimme konnte nicht gespeichert werden.';
	} finally {
		voting.value = false;
	}
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
			patchInteraction(localId.value, {
				reblogged: false,
				reblogs_count: Math.max(0, boostCount.value - 1),
			});
			showToast('Boost entfernt.', 'success');
		} else {
			await getApi().boostPost(localId.value);
			boosted.value = true;
			patchInteraction(localId.value, {
				reblogged: true,
				reblogs_count: boostCount.value + 1,
			});
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

async function toggleLike(): Promise<void> {
	if (localId.value === null || liking.value) {
		return;
	}
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	const id = localId.value;
	const before = interaction.value ?? {
		favourites_count: 0,
		favourited: false,
		reblogs_count: 0,
		reblogged: false,
		replies_count: 0,
	};
	const next = {
		...before,
		favourited: !before.favourited,
		favourites_count: Math.max(0, before.favourites_count + (before.favourited ? -1 : 1)),
	};

	rememberInteraction(id, next);
	liking.value = true;
	try {
		const saved = next.favourited
			? await getApi().likePost(id)
			: await getApi().unlikePost(id);
		rememberInteraction(id, saved);
		showToast(saved.favourited ? 'Beitrag gefällt dir.' : 'Like entfernt.', 'success');
	} catch (error) {
		rememberInteraction(id, before);
		showToast(error instanceof Error ? error.message : 'Der Like konnte nicht gespeichert werden.', 'error');
	} finally {
		liking.value = false;
	}
}

async function toggleBookmark(): Promise<void> {
	if (localId.value === null || bookmarking.value) {
		return;
	}
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	bookmarking.value = true;
	menuOpen.value = false;
	try {
		if (bookmarked.value) {
			await getApi().unbookmarkPost(localId.value);
			bookmarked.value = false;
			showToast('Lesezeichen entfernt.', 'success');
		} else {
			await getApi().bookmarkPost(localId.value);
			bookmarked.value = true;
			showToast('Beitrag als Lesezeichen gespeichert.', 'success');
		}
		emit('bookmark', bookmarked.value);
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'Das Lesezeichen konnte nicht geändert werden.', 'error');
	} finally {
		bookmarking.value = false;
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

function edit(): void {
	menuOpen.value = false;
	openEditor(props.post);
}

function openReport(): void {
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	menuOpen.value = false;
	reportOpen.value = true;
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
				<Avatar :id="authorId" :name="displayName" :src="post.author_avatar" :size="detail ? 52 : 44" />
			</a>

			<div class="a-post__identity">
				<a
					:href="profileHref"
					class="a-post__name"
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
					<span v-if="post.edited_at" class="a-post__edited" :title="`Bearbeitet: ${editedAbsolute}`">· bearbeitet</span>
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
					<button v-if="localId" type="button" role="menuitem" :disabled="bookmarking" @click.stop="toggleBookmark">
						<AppIcon name="bookmark" :size="15" />
						{{ bookmarked ? 'Lesezeichen entfernen' : 'Als Lesezeichen speichern' }}
					</button>
					<button type="button" role="menuitem" @click.stop="share">
						<AppIcon name="share" :size="15" /> Link kopieren
					</button>
					<button v-if="canQuote" type="button" role="menuitem" @click.stop="quote(); menuOpen = false">
						<AppIcon name="quote" :size="15" /> Beitrag zitieren
					</button>
					<button
						v-if="localId"
						type="button"
						role="menuitem"
						@click.stop="collectionOpen = true; menuOpen = false"
					>
						<AppIcon name="list" :size="15" /> Zu Sammlung hinzufügen
					</button>
					<button
						v-if="localId && post.revision !== undefined && post.revision > 1"
						type="button"
						role="menuitem"
						@click.stop="historyOpen = true; menuOpen = false"
					>
						<AppIcon name="clock" :size="15" /> Bearbeitungshistorie
					</button>
					<button v-if="canReport" type="button" role="menuitem" @click.stop="openReport">
						<AppIcon name="flag" :size="15" /> Beitrag melden
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
						v-if="canEdit"
						type="button"
						role="menuitem"
						@click.stop="edit"
					>
						<AppIcon name="pen" :size="15" /> Beitrag bearbeiten
					</button>
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
			<a :href="replyContext">{{ isRemotePost ? 'Antwort auf einen externen Beitrag' : 'Antwort auf einen Beitrag' }}</a>
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

		<article v-if="quoteObjectId !== ''" class="a-quote-embed">
			<p v-if="quoteStateLabel !== ''" class="a-quote-embed__state">
				<AppIcon name="info" :size="13" />
				{{ quoteStateLabel }}
			</p>
			<a
				v-if="quotedPost"
				class="a-quote-embed__link"
				:href="quotedHref(quotedPost) ?? undefined"
			>
				<header class="a-quote-embed__head">
					<Avatar
						:id="quotedPost.object_author_id ?? quotedPost.author_id"
						:name="quoteAuthor(quotedPost)"
						:src="quotedPost.author_avatar"
						:size="26"
					/>
					<strong>{{ quoteAuthor(quotedPost) }}</strong>
					<a
						v-if="quoteAuthor(quotedPost) !== quoteAuthorHandle(quotedPost)"
						:href="quoteAuthorHref(quotedPost)"
						class="a-quote-embed__handle"
						@click.stop
					>{{ quoteAuthorHandle(quotedPost) }}</a>
					<time :datetime="quotedPost.published_at">{{ relativeTime(quotedPost.published_at) }}</time>
				</header>
				<p v-if="quotedPost.content_warning" class="a-quote-embed__cw">
					<AppIcon name="info" :size="13" /> {{ quotedPost.content_warning }}
				</p>
				<RichText v-else :text="quotedPost.content" class="a-quote-embed__text" />
				<img
					v-if="quotedPost.attachments && quotedPost.attachments.length > 0"
					class="a-quote-embed__media"
					:src="quotedPost.attachments[0].thumbnail_url ?? quotedPost.attachments[0].url"
					:alt="quotedPost.attachments[0].alt_text ?? ''"
					loading="lazy"
				/>
			</a>
			<p v-else-if="quoteLoading" class="a-quote-embed__missing">Zitierter Beitrag wird geladen …</p>
			<p v-else class="a-quote-embed__missing">
				Der zitierte Beitrag ist nicht mehr öffentlich abrufbar.
			</p>
		</article>

		<MediaGrid
			v-if="post.attachments && post.attachments.length > 0"
			:attachments="post.attachments"
			:author-handle="handle"
		/>

		<div v-if="pollState" class="a-post__poll">
			<p class="a-post__poll-head">
				<AppIcon name="hash" :size="15" />
				{{ pollState.multiple ? 'Umfrage · Mehrfachauswahl' : 'Umfrage' }}
				<span>{{ pollTotal }} {{ pollTotal === 1 ? 'Stimme' : 'Stimmen' }}</span>
			</p>
			<ul>
				<li v-for="(option, index) in pollState.options" :key="index">
					<label
						class="a-post__poll-choice"
						:class="{ 'a-post__poll-choice--voted': pollState.own_votes?.includes(index) }"
					>
						<input
							v-if="canVote"
							:type="pollState.multiple ? 'checkbox' : 'radio'"
							:name="`poll-${localId ?? post.id}`"
							:value="index"
							:checked="selectedChoices.includes(index)"
							@change="toggleChoice(index)"
						/>
						<span class="a-post__poll-label">{{ option.title }}</span>
						<strong>{{ pollPercent(option.votes_count) }}%</strong>
					</label>
					<span class="a-post__poll-bar" aria-hidden="true">
						<span :style="{ width: `${pollPercent(option.votes_count)}%` }" />
					</span>
				</li>
			</ul>
			<div class="a-post__poll-foot">
				<span v-if="pollState.voted">Du hast abgestimmt.</span>
				<span v-else-if="pollState.expired || !pollOpen">Umfrage beendet.</span>
				<span v-else>{{ pollState.voters_count }} Teilnehmende · endet {{ relativeTime(pollState.expires_at) }}</span>
				<button
					v-if="canVote"
					type="button"
					class="a-btn a-btn--primary a-btn--small"
					:disabled="selectedChoices.length === 0 || voting"
					@click="submitVote"
				>
					{{ voting ? 'Wird gespeichert…' : 'Abstimmen' }}
				</button>
			</div>
			<p v-if="pollError" class="a-form-error" role="alert">{{ pollError }}</p>
		</div>

		<ul v-if="emojiReactions.length > 0" class="a-post__reactions">
			<li v-for="reaction in emojiReactions" :key="reaction.emoji" :title="`${reaction.count} Reaktion(en)`">
				<span>{{ reaction.emoji }}</span>
				<strong>{{ reaction.count }}</strong>
			</li>
		</ul>

		<footer class="a-post__actions">
			<button
				v-if="!isRemotePost"
				type="button"
				class="a-post__action"
				:aria-label="`Antworten${replyCount > 0 ? ` (${replyCount})` : ''}`"
				:title="`Antworten${replyCount > 0 ? ` (${replyCount})` : ''}`"
				@click="reply"
			>
				<AppIcon name="reply" :size="18" />
				<b v-if="replyCount > 0" class="a-post__action-count">{{ replyCount }}</b>
			</button>
			<a
				v-else-if="external"
				:href="external"
				target="_blank"
				rel="noopener noreferrer"
				class="a-post__action"
				:aria-label="`Auf dem Ursprungs-Server antworten${replyCount > 0 ? ` (${replyCount})` : ''}`"
				:title="`Auf dem Ursprungs-Server antworten${replyCount > 0 ? ` (${replyCount})` : ''}`"
			>
				<AppIcon name="reply" :size="18" />
				<b v-if="replyCount > 0" class="a-post__action-count">{{ replyCount }}</b>
			</a>
			<span v-else class="a-post__action a-post__action--static" :title="`${replyCount} Antworten`">
				<AppIcon name="reply" :size="18" />
				<b v-if="replyCount > 0" class="a-post__action-count">{{ replyCount }}</b>
			</span>

			<button
				v-if="localId"
				type="button"
				class="a-post__action"
				:class="{ 'a-post__action--active': boosted }"
				:aria-label="boosted ? 'Boost entfernen' : 'Beitrag boosten'"
				:title="boosted ? 'Boost entfernen' : 'Beitrag boosten'"
				:disabled="boosting"
				@click="toggleBoost"
			>
				<AppIcon name="repeat" :size="18" />
				<b v-if="boostCount > 0" class="a-post__action-count">{{ boostCount }}</b>
			</button>
			<span
				v-else
				class="a-post__action a-post__action--static"
				:title="`${boostCount} Boosts auf ${handle}`"
			>
				<AppIcon name="repeat" :size="18" />
				<b v-if="boostCount > 0" class="a-post__action-count">{{ boostCount }}</b>
			</span>

			<button
				v-if="canQuote"
				type="button"
				class="a-post__action"
				aria-label="Beitrag zitieren"
				title="Beitrag zitieren"
				@click="quote"
			>
				<AppIcon name="quote" :size="18" />
			</button>

			<button
				v-if="localId"
				type="button"
				class="a-post__action a-post__action--like"
				:class="{ 'a-post__action--liked': liked }"
				:aria-label="liked ? 'Like entfernen' : 'Beitrag liken'"
				:aria-pressed="liked"
				:title="liked ? 'Like entfernen' : 'Beitrag liken'"
				:disabled="liking"
				@click="toggleLike"
			>
				<AppIcon name="heart" :size="18" />
				<b v-if="likeCount > 0" class="a-post__action-count">{{ likeCount }}</b>
			</button>
			<span
				v-else
				class="a-post__action a-post__action--static"
				:title="`${likeCount} Likes auf ${handle}`"
			>
				<AppIcon name="heart" :size="18" />
				<b v-if="likeCount > 0" class="a-post__action-count">{{ likeCount }}</b>
			</span>

			<button
				v-if="localId && appState.session.user !== null"
				type="button"
				class="a-post__action"
				:class="{ 'a-post__action--active': bookmarked }"
				:aria-label="bookmarked ? 'Lesezeichen entfernen' : 'Als Lesezeichen speichern'"
				:aria-pressed="bookmarked"
				:title="bookmarked ? 'Lesezeichen entfernen' : 'Als Lesezeichen speichern'"
				:disabled="bookmarking"
				@click="toggleBookmark"
			>
				<AppIcon name="bookmark" :size="18" />
			</button>

			<button
				v-if="canReport"
				type="button"
				class="a-post__action"
				aria-label="Beitrag melden"
				title="Beitrag melden"
				@click="openReport"
			>
				<AppIcon name="flag" :size="18" />
			</button>

			<button type="button" class="a-post__action" aria-label="Link teilen" title="Link teilen" @click="share">
				<AppIcon name="share" :size="18" />
			</button>

			<a
				v-if="detailHref && !detail"
				:href="detailHref"
				class="a-post__action a-post__action--link"
				aria-label="Beitrag öffnen"
				title="Beitrag öffnen"
			>
				<AppIcon name="external" :size="17" />
			</a>
		</footer>

		<ReportDialog
			:open="reportOpen"
			target-type="post"
			:target-id="reportTargetId"
			:target-label="handle"
			@close="reportOpen = false"
		/>
	</article>

	<EditHistoryDialog
		:open="historyOpen"
		:post-id="localId ?? ''"
		@close="historyOpen = false"
	/>

	<AddToCollectionDialog
		:open="collectionOpen"
		:post-id="localId ?? ''"
		@close="collectionOpen = false"
	/>
</template>
