<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, nextTick, ref, watch } from 'vue';
import { useHostBridge } from '@alohasocial/ui';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import { accountName, handleLabel, isRemoteId, postReplyTargetId, postRouteId, truncate } from '../lib/format';
import { getApi } from '../lib/api';
import { hostDisplayName, resolveAccount } from '../lib/account';
import { announcePostCreated, announcePostUpdated } from '../lib/events';
import { appState, closeComposer, showToast } from '../lib/state';
import type { Attachment, Draft, TimelineItem } from '../lib/types';

const MAX_LENGTH = 5000;
const MAX_WARNING = 200;
const MAX_FILES = 10;
type MediaKind = 'image' | 'video' | 'audio';
const ACCEPTED_TYPES: Record<string, MediaKind> = {
	'image/jpeg': 'image',
	'image/png': 'image',
	'image/webp': 'image',
	'image/gif': 'image',
	'video/mp4': 'video',
	'video/webm': 'video',
	'audio/mpeg': 'audio',
	'audio/mp4': 'audio',
	'audio/ogg': 'audio',
	'audio/wav': 'audio',
};
const MAX_BYTES: Record<MediaKind, number> = {
	image: 5 * 1024 * 1024,
	video: 20 * 1024 * 1024,
	audio: 10 * 1024 * 1024,
};

interface PendingImage {
	key: number;
	file: File;
	kind: MediaKind;
	preview: string;
	alt: string;
}

const dialog = ref<HTMLDialogElement | null>(null);
const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const hostBridge = useHostBridge();
const canUploadMedia = computed(() => (
	appState.session.user !== null && hostBridge.capabilities().media
));
const blueskyStatusChecked = ref(false);
const blueskyConnected = ref(false);
const blueskyAccounts = ref<Array<{ did: string; handle: string }>>([]);
const selectedBlueskyDid = ref('');
const crossPostBluesky = ref(false);

const content = ref('');
const contentWarning = ref('');
const showWarning = ref(false);
const visibility = ref<'public' | 'unlisted' | 'followers'>('public');
const images = ref<PendingImage[]>([]);
const existing = ref<Attachment[]>([]);
const submitting = ref(false);
const error = ref('');
const scheduledAt = ref('');
const scheduleOpen = ref(false);
const showPoll = ref(false);
const pollOptions = ref<string[]>(['', '']);
const pollMultiple = ref(false);
const pollHideTotals = ref(false);
const pollExpiresIn = ref('86400');
const restoredAttachmentIds = ref<string[]>([]);
const savingDraft = ref(false);
let imageSequence = 0;
let draftTimer: number | null = null;

const editPost = computed(() => appState.composer.editPost);
const isEdit = computed(() => editPost.value !== null);
const replyTo = computed(() => appState.composer.replyTo);
const isReply = computed(() => replyTo.value !== null);
const quoteTo = computed(() => appState.composer.quoteTo);
const isQuote = computed(() => quoteTo.value !== null);
const preloadedDraft = computed<Draft | null>(() => appState.composer.draft);
const directTo = computed(() => appState.composer.directTo);
const isDirect = computed(() => directTo.value !== null);
const canCrossPostBluesky = computed(() => (
	!isEdit.value
	&& !isReply.value
	&& !isQuote.value
	&& !isDirect.value
	&& !showPoll.value
	&& scheduledAt.value === ''
	&& visibility.value === 'public'
));
const showBlueskyCrossPost = computed(() => (
	appState.session.user !== null
	&& hostBridge.capabilities().atproto
	&& blueskyStatusChecked.value
	&& blueskyConnected.value
	&& canCrossPostBluesky.value
));
const showBlueskyConnectionHint = computed(() => (
	appState.session.user !== null
	&& hostBridge.capabilities().atproto
	&& blueskyStatusChecked.value
	&& !blueskyConnected.value
	&& canCrossPostBluesky.value
));
const hasMediaAttachments = computed(() => (
	images.value.length > 0 || existing.value.length > 0 || restoredAttachmentIds.value.length > 0
));
const directHandle = computed(() => directTo.value === null ? '' : directTo.value.handle);
const directName = computed(() => {
	const account = directTo.value;
	if (account === null) {
		return '';
	}

	return account.display_name !== '' ? account.display_name : account.handle;
});
const quoteHandle = computed(() => quoteTo.value === null ? '' : handleLabel(quoteTo.value.object_author_id ?? quoteTo.value.author_id));
const quoteTargetId = computed(() => {
	const target = quoteTo.value;
	if (target === null) {
		return null;
	}

	return target.object_id ?? target.id;
});
const title = computed(() => (
	isEdit.value ? 'Edit post' : isDirect.value ? 'New message' : isQuote.value ? 'Quote post' : isReply.value ? 'Write a reply' : 'New post'
));
const submitLabel = computed(() => {
	if (submitting.value) {
		return isEdit.value ? 'Saving…' : 'Sending…';
	}
	if (crossPostBluesky.value) {
		return 'Post & Bluesky';
	}

	if (scheduledAt.value !== '') {
		return 'Schedule';
	}

	return isEdit.value ? 'Save' : isDirect.value ? 'Send' : isReply.value ? 'Reply' : isQuote.value ? 'Quote' : 'Post';
});
const replyAuthor = computed(() => replyTo.value === null ? '' : displayAuthor(replyTo.value));
const replyHandle = computed(() => replyTo.value === null ? '' : handleLabel(replyTo.value.object_author_id ?? replyTo.value.author_id));
const replyId = computed(() => replyTo.value === null ? null : postReplyTargetId(replyTo.value));
const isRemoteReply = computed(() => replyId.value !== null && isRemoteId(replyId.value));
// A remote parent this deployment cannot address (an at:// record, a
// deleted or restricted target) leaves no reply id; say so instead of
// leaving the submit button silently disabled.
const replyTargetUnavailable = computed(() => isReply.value && replyId.value === null);

