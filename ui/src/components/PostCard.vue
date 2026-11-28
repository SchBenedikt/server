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
import SourceDialog from './SourceDialog.vue';
import ReportDialog from './ReportDialog.vue';
import RichText from './RichText.vue';
import { accountName, absoluteTime, handleLabel, isLocalPostId, isRemoteId, postRouteId, relativeTime, visibilityLabel } from '../lib/format';
import { t } from '../lib/i18n';
import { hostDisplayName } from '../lib/account';
import { getApi } from '../lib/api';
import { announcePostDeleted } from '../lib/events';
import { ensureInteractions, interactionFor, rememberInteraction } from '../lib/interactions';
import { cachedQuote, loadQuote } from '../lib/quotes';
import { appState, openComposer, openEditor, openQuote, showToast } from '../lib/state';
import type { Attachment, AtprotoMirrorStatus, Poll, TimelineItem } from '../lib/types';
import type { ActivityPubDeliveryLookup, AtprotoPublicationLookup, AtprotoPublicationStatus } from '../lib/api';

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

	return hostDisplayName(authorId.value) || accountName(null, authorId.value);
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
const absolute = computed(() => absoluteTime(props.post.published_at));
const isMine = computed(() => !isRemoteAuthor.value && appState.session.user?.handle === authorId.value);
const canEdit = computed(() => (
	isMine.value
	&& props.post.kind !== 'boost'
	&& props.post.deleted_at === null
	&& postRouteId(props.post) !== null
));
const editedAbsolute = computed(() => (
	props.post.edited_at ? absoluteTime(props.post.edited_at) : ''
));
const localId = computed(() => postRouteId(props.post));
const canPublishToBluesky = computed(() => isMine.value && localId.value !== null && hostBridge.capabilities().atproto);
const remoteTarget = computed(() => {
	const candidate = props.post.object_id ?? props.post.id;
	return isRemoteId(candidate) || candidate.startsWith('at://') ? candidate : null;
});
// Every cached external post is likeable: an ActivityPub post by its object
// URL and an AT Protocol post by its at:// record URI. `remoteTarget` only
// yields those two shapes.
const interactionId = computed(() => localId.value ?? remoteTarget.value);
const detailTarget = computed(() => localId.value ?? remoteTarget.value);
const detailHref = computed(() => detailTarget.value === null ? null : `#/p/${encodeURIComponent(detailTarget.value)}`);
const isRemotePost = computed(() => localId.value === null);
const canReplyHere = computed(() => (
	!isRemotePost.value
	|| remoteTarget.value?.startsWith('http://') === true
	|| remoteTarget.value?.startsWith('https://') === true
));
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
			return t('card.quotePending');
		case 'rejected':
			return t('card.quoteRejected');
		case 'revoked':
			return t('card.quoteRevoked');
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
// The batched interaction value is authoritative once it arrives: it is
// refreshed after every like or boost, so a removal lowers the number again.
// The post payload is the fallback for cards that never request interactions.
const likeCount = computed(() => (
	interaction.value?.favourites_count ?? props.post.likes_count ?? 0
));
const boostCount = computed(() => Math.max(
	interaction.value?.reblogs_count ?? props.post.boosts_count ?? 0,
	interaction.value?.reblogged === true ? 1 : 0,
));
const replyCount = computed(() => (
	interaction.value?.replies_count ?? props.post.replies_count ?? 0
));
const emojiReactions = computed(() => interaction.value?.emoji_reactions ?? []);

const boosted = ref(false);
const boosting = ref(false);
const liking = ref(false);
const atprotoMirrorStatus = ref<AtprotoMirrorStatus | null>(null);
const retryingAtprotoMirror = ref(false);

