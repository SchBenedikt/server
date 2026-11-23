<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useHostBridge } from '@alohasocial/ui';
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
import { ensureInteractions, interactionFor, rememberInteraction } from '../lib/interactions';
import { cachedQuote, loadQuote } from '../lib/quotes';
import { appState, openComposer, openEditor, openQuote, showToast } from '../lib/state';
import type { Attachment, Poll, TimelineItem } from '../lib/types';

const props = withDefaults(defineProps<{
	post: TimelineItem;
	detail?: boolean;
	forYou?: boolean;
	pinned?: boolean;
}>(), {
	detail: false,
	forYou: false,
	pinned: false,
});

const emit = defineEmits<{ bookmark: [bookmarked: boolean]; pinned: [pinned: boolean] }>();
const hostBridge = useHostBridge();

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
const absolute = computed(() => new Date(props.post.published_at).toLocaleString('en-US'));
const isMine = computed(() => !isRemoteAuthor.value && appState.session.user?.handle === authorId.value);
const canEdit = computed(() => (
	isMine.value
	&& props.post.kind !== 'boost'
	&& props.post.deleted_at === null
	&& postRouteId(props.post) !== null
));
const editedAbsolute = computed(() => (
	props.post.edited_at ? new Date(props.post.edited_at).toLocaleString('en-US') : ''
));
const localId = computed(() => postRouteId(props.post));
const canPublishToBluesky = computed(() => isMine.value && localId.value !== null && hostBridge.capabilities().atproto);
const remoteTarget = computed(() => {
	const candidate = props.post.object_id ?? props.post.id;
	return isRemoteId(candidate) || candidate.startsWith('at://') ? candidate : null;
});
const interactionId = computed(() => (
	localId.value ?? (remoteTarget.value?.startsWith('http://') || remoteTarget.value?.startsWith('https://')
		? remoteTarget.value
		: null)
));
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
			return 'Quote approval is still pending';
		case 'rejected':
			return 'Quote was rejected';
		case 'revoked':
			return 'Quote approval was revoked';
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

function quoteMedia(post: TimelineItem): Attachment | null {
	return post.attachments?.[0] ?? null;
}

function attachmentIsVideo(attachment: Attachment): boolean {
	return attachment.media_type === 'video'
		|| attachment.media_type === 'gifv'
		|| attachment.mime_type.startsWith('video/');
}