const length = computed(() => Array.from(content.value).length);
const warningLength = computed(() => Array.from(contentWarning.value).length);
const overLimit = computed(() => length.value > MAX_LENGTH);
const warningOverLimit = computed(() => warningLength.value > MAX_WARNING);
const pollReady = computed(() => (
	!showPoll.value
	|| (pollOptions.value.filter((option) => option.trim() !== '').length >= 2
		&& pollOptions.value.filter((option) => option.trim() !== '').length === pollOptions.value.length)
));
const scheduleDate = computed(() => {
	if (scheduledAt.value === '') {
		return null;
	}

	const date = new Date(scheduledAt.value);
	return Number.isNaN(date.getTime()) ? null : date;
});
const scheduleReady = computed(() => (
	scheduledAt.value === '' || (scheduleDate.value !== null && scheduleDate.value.getTime() > Date.now() + 30_000)
));
const canSubmit = computed(() => (
	appState.session.user !== null
	&& (canUploadMedia.value || !hasMediaAttachments.value)
	&& (content.value.trim() !== '' || images.value.length > 0 || restoredAttachmentIds.value.length > 0 || (showPoll.value && pollReady.value))
	&& !overLimit.value
	&& !warningOverLimit.value
	&& !submitting.value
	&& pollReady.value
	&& scheduleReady.value
	&& (isReply.value ? replyId.value !== null : true)
	&& (isQuote.value ? quoteTargetId.value !== null : true)
	&& (isDirect.value ? directTo.value !== null : true)
	&& !(scheduledAt.value !== '' && showPoll.value)
));

const currentHandle = computed(() => appState.session.user?.handle ?? '');

function displayAuthor(post: TimelineItem): string {
	const id = post.object_author_id ?? post.author_id;
	if (appState.session.user?.handle === id) {
		return appState.session.user.displayName || appState.session.profile?.display_name || handleLabel(id);
	}

	return hostDisplayName(id) || handleLabel(id);
}

function draftKey(): string {
	return `aloha.draft.v1.${currentHandle.value}`;
}

function draftContext(): string {
	if (replyId.value !== null) {
		return `reply:${replyId.value}`;
	}
	if (quoteTargetId.value !== null) {
		return `quote:${quoteTargetId.value}`;
	}

	return '';
}

function populateDraft(draft: Draft): void {
	resetImages();
	resetExtras();
	existing.value = [];
	restoredAttachmentIds.value = [...draft.attachment_ids];
	content.value = draft.content;
	contentWarning.value = draft.content_warning ?? '';
	showWarning.value = (draft.content_warning ?? '') !== '';
	visibility.value = draft.visibility === 'unlisted' || draft.visibility === 'followers'
		? draft.visibility
		: 'public';

	if (draft.poll !== null) {
		showPoll.value = true;
		pollOptions.value = draft.poll.options.length >= 2 ? [...draft.poll.options] : ['', ''];
		pollMultiple.value = draft.poll.multiple;
		pollHideTotals.value = draft.poll.hide_totals;
		pollExpiresIn.value = String(draft.poll.expires_in);
	}
}