function atprotoMirrorProblem(status: AtprotoMirrorStatus | null): string | null {
	if (status === null || status.state === 'published' || status.state === 'removed') return null;
	if (status.state === 'unavailable' || status.reason === 'atproto_disabled') return t('card.mirrorUnavailable');
	if (status.reason === 'atproto_account_not_connected') return t('card.mirrorReconnect');
	if (status.retryable) return t('card.mirrorTemp');
	return t('card.mirrorFailed');
}
const sharing = ref(false);
const deleting = ref(false);
const confirmingDelete = ref(false);
const menuOpen = ref(false);
const bookmarked = ref(props.post.bookmarked === true);
const bookmarking = ref(false);
const archiving = ref(false);
const publishingBluesky = ref(false);
const blueskyPublication = ref<AtprotoPublicationStatus | null>(null);
const blueskyDeliveryState = ref<AtprotoPublicationLookup['state']>('not_queued');
const blueskyDeliveryAttempts = ref(0);
const blueskyPublicationLoading = ref(false);
const blueskyPublicationError = ref('');
let blueskyPublicationLookup = 0;
const activityPubDelivery = ref<ActivityPubDeliveryLookup | null>(null);
const activityPubDeliveryLoading = ref(false);
const activityPubDeliveryError = ref('');
const retryingActivityPubDelivery = ref(false);
let activityPubDeliveryLookup = 0;
const pinning = ref(false);
const isPinned = ref(props.pinned === true);
const blueskyPublicationUrl = computed(() => blueskyPublication.value === null
	? ''
	: `https://bsky.app/profile/${encodeURIComponent(blueskyPublication.value.accountDid)}/post/${encodeURIComponent(blueskyPublication.value.rkey)}`);

watch(() => props.pinned, (value) => {
	isPinned.value = value === true;
}, { immediate: true });
const reportOpen = ref(false);
const historyOpen = ref(false);
const sourceOpen = ref(false);
const collectionOpen = ref(false);

watch(() => props.post.bookmarked, (value) => {
	if (value !== undefined) {
		bookmarked.value = value;
	}
});

watch(interaction, (value) => {
	if (value?.bookmarked !== undefined) {
		bookmarked.value = value.bookmarked;
	}
}, { immediate: true });

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

	const element = rootEl.value;
	if (element !== null && typeof IntersectionObserver === 'function') {
		viewportObserver = new IntersectionObserver((entries) => {
			if (entries.some((entry) => entry.isIntersecting)) {
				viewportObserver?.disconnect();
				viewportObserver = null;
				requestInteractions();
			}
		}, { rootMargin: '200px' });
		viewportObserver.observe(element);
	} else {
		requestInteractions();
	}
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
}, { immediate: true });

// Interaction state is only fetched once the card approaches the viewport:
// a long timeline must not fan out one batched request per off-screen card.
const rootEl = ref<HTMLElement | null>(null);
let viewportObserver: IntersectionObserver | null = null;

function requestInteractions(): void {
	const id = interactionId.value;
	if (id !== null) {
		ensureInteractions([id]);
	}
}

watch(interactionId, () => {
	if (viewportObserver === null) {
		requestInteractions();
	}
});

watch(interaction, (value) => {
	if (value !== undefined) {
		boosted.value = value.reblogged;
	}
}, { immediate: true });

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
			throw new Error(t('card.pollCannotVote'));
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
		showToast(t('card.voteSaved'), 'success');
	} catch (error) {
		pollError.value = error instanceof Error ? error.message : t('card.voteFailed');
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
	atprotoMirrorKind.value = 'boost';
	atprotoMirrorStatus.value = null;
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
			atprotoMirrorStatus.value = await getApi().boostPost(id);
		} else {
			atprotoMirrorStatus.value = await getApi().unboostPost(id);
		}
		boosted.value = nextBoosted;
		rememberInteraction(id, next);
		const mirrorProblem = atprotoMirrorProblem(atprotoMirrorStatus.value);
		showToast(
			mirrorProblem !== null
				? t('card.boostLocalOnly', { problem: mirrorProblem })
				: t(
					localId.value === null
						? (nextBoosted ? 'card.boostRemoteQueued' : 'card.boostRemoteRemovalQueued')
						: (nextBoosted ? 'card.boosted' : 'card.boostRemoved'),
				),
			mirrorProblem !== null ? 'error' : 'success',
		);
		try {
			sessionStorage.setItem(storageKey(), boosted.value ? '1' : '0');
		} catch {
			// The in-memory state is enough when storage is unavailable.
		}
	} catch (error) {
		boosted.value = before.reblogged;
		rememberInteraction(id, before);
		showToast(error instanceof Error ? error.message : t('card.boostFailed'), 'error');
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
	atprotoMirrorKind.value = 'like';
	atprotoMirrorStatus.value = null;
	try {
		const saved = next.favourited
			? await getApi().likePost(id)
			: await getApi().unlikePost(id);
		// Keep the optimistic number when a stale origin snapshot has not
		// caught up with this click yet; it must not jump back after a like.
		rememberInteraction(id, {
			...saved,
			favourites_count: saved.favourited
				? Math.max(saved.favourites_count, next.favourites_count)
				: Math.min(saved.favourites_count, next.favourites_count),
		});
		atprotoMirrorStatus.value = saved.atproto_mirror ?? null;
		const mirrorProblem = atprotoMirrorProblem(atprotoMirrorStatus.value);
		showToast(
			mirrorProblem !== null
				? t('card.likeLocalOnly', { problem: mirrorProblem })
				: t(
					id === remoteTarget.value
						? (saved.favourited ? 'card.likeRemoteQueued' : 'card.unlikeRemoteQueued')
						: (saved.favourited ? 'card.liked' : 'card.likeRemoved'),
				),
			mirrorProblem !== null ? 'error' : 'success',
		);
	} catch (error) {
		rememberInteraction(id, before);
		showToast(error instanceof Error ? error.message : t('card.likeFailed'), 'error');
	} finally {
		liking.value = false;
	}
}