function attachmentIsAudio(attachment: Attachment): boolean {
	return attachment.media_type === 'audio' || attachment.mime_type.startsWith('audio/');
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

const interaction = computed(() => interactionFor(interactionId.value));
const liked = computed(() => interaction.value?.favourited === true);
const likeCount = computed(() => isRemotePost.value
	? (props.post.likes_count ?? 0)
	: (interaction.value?.favourites_count ?? props.post.likes_count ?? 0));
const boostCount = computed(() => isRemotePost.value
	? Math.max(props.post.boosts_count ?? 0, interaction.value?.reblogged === true ? 1 : 0)
	: (interaction.value?.reblogs_count ?? props.post.boosts_count ?? 0));
const replyCount = computed(() => isRemotePost.value
	? (props.post.replies_count ?? 0)
	: (interaction.value?.replies_count ?? props.post.replies_count ?? 0));
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
const archiving = ref(false);
const publishingBluesky = ref(false);
const pinning = ref(false);
const isPinned = ref(props.pinned === true);

watch(() => props.pinned, (value) => {
	isPinned.value = value === true;
}, { immediate: true });
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

watch(localId, () => {
	boosted.value = readBoosted();
}, { immediate: true });

watch(interactionId, (id) => {
	if (id !== null && (id.startsWith('http://') || id.startsWith('https://'))) {
		boosted.value = false;
	}
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
			throw new Error('This poll cannot be voted on here.');
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
		showToast('Vote saved.', 'success');
	} catch (error) {
		pollError.value = error instanceof Error ? error.message : 'The vote could not be saved.';
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
	const id = interactionId.value;
	if (id === null || boosting.value) {
		return;
	}
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

	boosting.value = true;
	const before = interaction.value ?? {
		favourites_count: 0,
		favourited: false,
		reblogs_count: 0,
		reblogged: false,
		replies_count: 0,
	};
	try {
		const nextBoosted = !boosted.value;
		const next = {
			...before,
			reblogged: nextBoosted,
			reblogs_count: Math.max(0, before.reblogs_count + (nextBoosted ? 1 : -1)),
		};
		if (nextBoosted) {
			await getApi().boostPost(id);
		} else {
			await getApi().unboostPost(id);
		}
		boosted.value = nextBoosted;
		rememberInteraction(id, next);
		showToast(
			localId.value === null
				? (nextBoosted ? 'Remote boost queued.' : 'Remote boost removal queued.')
				: (nextBoosted ? 'Post boosted.' : 'Boost removed.'),
			'success',
		);
		try {
			sessionStorage.setItem(storageKey(), boosted.value ? '1' : '0');
		} catch {
			// The in-memory state is enough when storage is unavailable.
		}
	} catch (error) {
		boosted.value = before.reblogged;
		rememberInteraction(id, before);
		showToast(error instanceof Error ? error.message : 'The post could not be boosted.', 'error');
	} finally {
		boosting.value = false;
	}
}

async function toggleLike(): Promise<void> {
	const id = interactionId.value;
	if (id === null || liking.value) {
		return;
	}
	if (appState.session.user === null) {
		window.location.assign('/login');
		return;
	}

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
		showToast(
			id === remoteTarget.value
				? (saved.favourited ? 'Remote like queued.' : 'Remote unlike queued.')
				: (saved.favourited ? 'Post liked.' : 'Like removed.'),
			'success',
		);
	} catch (error) {
		rememberInteraction(id, before);
		showToast(error instanceof Error ? error.message : 'The like could not be saved.', 'error');
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
			showToast('Bookmark removed.', 'success');
		} else {
			await getApi().bookmarkPost(localId.value);
			bookmarked.value = true;
			showToast('Post bookmarked.', 'success');
		}
		emit('bookmark', bookmarked.value);
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The bookmark could not be changed.', 'error');
	} finally {
		bookmarking.value = false;
	}
}

async function togglePin(): Promise<void> {
	if (localId.value === null || pinning.value) {
		return;
	}

	pinning.value = true;
	menuOpen.value = false;
	try {
		if (isPinned.value) {
			await getApi().unpinPost(localId.value);
			isPinned.value = false;
			emit('pinned', false);
			showToast('Post unpinned.', 'success');
		} else {
			await getApi().pinPost(localId.value);
			isPinned.value = true;
			emit('pinned', true);
			showToast('Post pinned to the top of your profile.', 'success');
		}
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The post could not be pinned.', 'error');
	} finally {
		pinning.value = false;
	}
}

async function archive(): Promise<void> {
	if (localId.value === null || archiving.value) {
		return;
	}

	archiving.value = true;
	menuOpen.value = false;
	try {
		await getApi().archivePost(localId.value);
		showToast('Post archived. You can find it under “Archive”.', 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The post could not be archived.', 'error');
	} finally {
		archiving.value = false;
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
			showToast('Link copied to clipboard.', 'success');
		} else {
			window.prompt('Link to post', shareUrl.value);
		}
	} catch {
		window.prompt('Link to post', shareUrl.value);
	} finally {
		sharing.value = false;
		menuOpen.value = false;
	}
}

async function sendFeedback(kind: 'less' | 'more'): Promise<void> {
	if (localId.value === null) {
		return;
	}

	menuOpen.value = false;
	try {
		await getApi().forYouFeedback(localId.value, kind);
		showToast(
			kind === 'less' ? 'You will see less of this.' : 'You will see more of this.',
			'success',
		);
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The feedback could not be saved.', 'error');
	}
}