function saveDraft(): void {
	if (isReply.value || isEdit.value || currentHandle.value === '') {
		return;
	}

	if (draftTimer !== null) {
		window.clearTimeout(draftTimer);
	}

	draftTimer = window.setTimeout(() => {
		try {
			if (content.value.trim() === '' && contentWarning.value.trim() === '' && images.value.length === 0) {
				localStorage.removeItem(draftKey());
			} else {
				localStorage.setItem(draftKey(), JSON.stringify({
					content: content.value,
					contentWarning: contentWarning.value,
					showWarning: showWarning.value,
					visibility: visibility.value,
				}));
			}
		} catch {
			// Draft persistence is a convenience, not a requirement.
		}
	}, 250);
}

function restoreDraft(): void {
	resetExtras();

	restoredAttachmentIds.value = [];
	existing.value = [];

	if (isReply.value || isEdit.value || isQuote.value || isDirect.value) {
		content.value = '';
		contentWarning.value = '';
		showWarning.value = false;
		visibility.value = 'public';
		return;
	}

	try {
		const raw = localStorage.getItem(draftKey());
		if (raw === null) {
			return;
		}
		const parsed = JSON.parse(raw) as Record<string, unknown>;
		if (typeof parsed.content === 'string') {
			content.value = parsed.content;
		}
		if (typeof parsed.contentWarning === 'string') {
			contentWarning.value = parsed.contentWarning;
		}
		if (parsed.showWarning === true) {
			showWarning.value = true;
		}
		if (parsed.visibility === 'public' || parsed.visibility === 'unlisted' || parsed.visibility === 'followers') {
			visibility.value = parsed.visibility;
		}
	} catch {
		// A malformed or unavailable draft just starts an empty composer.
	}
}

function clearDraft(): void {
	try {
		localStorage.removeItem(draftKey());
	} catch {
		// Nothing to clear when storage is unavailable.
	}
}

watch([content, contentWarning, showWarning, visibility], saveDraft, { deep: true });

function populateEditor(post: TimelineItem): void {
	resetImages();
	resetExtras();
	existing.value = [...(post.attachments ?? [])];
	content.value = post.content;
	contentWarning.value = post.content_warning ?? '';
	showWarning.value = post.content_warning !== null && post.content_warning !== '';
	visibility.value = post.visibility === 'unlisted' || post.visibility === 'followers'
		? post.visibility
		: 'public';
}

function resetExtras(): void {
	restoredAttachmentIds.value = [];
	scheduledAt.value = '';
	scheduleOpen.value = false;
	showPoll.value = false;
	pollOptions.value = ['', ''];
	pollMultiple.value = false;
	pollHideTotals.value = false;
	pollExpiresIn.value = '86400';
	crossPostBluesky.value = false;
}

let blueskyStatusRequest = 0;
watch(() => appState.composer.open, (open) => {
	const request = ++blueskyStatusRequest;
	blueskyStatusChecked.value = false;
	blueskyConnected.value = false;
	blueskyAccounts.value = [];
	selectedBlueskyDid.value = '';
	crossPostBluesky.value = false;
	if (!open || appState.session.user === null || !hostBridge.capabilities().atproto) {
		return;
	}

	void getApi().atprotoIdentity().then((status) => {
		if (request !== blueskyStatusRequest) {
			return;
		}
		blueskyAccounts.value = [
			...(status.identity === null ? [] : [{ did: status.identity.did, handle: status.identity.handle }]),
			...(status.external.connected && status.external.did && status.external.handle
				? [{ did: status.external.did, handle: status.external.handle }] : []),
		];
		blueskyConnected.value = status.publish && blueskyAccounts.value.length > 0;
		selectedBlueskyDid.value = blueskyAccounts.value[0]?.did ?? '';
		blueskyStatusChecked.value = true;
	}).catch(() => {
		if (request === blueskyStatusRequest) {
			blueskyConnected.value = false;
			blueskyStatusChecked.value = true;
		}
	});
});

watch(canCrossPostBluesky, (eligible) => {
	if (!eligible) {
		crossPostBluesky.value = false;
	}
});

function addPollOption(): void {
	if (pollOptions.value.length < 4) {
		pollOptions.value.push('');
	}
}