async function retryAtprotoMirror(): Promise<void> {
	const id = interactionId.value;
	if (!id || retryingAtprotoMirror.value || !atprotoMirrorStatus.value?.retryable) return;
	retryingAtprotoMirror.value = true;
	try {
		if (atprotoMirrorKind.value === 'like') {
			const result = liked.value ? await getApi().likePost(id) : await getApi().unlikePost(id);
			rememberInteraction(id, result);
			atprotoMirrorStatus.value = result.atproto_mirror ?? null;
		} else {
			atprotoMirrorStatus.value = boosted.value ? await getApi().boostPost(id) : await getApi().unboostPost(id);
		}
		const mirrorProblem = atprotoMirrorProblem(atprotoMirrorStatus.value);
		showToast(mirrorProblem === null ? t('card.mirrorUpdated') : t('card.mirrorRetryFailed', { problem: mirrorProblem }), mirrorProblem === null ? 'success' : 'error');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.mirrorRetryFailedToast'), 'error');
	} finally { retryingAtprotoMirror.value = false; }
}

const atprotoMirrorKind = ref<'like' | 'boost'>('like');

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
			showToast(t('card.bookmarkRemoved'), 'success');
		} else {
			await getApi().bookmarkPost(localId.value);
			bookmarked.value = true;
			showToast(t('card.bookmarked'), 'success');
		}
		if (interaction.value !== undefined) {
			rememberInteraction(localId.value, { ...interaction.value, bookmarked: bookmarked.value });
		}
		emit('bookmark', bookmarked.value);
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.bookmarkFailed'), 'error');
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
			showToast(t('card.unpinned'), 'success');
		} else {
			await getApi().pinPost(localId.value);
			isPinned.value = true;
			emit('pinned', true);
			showToast(t('card.pinned'), 'success');
		}
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.pinFailed'), 'error');
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
		showToast(t('card.archived'), 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.archiveFailed'), 'error');
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
			showToast(t('card.linkCopied'), 'success');
		} else {
			window.prompt(t('card.linkToPost'), shareUrl.value);
		}
	} catch {
		window.prompt(t('card.linkToPost'), shareUrl.value);
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
		showToast(t(kind === 'less' ? 'card.feedbackLess' : 'card.feedbackMore'), 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.feedbackFailed'), 'error');
	}
}