async function publishToBluesky(): Promise<void> {
	if (!canPublishToBluesky.value || publishingBluesky.value || localId.value === null) {
		return;
	}

	publishingBluesky.value = true;
	menuOpen.value = false;
	try {
		const status = await getApi().blueskyAccount();
		if (!status.publish_enabled) {
			showToast('Bluesky publishing is disabled on this instance.', 'error');
			return;
		}
		if (!status.account.connected) {
			showToast('Connect a Bluesky account in Settings before publishing.', 'info');
			return;
		}
		await getApi().publishToBluesky(localId.value);
		showToast('Post published to Bluesky.', 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The post could not be published to Bluesky.', 'error');
	} finally {
		publishingBluesky.value = false;
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
		showToast('Post deleted.', 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The post could not be deleted.', 'error');
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
		:aria-label="`Post by ${handle}`"
	>
		<p v-if="post.kind === 'boost' && post.boosted_by" class="a-post__boosted-by">
			<AppIcon name="repeat" :size="15" />
			<span><a :href="`#/u/${encodeURIComponent(post.boosted_by)}`">{{ handleLabel(post.boosted_by) }}</a> boosted</span>
		</p>

		<header class="a-post__header">
			<a :href="profileHref" class="a-post__avatar-link" :aria-label="`Profile of ${displayName}`">
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
					<span v-if="post.edited_at" class="a-post__edited" :title="`Edited: ${editedAbsolute}`">· edited</span>
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
					aria-label="More actions"
					:aria-expanded="menuOpen"
					@click.stop="menuOpen = !menuOpen"
				>
					<AppIcon name="more" :size="19" />
				</button>

				<div v-if="menuOpen" class="a-post__menu" role="menu">
					<button v-if="localId" type="button" role="menuitem" :disabled="bookmarking" @click.stop="toggleBookmark">
						<AppIcon name="bookmark" :size="15" />
						{{ bookmarked ? 'Remove bookmark' : 'Bookmark post' }}
					</button>
					<button type="button" role="menuitem" @click.stop="share">
						<AppIcon name="share" :size="15" /> Copy link
					</button>
					<button v-if="canQuote" type="button" role="menuitem" @click.stop="quote(); menuOpen = false">
						<AppIcon name="quote" :size="15" /> Quote post
					</button>
					<button
						v-if="localId"
						type="button"
						role="menuitem"
						@click.stop="collectionOpen = true; menuOpen = false"
					>
						<AppIcon name="list" :size="15" /> Add to collection
					</button>
					<button
						v-if="localId && post.revision !== undefined && post.revision > 1"
						type="button"
						role="menuitem"
						@click.stop="historyOpen = true; menuOpen = false"
					>
						<AppIcon name="clock" :size="15" /> Edit history
					</button>
					<button
						v-if="isMine && localId"
						type="button"
						role="menuitem"
						:disabled="pinning"
						@click.stop="togglePin"
					>
						<AppIcon :name="isPinned ? 'x' : 'pin'" :size="15" />
						{{ isPinned ? 'Unpin' : 'Pin to profile' }}
					</button>
					<button
						v-if="isMine && localId"
						type="button"
						role="menuitem"
						:disabled="archiving"
						@click.stop="archive"
					>
						<AppIcon name="archive" :size="15" /> Archive
					</button>
					<button
						v-if="forYou && localId"
						type="button"
						role="menuitem"
						@click.stop="sendFeedback('less')"
					>
						<AppIcon name="bellOff" :size="15" /> Show less of this
					</button>
					<button
						v-if="forYou && localId"
						type="button"
						role="menuitem"
						@click.stop="sendFeedback('more')"
					>
						<AppIcon name="sparkles" :size="15" /> Show more of this
					</button>
					<button
						v-if="canPublishToBluesky"
						type="button"
						role="menuitem"
						:disabled="publishingBluesky"
						@click.stop="publishToBluesky"
					>
						<AppIcon :name="publishingBluesky ? 'refresh' : 'share'" :size="15" :class="{ 'a-spin': publishingBluesky }" />
						Publish to Bluesky
					</button>
					<button v-if="canReport" type="button" role="menuitem" @click.stop="openReport">
						<AppIcon name="flag" :size="15" /> Report post
					</button>
					<a
						v-if="external"
						:href="external"
						target="_blank"
						rel="noopener noreferrer"
						role="menuitem"
						@click="menuOpen = false"
					>
						<AppIcon name="external" :size="15" /> Open original
					</a>
					<button
						v-if="canEdit"
						type="button"
						role="menuitem"
						@click.stop="edit"
					>
						<AppIcon name="pen" :size="15" /> Edit post
					</button>
					<button
						v-if="isMine"
						type="button"
						role="menuitem"
						class="a-post__menu-danger"
						@click.stop="confirmingDelete = true; menuOpen = false"
					>
						<AppIcon name="trash" :size="15" /> Delete post
					</button>
				</div>
			</div>
		</header>

		<p v-if="replyContext" class="a-post__reply-context">
			<AppIcon name="reply" :size="14" />
			<a :href="replyContext">{{ isRemotePost ? 'Reply to an external post' : 'Reply to a post' }}</a>
		</p>

		<div v-if="confirmingDelete" class="a-post__confirm" role="alert">
			<span>Delete this post?</span>
			<div>
				<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="deleting" @click="remove">
					{{ deleting ? 'Deleting…' : 'Delete' }}
				</button>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="confirmingDelete = false">Cancel</button>
			</div>
		</div>

		<details v-if="post.content_warning" class="a-post__cw">
			<summary>
				<AppIcon name="info" :size="14" />
				Content warning: <strong>{{ post.content_warning }}</strong>
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
				<template v-if="quoteMedia(quotedPost) !== null">
					<video
						v-if="attachmentIsVideo(quoteMedia(quotedPost)!)"
						class="a-quote-embed__media"
						:src="quoteMedia(quotedPost)!.url"
						:poster="quoteMedia(quotedPost)!.thumbnail_url ?? undefined"
						muted
						playsinline
						preload="metadata"
					/>
					<span
						v-else-if="attachmentIsAudio(quoteMedia(quotedPost)!)"
						class="a-quote-embed__media a-quote-embed__media--audio"
					>
						<AppIcon name="music" :size="16" />
						{{ quoteMedia(quotedPost)!.alt_text || 'Audio-attachment' }}
					</span>
					<img
						v-else
						class="a-quote-embed__media"
						:src="quoteMedia(quotedPost)!.thumbnail_url ?? quoteMedia(quotedPost)!.url"
						:alt="quoteMedia(quotedPost)!.alt_text ?? ''"
						loading="lazy"
					/>
				</template>
			</a>
			<p v-else-if="quoteLoading" class="a-quote-embed__missing">Loading quoted post…</p>
			<p v-else class="a-quote-embed__missing">
				The quoted post is no longer publicly available.
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
				{{ pollState.multiple ? 'poll · Multiple choice' : 'poll' }}
				<span>{{ pollTotal }} {{ pollTotal === 1 ? 'vote' : 'Votes' }}</span>
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
				<span v-if="pollState.voted">You voted.</span>
				<span v-else-if="pollState.expired || !pollOpen">Poll ended.</span>
				<span v-else>{{ pollState.voters_count }} voters · ends {{ relativeTime(pollState.expires_at) }}</span>
				<button
					v-if="canVote"
					type="button"
					class="a-btn a-btn--primary a-btn--small"
					:disabled="selectedChoices.length === 0 || voting"
					@click="submitVote"
				>
					{{ voting ? 'Saving…' : 'Vote' }}
				</button>
			</div>
			<p v-if="pollError" class="a-form-error" role="alert">{{ pollError }}</p>
		</div>

		<ul v-if="emojiReactions.length > 0" class="a-post__reactions">
			<li v-for="reaction in emojiReactions" :key="reaction.emoji" :title="`${reaction.count} reaction(s)`">
				<span>{{ reaction.emoji }}</span>
				<strong>{{ reaction.count }}</strong>
			</li>
		</ul>

		<footer class="a-post__actions">
			<button
				v-if="!isRemotePost"
				type="button"
				class="a-post__action"
				:aria-label="`Replies${replyCount > 0 ? ` (${replyCount})` : ''}`"
				:title="`Replies${replyCount > 0 ? ` (${replyCount})` : ''}`"
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
				:aria-label="`Reply on the original server${replyCount > 0 ? ` (${replyCount})` : ''}`"
				:title="`Reply on the original server${replyCount > 0 ? ` (${replyCount})` : ''}`"
			>
				<AppIcon name="reply" :size="18" />
				<b v-if="replyCount > 0" class="a-post__action-count">{{ replyCount }}</b>
			</a>
			<span v-else class="a-post__action a-post__action--static" :title="`${replyCount} Replies`">
				<AppIcon name="reply" :size="18" />
				<b v-if="replyCount > 0" class="a-post__action-count">{{ replyCount }}</b>
			</span>

			<button
				v-if="interactionId"
				type="button"
				class="a-post__action"
				:class="{ 'a-post__action--active': boosted }"
				:aria-label="boosted ? 'Remove boost' : 'Boost post'"
				:title="boosted ? 'Remove boost' : 'Boost post'"
				:disabled="boosting"
				@click="toggleBoost"
			>
				<AppIcon name="repeat" :size="18" />
				<b v-if="boostCount > 0" class="a-post__action-count">{{ boostCount }}</b>
			</button>
			<span
				v-else
				class="a-post__action a-post__action--static"
				:title="`${boostCount} boosts on ${handle}`"
			>
				<AppIcon name="repeat" :size="18" />
				<b v-if="boostCount > 0" class="a-post__action-count">{{ boostCount }}</b>
			</span>

			<button
				v-if="canQuote"
				type="button"
				class="a-post__action"
				aria-label="Quote post"
				title="Quote post"
				@click="quote"
			>
				<AppIcon name="quote" :size="18" />
			</button>

			<button
				v-if="interactionId"
				type="button"
				class="a-post__action a-post__action--like"
				:class="{ 'a-post__action--liked': liked }"
				:aria-label="liked ? 'Remove like' : 'Like post'"
				:aria-pressed="liked"
				:title="liked ? 'Remove like' : 'Like post'"
				:disabled="liking"
				@click="toggleLike"
			>
				<AppIcon name="heart" :size="18" />
				<b v-if="likeCount > 0" class="a-post__action-count">{{ likeCount }}</b>
			</button>
			<span
				v-else
				class="a-post__action a-post__action--static"
				:title="`${likeCount} likes on ${handle}`"
			>
				<AppIcon name="heart" :size="18" />
				<b v-if="likeCount > 0" class="a-post__action-count">{{ likeCount }}</b>
			</span>

			<button
				v-if="localId && appState.session.user !== null"
				type="button"
				class="a-post__action"
				:class="{ 'a-post__action--active': bookmarked }"
				:aria-label="bookmarked ? 'Remove bookmark' : 'Bookmark post'"
				:aria-pressed="bookmarked"
				:title="bookmarked ? 'Remove bookmark' : 'Bookmark post'"
				:disabled="bookmarking"
				@click="toggleBookmark"
			>
				<AppIcon name="bookmark" :size="18" />
			</button>

			<button
				v-if="canReport"
				type="button"
				class="a-post__action"
				aria-label="Report post"
				title="Report post"
				@click="openReport"
			>
				<AppIcon name="flag" :size="18" />
			</button>

			<button type="button" class="a-post__action" aria-label="Share link" title="Share link" @click="share">
				<AppIcon name="share" :size="18" />
			</button>

			<a
				v-if="detailHref && !detail"
				:href="detailHref"
				class="a-post__action a-post__action--link"
				aria-label="Open post"
				title="Open post"
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