function removePollOption(index: number): void {
	if (pollOptions.value.length <= 2) {
		return;
	}

	pollOptions.value.splice(index, 1);
}

function togglePoll(): void {
	if (isQuote.value || isRemoteReply.value) {
		return;
	}

	showPoll.value = !showPoll.value;
	if (showPoll.value) {
		scheduleOpen.value = false;
		scheduledAt.value = '';
	}
}

function toggleSchedule(): void {
	if (isQuote.value || isRemoteReply.value) {
		return;
	}

	scheduleOpen.value = !scheduleOpen.value;
	if (scheduleOpen.value) {
		showPoll.value = false;
	} else {
		scheduledAt.value = '';
	}
}

watch(() => appState.composer.open, async (open) => {
	if (open) {
		error.value = '';
		if (editPost.value !== null) {
			populateEditor(editPost.value);
		} else if (preloadedDraft.value !== null) {
			populateDraft(preloadedDraft.value);
		} else {
			existing.value = [];
			restoreDraft();
		}
		await nextTick();
		if (dialog.value !== null && !dialog.value.open) {
			dialog.value.showModal();
		}
		window.setTimeout(() => textarea.value?.focus(), 60);
		if (replyTo.value !== null) {
			void resolveAccount(replyTo.value.object_author_id ?? replyTo.value.author_id);
		}
		if (quoteTo.value !== null) {
			void resolveAccount(quoteTo.value.object_author_id ?? quoteTo.value.author_id);
		}
	} else if (dialog.value?.open) {
		dialog.value.close();
	}
}, { immediate: false });

function requestClose(): void {
	if (submitting.value) {
		return;
	}

	closeComposer();
}

function addFiles(event: Event): void {
	const input = event.target as HTMLInputElement;
	const selected = Array.from(input.files ?? []);
	input.value = '';
	if (!canUploadMedia.value) {
		showToast('Media uploads are not configured on this instance.', 'error');
		return;
	}

	for (const file of selected) {
		if (images.value.length + existing.value.length >= MAX_FILES) {
			showToast(`Up to ${MAX_FILES} attachments per post.`, 'error');
			break;
		}
		const kind = ACCEPTED_TYPES[file.type];
		if (kind === undefined) {
			showToast('Supported formats: JPEG, PNG, WebP, GIF, MP4/WebM, MP3, M4A, Ogg and WAV.', 'error');
			continue;
		}
		if (file.size > MAX_BYTES[kind]) {
			showToast(`A ${kind === 'image' ? 'image' : kind === 'video' ? 'video' : 'audio file'} may be at most ${MAX_BYTES[kind] / 1024 / 1024} MiB.`, 'error');
			continue;
		}

		images.value.push({
			key: ++imageSequence,
			file,
			kind,
			preview: URL.createObjectURL(file),
			alt: '',
		});
	}
}

function removeImage(key: number): void {
	const index = images.value.findIndex((image) => image.key === key);
	if (index === -1) {
		return;
	}

	URL.revokeObjectURL(images.value[index].preview);
	images.value.splice(index, 1);
}

function removeExisting(id: string): void {
	existing.value = existing.value.filter((attachment) => attachment.id !== id);
}

function removeRestoredAttachments(): void {
	restoredAttachmentIds.value = [];
}

function resetImages(): void {
	for (const image of images.value) {
		URL.revokeObjectURL(image.preview);
	}
	images.value = [];
}

async function saveAsDraft(): Promise<void> {
	if (savingDraft.value || appState.session.user === null) {
		return;
	}
	if (isRemoteReply.value) {
		showToast('Replies to external posts cannot be saved as drafts.', 'error');
		return;
	}
	if (!canUploadMedia.value && hasMediaAttachments.value) {
		showToast('Remove the media attachments before saving; media uploads are unavailable on this instance.', 'error');
		return;
	}

	const pending = images.value.length;
	if (
		content.value.trim() === ''
		&& pending === 0
		&& restoredAttachmentIds.value.length === 0
		&& !showPoll.value
	) {
		showToast('The draft is empty.', 'error');
		return;
	}

	savingDraft.value = true;
	try {
		const uploaded: string[] = [];
		for (const image of images.value) {
			uploaded.push((await getApi().uploadMedia(image.file, image.alt.trim())).id);
		}

		const options = pollOptions.value.map((option) => option.trim()).filter((option) => option !== '');
		const draft = await getApi().saveDraft({
			context: draftContext(),
			content: content.value,
			content_warning: showWarning.value && contentWarning.value.trim() !== '' ? contentWarning.value : null,
			visibility: visibility.value,
			attachment_ids: [...restoredAttachmentIds.value, ...uploaded],
			in_reply_to_id: replyId.value,
			quoted_post_id: isQuote.value ? quoteTargetId.value : null,
			poll: showPoll.value && options.length >= 2
				? {
					options,
					multiple: pollMultiple.value,
					hide_totals: pollHideTotals.value,
					expires_in: Number(pollExpiresIn.value),
				}
				: null,
		});

		appState.composer.draft = draft;
		clearDraft();
		resetImages();
		showToast('Draft saved. You can find it under “Drafts”.', 'success');
		closeComposer();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The draft could not be saved.', 'error');
	} finally {
		savingDraft.value = false;
	}
}