async function publishToBluesky(): Promise<void> {
	if (!canPublishToBluesky.value || publishingBluesky.value || localId.value === null) {
		return;
	}

	publishingBluesky.value = true;
	menuOpen.value = false;
	try {
		const status = await getApi().atprotoIdentity();
		if (!status.publish) {
			showToast(t('card.bskyDisabled'), 'error');
			return;
		}
		const accountDid = status.identity?.did || (status.external.connected ? status.external.did : undefined);
		if (accountDid === undefined || accountDid === '') {
			showToast(t('card.bskyConnect'), 'info');
			return;
		}
		blueskyPublication.value = await getApi().publishToBluesky(localId.value, accountDid);
		blueskyDeliveryState.value = 'published';
		blueskyDeliveryAttempts.value = 0;
		showToast(t('card.bskyPublishedToast'), 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.bskyPublishFailed'), 'error');
	} finally {
		publishingBluesky.value = false;
	}
}

async function loadBlueskyPublication(): Promise<void> {
	if (!canPublishToBluesky.value || localId.value === null) {
		return;
	}

	const lookup = ++blueskyPublicationLookup;
	blueskyPublication.value = null;
	blueskyDeliveryState.value = 'not_queued';
	blueskyDeliveryAttempts.value = 0;
	blueskyPublicationError.value = '';
	blueskyPublicationLoading.value = true;
	try {
		const status = await getApi().atprotoIdentity();
		if (lookup !== blueskyPublicationLookup || !menuOpen.value) {
			return;
		}
		if (!status.publish) {
			return;
		}
		const accountDid = status.identity?.did || (status.external.connected ? status.external.did : undefined);
		if (accountDid === undefined || accountDid === '') {
			return;
		}
		const delivery = await getApi().atprotoPublication(localId.value, accountDid);
		if (lookup === blueskyPublicationLookup && menuOpen.value) {
			blueskyPublication.value = delivery.publication;
			blueskyDeliveryState.value = delivery.state;
			blueskyDeliveryAttempts.value = delivery.attempts;
		}
	} catch (error) {
		if (lookup === blueskyPublicationLookup && menuOpen.value) {
			blueskyPublicationError.value = error instanceof Error
				? error.message
				: t('card.bskyStatusFailed');
		}
	} finally {
		if (lookup === blueskyPublicationLookup) {
			blueskyPublicationLoading.value = false;
		}
	}
}

async function loadActivityPubDelivery(): Promise<void> {
	if (!isMine.value || localId.value === null) {
		activityPubDelivery.value = null;
		return;
	}
	const lookup = ++activityPubDeliveryLookup;
	activityPubDeliveryLoading.value = true;
	activityPubDeliveryError.value = '';
	try {
		const status = await getApi().activityPubDelivery(localId.value);
		if (lookup === activityPubDeliveryLookup && menuOpen.value) activityPubDelivery.value = status;
	} catch (error) {
		if (lookup === activityPubDeliveryLookup && menuOpen.value) {
			activityPubDeliveryError.value = error instanceof Error ? error.message : t('card.deliveryStatusFailed');
		}
	} finally {
		if (lookup === activityPubDeliveryLookup) activityPubDeliveryLoading.value = false;
	}
}

async function retryActivityPubDelivery(): Promise<void> {
	if (localId.value === null || retryingActivityPubDelivery.value || !activityPubDelivery.value?.retryable) return;
	retryingActivityPubDelivery.value = true;
	try {
		const result = await getApi().retryActivityPubDelivery(localId.value);
		activityPubDelivery.value = result;
		showToast(result.retried > 0 ? t(result.retried === 1 ? 'card.deliveryQueuedToastOne' : 'card.deliveryQueuedToast', { count: result.retried }) : t('card.deliveryNoRetry'), result.retried > 0 ? 'success' : 'info');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.deliveryRetryFailed'), 'error');
	} finally {
		retryingActivityPubDelivery.value = false;
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
		showToast(t('card.postDeleted'), 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('card.deleteFailed'), 'error');
	} finally {
		deleting.value = false;
		confirmingDelete.value = false;
		menuOpen.value = false;
	}
}

function deliveryRetryLabel(failed: number, attempts: number): string {
	const key = attempts > 0
		? (failed === 1 ? 'card.retryFailedOne' : 'card.retryFailed')
		: (failed === 1 ? 'card.retryFailedNoAttemptsOne' : 'card.retryFailedNoAttempts');
	return t(key, { count: failed, attempts: t('card.attempts', { count: attempts }) });
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
		void loadBlueskyPublication();
		void loadActivityPubDelivery();
	} else {
		blueskyPublicationLookup += 1;
		blueskyPublicationLoading.value = false;
		activityPubDeliveryLookup += 1;
		activityPubDeliveryLoading.value = false;
		document.removeEventListener('click', onDocumentClick);
	}
});