function discardSavedDraft(): void {
	const draft = appState.composer.draft;
	if (draft === null) {
		return;
	}

	appState.composer.draft = null;
	void getApi().deleteDraft(draft.id).catch(() => {
		// The post is already published; a leftover draft is harmless.
	});
}

async function submit(): Promise<void> {
	error.value = '';
	if (!canUploadMedia.value && hasMediaAttachments.value) {
		error.value = 'Remove the media attachments before publishing; media uploads are unavailable on this instance.';
		return;
	}

	if (!canSubmit.value) {
		if (content.value.trim() === '' && images.value.length === 0 && restoredAttachmentIds.value.length === 0) {
			error.value = 'A post needs text, media or a poll.';
		}
		return;
	}

	submitting.value = true;

	try {
		const attachments: Attachment[] = [];
		for (const image of images.value) {
			attachments.push(await getApi().uploadMedia(image.file, image.alt.trim()));
		}

		const editing = editPost.value;
		if (editing !== null) {
			const updated = await getApi().updatePost(editing.id, {
				content: content.value,
				visibility: visibility.value,
				content_warning: showWarning.value ? contentWarning.value : null,
				attachment_ids: [
					...existing.value.map((attachment) => attachment.id),
					...attachments.map((attachment) => attachment.id),
				],
				revision: editing.revision,
			});

			announcePostUpdated(updated);
			resetImages();
			existing.value = [];
			closeComposer();
			showToast('Post updated.', 'success');
			return;
		}

		const replying = isReply.value;
		const attachmentIds = [...restoredAttachmentIds.value, ...attachments.map((attachment) => attachment.id)];

		if (scheduledAt.value !== '') {
			if (scheduleDate.value === null) {
				throw new Error('Please choose a valid time.');
			}

			const scheduled = await getApi().createScheduledPost({
				content: content.value,
				visibility: visibility.value,
				content_warning: showWarning.value ? contentWarning.value : null,
				in_reply_to_id: replyId.value,
				attachment_ids: attachmentIds,
				scheduled_at: scheduleDate.value.toISOString(),
			});

			clearDraft();
			discardSavedDraft();
			resetImages();
			existing.value = [];
			content.value = '';
			contentWarning.value = '';
			showWarning.value = false;
			resetExtras();
			closeComposer();
			showToast(
				`Post scheduled for ${new Date(scheduled.scheduled_at).toLocaleString()}.`,
				'success',
			);
			return;
		}

		if (showPoll.value) {
			const options = pollOptions.value.map((option) => option.trim()).filter((option) => option !== '');
			if (options.length < 2) {
				throw new Error('A poll needs at least two options.');
			}

			const post = await getApi().createStatus({
				status: content.value,
				visibility: visibility.value,
				spoiler_text: showWarning.value ? contentWarning.value : null,
				media_ids: attachmentIds,
				in_reply_to_id: replyId.value,
				poll: {
					options,
					expires_in: Number(pollExpiresIn.value),
					multiple: pollMultiple.value,
					hide_totals: pollHideTotals.value,
				},
			});

			announcePostCreated(post);
			clearDraft();
			discardSavedDraft();
			resetImages();
			existing.value = [];
			content.value = '';
			contentWarning.value = '';
			showWarning.value = false;
			resetExtras();
			closeComposer();
			showToast('Post saved. Delivery to other servers runs in the background.', 'success');
			return;
		}

		const direct = isDirect.value && directTo.value !== null;
		const quoting = isQuote.value;
		const post = await getApi().createPost({
			content: content.value,
			visibility: direct ? 'direct' : visibility.value,
			content_warning: showWarning.value ? contentWarning.value : null,
			in_reply_to_id: replyId.value,
			quoted_post_id: isQuote.value ? quoteTargetId.value : null,
			attachment_ids: attachmentIds,
			recipient_ids: direct && directTo.value !== null ? [directTo.value.id] : [],
		});
		const publishOnBluesky = crossPostBluesky.value;

		announcePostCreated(post);
		clearDraft();
		discardSavedDraft();
		resetImages();
		existing.value = [];
		content.value = '';
		contentWarning.value = '';
		showWarning.value = false;
		visibility.value = 'public';
		resetExtras();
		closeComposer();
		if (publishOnBluesky) {
			try {
			await getApi().publishToBluesky(post.id, selectedBlueskyDid.value || undefined);
				showToast('Post saved here and published to Bluesky.', 'success');
			} catch (caught) {
				const reason = caught instanceof Error ? ` ${caught.message}` : '';
				showToast(`Post saved here, but Bluesky publishing failed.${reason} Retry from the post menu.`, 'error');
			}
		} else {
			showToast(
				direct
					? 'Message saved. Delivery to the recipient’s server runs in the background.'
					: replying
						? 'Reply saved. Delivery to other servers runs in the background.'
						: quoting
							? 'Quote saved. Delivery to other servers runs in the background.'
							: 'Post saved. Delivery to other servers runs in the background.',
				'success',
			);
		}
	} catch (caught) {
		error.value = caught instanceof Error
			? caught.message
			: isEdit.value
				? 'The post could not be saved.'
				: 'The post could not be published.';
	} finally {
		submitting.value = false;
	}
}
</script>

<template>
	<dialog ref="dialog" class="a-modal a-composer" aria-labelledby="a-composer-title" @cancel.prevent="requestClose">
		<div class="a-modal__panel">
			<header class="a-modal__head">
				<button type="button" class="a-icon-btn" aria-label="Close composer" @click="requestClose">
					<AppIcon name="x" :size="20" />
				</button>
				<h2 id="a-composer-title">{{ title }}</h2>
				<span class="a-modal__head-spacer" />
			</header>

			<div v-if="replyTo" class="a-composer__reply">
				<Avatar :id="replyTo.object_author_id ?? replyTo.author_id" :name="replyAuthor" :size="30" />
				<div>
					<strong>{{ replyAuthor }}</strong>
					<span v-if="replyAuthor !== replyHandle">{{ replyHandle }}</span>
					<p>{{ truncate(replyTo.content, 160) }}</p>
					<p v-if="replyTargetUnavailable" class="a-composer__hint">
						This post can't be replied to from here.
					</p>
				</div>
			</div>

			<div v-if="directTo" class="a-composer__direct">
				<Avatar :id="directTo.id" :name="directName" :src="directTo.avatar" :size="30" />
				<div>
					<strong>message to {{ directName }}</strong>
					<span>{{ directHandle }} · visible only to this account</span>
				</div>
			</div>

			<div v-if="quoteTo" class="a-composer__quote">
				<p class="a-composer__quote-head">
					<AppIcon name="quote" :size="15" />
					<span>You are quoting <strong>{{ quoteHandle }}</strong></span>
				</p>
				<p class="a-composer__quote-text">{{ truncate(quoteTo.content, 240) }}</p>
				<p v-if="quoteTo.quote_state === 'pending'" class="a-composer__hint">
					Remote posts may still be awaiting approval; the post will be published anyway.
				</p>
			</div>

			<form class="a-composer__body" @submit.prevent="submit">
				<div class="a-composer__identity">
					<Avatar
						:id="appState.session.user?.handle ?? 'aloha'"
						:name="appState.session.user?.displayName || appState.session.profile?.display_name"
						:size="38"
					/>
					<span>Post as <strong>@{{ appState.session.user?.handle ?? 'Guest' }}</strong></span>
				</div>

				<textarea
					ref="textarea"
					v-model="content"
					class="a-composer__textarea"
					rows="5"
					maxlength="5200"
					placeholder="What's on your mind?"
					aria-label="Post text"
				/>

				<div v-if="showWarning" class="a-composer__cw">
					<label for="a-composer-cw">Content warning</label>
					<input
						id="a-composer-cw"
						v-model="contentWarning"
						type="text"
						maxlength="220"
						placeholder="What is it about? (optional)"
					/>
					<span :class="{ 'a-count--over': warningOverLimit }">{{ warningLength }} / {{ MAX_WARNING }}</span>
				</div>

				<div v-if="scheduleOpen && !isEdit" class="a-composer__extras">
					<div class="a-composer__extras-head">
						<AppIcon name="calendar" :size="16" />
						<strong>Schedule post</strong>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="scheduleOpen = false; scheduledAt = ''">
							Remove
						</button>
					</div>
					<label class="a-composer__field">
						<span>Publish at</span>
						<input v-model="scheduledAt" type="datetime-local" />
					</label>
					<p v-if="scheduledAt !== '' && !scheduleReady" class="a-form-error" role="alert">
						The time must be in the future.
					</p>
					<p class="a-composer__hint">
						Scheduled posts cannot contain a poll.
					</p>
				</div>

				<div v-if="showPoll && !isEdit" class="a-composer__extras">
					<div class="a-composer__extras-head">
						<AppIcon name="hash" :size="16" />
						<strong>poll</strong>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="showPoll = false">
							Remove
						</button>
					</div>
					<label v-for="(option, index) in pollOptions" :key="index" class="a-composer__field a-composer__poll-option">
						<span>Reply {{ index + 1 }}</span>
						<input v-model="pollOptions[index]" type="text" maxlength="50" :placeholder="`Reply ${index + 1}`" />
						<button
							v-if="pollOptions.length > 2"
							type="button"
							class="a-icon-btn"
							:aria-label="`Remove option ${index + 1}`"
							@click="removePollOption(index)"
						>
							<AppIcon name="x" :size="16" />
						</button>
					</label>
					<button v-if="pollOptions.length < 4" type="button" class="a-btn a-btn--ghost a-btn--small" @click="addPollOption">
						<AppIcon name="plus" :size="14" /> Add option
					</button>
					<div class="a-composer__poll-settings">
						<label class="a-composer__field">
							<span>Duration</span>
							<select v-model="pollExpiresIn">
								<option value="300">5 minutes</option>
								<option value="3600">1 hour</option>
								<option value="86400">1 Tag</option>
								<option value="259200">3 days</option>
								<option value="604800">7 days</option>
							</select>
						</label>
						<label class="a-composer__check">
							<input v-model="pollMultiple" type="checkbox" />
							<span>Multiple choice</span>
						</label>
						<label class="a-composer__check">
							<input v-model="pollHideTotals" type="checkbox" />
							<span>Results only after the poll closes</span>
						</label>
					</div>
				</div>

				<p v-if="restoredAttachmentIds.length > 0" class="a-composer__hint">
					{{ restoredAttachmentIds.length }} saved {{ restoredAttachmentIds.length === 1 ? 'attachment' : 'attachments' }} from the draft will be sent too.
					<button v-if="!canUploadMedia" type="button" class="a-btn a-btn--ghost a-btn--small" @click="removeRestoredAttachments">
						Remove saved attachments
					</button>
				</p>
				<div v-if="images.length > 0 || existing.length > 0" class="a-composer__images">
					<figure v-for="attachment in existing" :key="attachment.id" class="a-composer__image">
						<img
							v-if="attachment.media_type === 'image'"
							:src="attachment.url"
							:alt="attachment.alt_text ?? ''"
						/>
						<video
							v-else-if="attachment.media_type === 'video' || attachment.mime_type.startsWith('video/')"
							:src="attachment.url"
							:poster="attachment.thumbnail_url ?? undefined"
							muted
							playsinline
							preload="metadata"
						/>
						<span v-else class="a-composer__audio">
							<AppIcon :name="attachment.mime_type.startsWith('audio/') ? 'music' : 'image'" :size="18" />
							{{ attachment.mime_type.startsWith('audio/') ? 'Audio' : 'attachment' }}
						</span>
						<button
							type="button"
							class="a-composer__image-remove"
							:aria-label="`${attachment.media_type === 'image' ? 'Image' : 'attachment'} removed`"
							@click="removeExisting(attachment.id)"
						>
							<AppIcon name="x" :size="15" />
						</button>
						<span class="a-composer__image-note">Existing attachment</span>
					</figure>
					<figure v-for="(image, index) in images" :key="image.key" class="a-composer__image">
						<video
							v-if="image.kind === 'video'"
							:src="image.preview"
							muted
							playsinline
							preload="metadata"
						/>
						<span v-else-if="image.kind === 'audio'" class="a-composer__audio">
							<AppIcon name="music" :size="22" />
							{{ image.file.name }}
						</span>
						<img v-else :src="image.preview" :alt="image.alt || `Image ${index + 1}`" />
						<button type="button" class="a-composer__image-remove" aria-label="Remove attachment" @click="removeImage(image.key)">
							<AppIcon name="x" :size="15" />
						</button>
						<label>
							<span>{{ image.kind === 'image' ? `Alt text for image ${index + 1}` : `Description for attachment ${index + 1}` }}</span>
							<input
								v-model="image.alt"
								type="text"
								maxlength="1000"
								placeholder="Describe the attachment for people who cannot see it."
							/>
						</label>
					</figure>
				</div>

				<p v-if="error" class="a-form-error" role="alert">{{ error }}</p>

				<div class="a-composer__tools">
					<input
						ref="fileInput"
						class="a-visually-hidden"
						type="file"
						accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,audio/mpeg,audio/mp4,audio/ogg,audio/wav"
						multiple
						:disabled="!canUploadMedia"
						@change="addFiles"
					/>
					<button
						type="button"
						class="a-icon-btn"
						:disabled="!canUploadMedia || images.length + existing.length >= MAX_FILES || submitting"
						:aria-label="canUploadMedia ? 'Add images, video or audio' : 'Media uploads are unavailable'"
						:title="canUploadMedia ? 'Add media' : 'Media uploads are not configured on this instance'"
						@click="fileInput?.click()"
					>
						<AppIcon name="image" :size="20" />
					</button>
					<p v-if="!canUploadMedia" class="a-composer__hint">
						Media uploads are not configured on this instance.
					</p>
					<button
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': showWarning }"
						aria-label="Add content warning"
						title="Content warning"
						@click="showWarning = !showWarning"
					>
						<AppIcon name="info" :size="20" />
					</button>
					<button
						v-if="!isEdit && !isQuote && !isDirect && !isRemoteReply"
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': showPoll }"
						aria-label="Add poll"
						title="poll"
						@click="togglePoll"
					>
						<AppIcon name="hash" :size="20" />
					</button>
					<button
						v-if="!isEdit && !isQuote && !isDirect && !isRemoteReply"
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': scheduleOpen }"
						aria-label="Schedule post"
						title="Schedule post"
						@click="toggleSchedule"
					>
						<AppIcon name="calendar" :size="20" />
					</button>
					<label v-if="isDirect" class="a-composer__visibility">
						<AppIcon name="lock" :size="16" />
						<span>Direct message</span>
					</label>
					<label v-else class="a-composer__visibility">
						<AppIcon name="globe" :size="16" />
						<select v-model="visibility" aria-label="Visibility">
							<option value="public">Public</option>
							<option value="unlisted">Unlisted</option>
							<option value="followers">Followers only</option>
						</select>
					</label>
					<label v-if="showBlueskyCrossPost" class="a-composer__visibility a-composer__cross-post">
						<input v-model="crossPostBluesky" type="checkbox" :disabled="submitting" />
						<span>Also publish to Bluesky</span>
					</label>
			<label v-if="showBlueskyCrossPost && blueskyAccounts.length > 1" class="a-composer__visibility">
				<span>Bluesky account</span>
				<select v-model="selectedBlueskyDid" :disabled="submitting" aria-label="Bluesky account">
					<option v-for="account in blueskyAccounts" :key="account.did" :value="account.did">{{ account.handle }}</option>
				</select>
			</label>
					<p v-else-if="showBlueskyConnectionHint" class="a-composer__hint" role="status">
						Connect a Bluesky account in Settings to publish to both networks.
					</p>
					<span class="a-composer__count" :class="{ 'a-count--over': overLimit }">
						{{ length }} / {{ MAX_LENGTH }}
					</span>
					<button
						v-if="!isEdit && !isDirect && !isRemoteReply"
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="savingDraft || submitting"
						@click="saveAsDraft"
					>
						<AppIcon name="pen" :size="15" />
						{{ savingDraft ? 'Saving…' : 'draft' }}
					</button>
					<button type="submit" class="a-btn a-btn--primary" :disabled="!canSubmit">
						<AppIcon v-if="submitting" name="refresh" :size="16" class="a-spin" />
						{{ submitLabel }}
					</button>
				</div>
			</form>
		</div>
	</dialog>
</template>