onUnmounted(() => {
	blueskyPublicationLookup += 1;
	activityPubDeliveryLookup += 1;
	viewportObserver?.disconnect();
	document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
	<article
		ref="rootEl"
		class="a-post"
		:class="{ 'a-post--detail': detail, 'a-post--boost': post.kind === 'boost' }"
		:aria-label="t('card.postBy', { handle })"
	>
		<p v-if="post.kind === 'boost' && post.boosted_by" class="a-post__boosted-by">
			<AppIcon name="repeat" :size="15" />
			<span><a :href="`#/u/${encodeURIComponent(post.boosted_by)}`">{{ handleLabel(post.boosted_by) }}</a> {{ t('card.boostedSuffix') }}</span>
		</p>

		<header class="a-post__header">
			<a :href="profileHref" class="a-post__avatar-link" :aria-label="t('card.profileOf', { name: displayName })">
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
					<span v-if="post.edited_at" class="a-post__edited" :title="t('card.editedTitle', { time: editedAbsolute })">· {{ t('card.edited') }}</span>
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
					:aria-label="t('card.moreActions')"
					:aria-expanded="menuOpen"
					@click.stop="menuOpen = !menuOpen"
				>
					<AppIcon name="more" :size="19" />
				</button>

				<div v-if="menuOpen" class="a-post__menu" role="menu">
					<button
						v-if="isMine && localId && activityPubDeliveryLoading"
						type="button" role="menuitem" disabled
					>
						<AppIcon name="refresh" :size="15" class="a-spin" /> {{ t('card.checkingDelivery') }}
					</button>
					<button
						v-if="isMine && localId && activityPubDelivery?.state === 'pending'"
						type="button" role="menuitem" disabled
					>
						<AppIcon name="refresh" :size="15" class="a-spin" /> {{ t(activityPubDelivery.pending === 1 ? 'card.queuedForOne' : 'card.queuedFor', { count: activityPubDelivery.pending }) }}
					</button>
					<p v-if="isMine && localId && activityPubDelivery?.state === 'failed' && activityPubDelivery.pending > 0" class="a-post__menu-error" role="status">
						{{ t(activityPubDelivery.pending === 1 ? 'card.deliveryStillQueuedOne' : 'card.deliveryStillQueued', { count: activityPubDelivery.pending }) }}
					</p>
					<button
						v-if="isMine && localId && activityPubDelivery?.state === 'failed' && activityPubDelivery.retryable"
						type="button" role="menuitem" :disabled="retryingActivityPubDelivery"
						@click.stop="retryActivityPubDelivery"
					>
						<AppIcon :name="retryingActivityPubDelivery ? 'refresh' : 'share'" :size="15" :class="{ 'a-spin': retryingActivityPubDelivery }" />
						{{ deliveryRetryLabel(activityPubDelivery.failed, activityPubDelivery.attempts) }}
					</button>
					<p v-if="isMine && localId && activityPubDelivery?.state === 'failed' && !activityPubDelivery.retryable" class="a-post__menu-error" role="status">
						{{ t(activityPubDelivery.failed === 1 ? 'card.deliveryFailedFinalOne' : 'card.deliveryFailedFinal', { count: activityPubDelivery.failed }) }}
					</p>
					<p v-if="isMine && localId && activityPubDeliveryError !== ''" class="a-post__menu-error" role="status">
						{{ activityPubDeliveryError }}
					</p>
					<button v-if="localId" type="button" role="menuitem" :disabled="bookmarking" @click.stop="toggleBookmark">
						<AppIcon name="bookmark" :size="15" />
						{{ t(bookmarked ? 'card.unbookmark' : 'card.bookmark') }}
					</button>
					<button type="button" role="menuitem" @click.stop="share">
						<AppIcon name="share" :size="15" /> {{ t('card.copyLink') }}
					</button>
					<button v-if="canQuote" type="button" role="menuitem" @click.stop="quote(); menuOpen = false">
						<AppIcon name="quote" :size="15" /> {{ t('card.quote') }}
					</button>
					<button
						v-if="localId"
						type="button"
						role="menuitem"
						@click.stop="collectionOpen = true; menuOpen = false"
					>
						<AppIcon name="list" :size="15" /> {{ t('card.addToCollection') }}
					</button>
					<button
						v-if="localId && post.revision !== undefined && post.revision > 1"
						type="button"
						role="menuitem"
						@click.stop="historyOpen = true; menuOpen = false"
					>
						<AppIcon name="clock" :size="15" /> {{ t('card.editHistory') }}
					</button>
					<button
						v-if="isMine && localId"
						type="button"
						role="menuitem"
						@click.stop="sourceOpen = true; menuOpen = false"
					>
						<AppIcon name="code" :size="15" /> {{ t('card.viewSource') }}
					</button>
					<button
						v-if="isMine && localId"
						type="button"
						role="menuitem"
						:disabled="pinning"
						@click.stop="togglePin"
					>
						<AppIcon :name="isPinned ? 'x' : 'pin'" :size="15" />
						{{ t(isPinned ? 'card.unpin' : 'card.pin') }}
					</button>
					<button
						v-if="isMine && localId"
						type="button"
						role="menuitem"
						:disabled="archiving"
						@click.stop="archive"
					>
						<AppIcon name="archive" :size="15" /> {{ t('card.archive') }}
					</button>
					<button
						v-if="forYou && localId"
						type="button"
						role="menuitem"
						@click.stop="sendFeedback('less')"
					>
						<AppIcon name="bellOff" :size="15" /> {{ t('card.showLess') }}
					</button>
					<button
						v-if="forYou && localId"
						type="button"
						role="menuitem"
						@click.stop="sendFeedback('more')"
					>
						<AppIcon name="sparkles" :size="15" /> {{ t('card.showMore') }}
					</button>
					<button
						v-if="canPublishToBluesky && blueskyPublicationLoading"
						type="button"
						role="menuitem"
						disabled
					>
						<AppIcon name="refresh" :size="15" class="a-spin" /> {{ t('card.checkingBsky') }}
					</button>
					<a
						v-if="canPublishToBluesky && !blueskyPublicationLoading && blueskyPublication !== null"
						:href="blueskyPublicationUrl"
						target="_blank"
						rel="noopener noreferrer"
						role="menuitem"
						@click="menuOpen = false"
					>
						<AppIcon name="check" :size="15" />
						{{ t(blueskyDeliveryState === 'published' ? 'card.bskyPublished' : 'card.bskyConfirmed') }}
					</a>
					<button
						v-if="canPublishToBluesky && !blueskyPublicationLoading && blueskyDeliveryState === 'pending'"
						type="button"
						role="menuitem"
						disabled
					>
						<AppIcon name="refresh" :size="15" class="a-spin" /> {{ t('card.bskyQueued') }}
					</button>
					<button
						v-if="canPublishToBluesky && !blueskyPublicationLoading && blueskyDeliveryState === 'failed'"
						type="button"
						role="menuitem"
						:disabled="publishingBluesky"
						@click.stop="publishToBluesky"
					>
						<AppIcon :name="publishingBluesky ? 'refresh' : 'share'" :size="15" :class="{ 'a-spin': publishingBluesky }" />
						{{ blueskyDeliveryAttempts > 0 ? t('card.bskyRetryAttempts', { count: blueskyDeliveryAttempts }) : t('card.bskyRetry') }}
					</button>
					<button
						v-if="canPublishToBluesky && !blueskyPublicationLoading && blueskyDeliveryState === 'not_queued'"
						type="button"
						role="menuitem"
						:disabled="publishingBluesky"
						@click.stop="publishToBluesky"
					>
						<AppIcon :name="publishingBluesky ? 'refresh' : 'share'" :size="15" :class="{ 'a-spin': publishingBluesky }" />
						{{ t('card.publishBsky') }}
					</button>
					<p v-if="canPublishToBluesky && blueskyPublicationError !== ''" class="a-post__menu-error" role="status">
						{{ blueskyPublicationError }}
					</p>
					<button v-if="canReport" type="button" role="menuitem" @click.stop="openReport">
						<AppIcon name="flag" :size="15" /> {{ t('card.report') }}
					</button>
					<a
						v-if="external"
						:href="external"
						target="_blank"
						rel="noopener noreferrer"
						role="menuitem"
						@click="menuOpen = false"
					>
						<AppIcon name="external" :size="15" /> {{ t('card.openOriginal') }}
					</a>
					<button
						v-if="canEdit"
						type="button"
						role="menuitem"
						@click.stop="edit"
					>
						<AppIcon name="pen" :size="15" /> {{ t('card.edit') }}
					</button>
					<button
						v-if="isMine"
						type="button"
						role="menuitem"
						class="a-post__menu-danger"
						@click.stop="confirmingDelete = true; menuOpen = false"
					>
						<AppIcon name="trash" :size="15" /> {{ t('card.delete') }}
					</button>
				</div>
			</div>
		</header>

		<p v-if="replyContext" class="a-post__reply-context">
			<AppIcon name="reply" :size="14" />
			<a :href="replyContext">{{ t(isRemotePost ? 'card.replyExternal' : 'card.replyToPost') }}</a>
		</p>

		<div v-if="confirmingDelete" class="a-post__confirm" role="alert">
			<span>{{ t('card.deleteConfirm') }}</span>
			<div>
				<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="deleting" @click="remove">
					{{ deleting ? t('card.deleting') : t('story.delete') }}
				</button>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="confirmingDelete = false">{{ t('common.cancel') }}</button>
			</div>
		</div>

		<details v-if="post.content_warning" class="a-post__cw">
			<summary>
				<AppIcon name="info" :size="14" />
				{{ t('scheduled.contentWarningLabel') }}: <strong>{{ post.content_warning }}</strong>
			</summary>
			<RichText :text="post.content" :allow-markup="post.is_remote === true" />
		</details>
		<RichText v-else :text="post.content" :allow-markup="post.is_remote === true" class="a-post__content" />

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
				<RichText v-else :text="quotedPost.content" :allow-markup="quotedPost.is_remote === true" class="a-quote-embed__text" />
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
						{{ quoteMedia(quotedPost)!.alt_text || t('card.audioAttachment') }}
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
			<p v-else-if="quoteLoading" class="a-quote-embed__missing">{{ t('card.quoteLoading') }}</p>
			<p v-else class="a-quote-embed__missing">
				{{ t('card.quoteMissing') }}
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
				{{ t(pollState.multiple ? 'card.pollMultiple' : 'card.poll') }}
				<span>{{ t(pollTotal === 1 ? 'card.vote' : 'card.votes', { count: pollTotal }) }}</span>
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
				<span v-if="pollState.voted">{{ t('card.youVoted') }}</span>
				<span v-else-if="pollState.expired || !pollOpen">{{ t('card.pollEnded') }}</span>
				<span v-else>{{ t('card.votersEnds', { count: pollState.voters_count, time: relativeTime(pollState.expires_at) }) }}</span>
				<button
					v-if="canVote"
					type="button"
					class="a-btn a-btn--primary a-btn--small"
					:disabled="selectedChoices.length === 0 || voting"
					@click="submitVote"
				>
					{{ voting ? t('common.saving') : t('card.voteSubmit') }}
				</button>
			</div>
			<p v-if="pollError" class="a-form-error" role="alert">{{ pollError }}</p>
		</div>

		<ul v-if="emojiReactions.length > 0" class="a-post__reactions">
			<li v-for="reaction in emojiReactions" :key="reaction.emoji" :title="t(reaction.count === 1 ? 'card.reactionsTitleOne' : 'card.reactionsTitle', { count: reaction.count })">
				<span>{{ reaction.emoji }}</span>
				<strong>{{ reaction.count }}</strong>
			</li>
		</ul>

		<footer class="a-post__actions">
			<button
				v-if="canReplyHere"
				type="button"
				class="a-post__action"
				:aria-label="replyCount > 0 ? `${t('card.replies')} (${replyCount})` : t('card.replies')"
				:title="replyCount > 0 ? `${t('card.replies')} (${replyCount})` : t('card.replies')"
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
				:aria-label="replyCount > 0 ? `${t('card.replyOriginal')} (${replyCount})` : t('card.replyOriginal')"
				:title="replyCount > 0 ? `${t('card.replyOriginal')} (${replyCount})` : t('card.replyOriginal')"
			>
				<AppIcon name="reply" :size="18" />
				<b v-if="replyCount > 0" class="a-post__action-count">{{ replyCount }}</b>
			</a>
			<span v-else class="a-post__action a-post__action--static" :title="t('card.repliesCount', { count: replyCount })">
				<AppIcon name="reply" :size="18" />
				<b v-if="replyCount > 0" class="a-post__action-count">{{ replyCount }}</b>
			</span>

			<button
				v-if="interactionId"
				type="button"
				class="a-post__action"
				:class="{ 'a-post__action--active': boosted }"
				:aria-label="t(boosted ? 'card.unrepost' : 'card.repost')"
				:title="t(boosted ? 'card.unboost' : 'card.boost')"
				:disabled="boosting"
				@click="toggleBoost"
			>
				<AppIcon name="repeat" :size="18" />
				<b v-if="boostCount > 0" class="a-post__action-count">{{ boostCount }}</b>
			</button>
			<span
				v-else
				class="a-post__action a-post__action--static"
				:title="t('card.boostsOn', { count: boostCount, handle })"
			>
				<AppIcon name="repeat" :size="18" />
				<b v-if="boostCount > 0" class="a-post__action-count">{{ boostCount }}</b>
			</span>

			<button
				v-if="canQuote"
				type="button"
				class="a-post__action"
				:aria-label="t('card.quote')"
				:title="t('card.quote')"
				@click="quote"
			>
				<AppIcon name="quote" :size="18" />
			</button>

			<button
				v-if="interactionId"
				type="button"
				class="a-post__action a-post__action--like"
				:class="{ 'a-post__action--liked': liked }"
				:aria-label="t(liked ? 'card.unlike' : 'card.like')"
				:aria-pressed="liked"
				:title="t(liked ? 'card.unlike' : 'card.like')"
				:disabled="liking"
				@click="toggleLike"
			>
				<AppIcon name="heart" :size="18" />
				<b v-if="likeCount > 0" class="a-post__action-count">{{ likeCount }}</b>
			</button>
			<span
				v-else
				class="a-post__action a-post__action--static"
				:title="t('card.likesOn', { count: likeCount, handle })"
			>
				<AppIcon name="heart" :size="18" />
				<b v-if="likeCount > 0" class="a-post__action-count">{{ likeCount }}</b>
			</span>

			<button
				v-if="localId && appState.session.user !== null"
				type="button"
				class="a-post__action"
				:class="{ 'a-post__action--active': bookmarked }"
				:aria-label="t(bookmarked ? 'card.unbookmark' : 'card.bookmark')"
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
				:aria-label="t('card.report')"
				:title="t('card.report')"
				@click="openReport"
			>
				<AppIcon name="flag" :size="18" />
			</button>

			<button type="button" class="a-post__action" :aria-label="t('card.shareLink')" :title="t('card.shareLink')" @click="share">
				<AppIcon name="share" :size="18" />
			</button>

			<a
				v-if="detailHref && !detail"
				:href="detailHref"
				class="a-post__action a-post__action--link"
				:aria-label="t('card.openPost')"
				:title="t('card.openPost')"
			>
				<AppIcon name="external" :size="17" />
			</a>
		</footer>
		<p v-if="atprotoMirrorProblem(atprotoMirrorStatus)" class="a-form-error a-post__mirror-status" role="status">
			{{ atprotoMirrorProblem(atprotoMirrorStatus) }}{{ atprotoMirrorStatus?.retryable ? t('card.mirrorRetryHint') : t('card.mirrorNoRetryHint') }}
			<button v-if="atprotoMirrorStatus.retryable" type="button" class="a-btn a-btn--small" :disabled="retryingAtprotoMirror" @click="retryAtprotoMirror">
				{{ retryingAtprotoMirror ? t('card.retrying') : t('card.retryMirror') }}
			</button>
		</p>

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

	<SourceDialog
		:open="sourceOpen"
		:post-id="localId ?? ''"
		@close="sourceOpen = false"
	/>

	<AddToCollectionDialog
		:open="collectionOpen"
		:post-id="localId ?? ''"
		@close="collectionOpen = false"
	/>
</template>
