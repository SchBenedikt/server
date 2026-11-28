<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, nextTick, ref, watch } from 'vue';
import { useHostBridge } from '@alohasocial/ui';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import RichText from './RichText.vue';
import { accountName, handleLabel, isRemoteId, postReplyTargetId, postRouteId, truncate } from '../lib/format';
import { getApi, loadErrorMessage } from '../lib/api';
import { hostDisplayName, resolveAccount } from '../lib/account';
import { announcePostCreated, announcePostUpdated } from '../lib/events';
import { t } from '../lib/i18n';
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
const postLanguage = ref('');
const images = ref<PendingImage[]>([]);
const existing = ref<Attachment[]>([]);
const submitting = ref(false);
const error = ref('');
const scheduledAt = ref('');
const scheduleOpen = ref(false);
const showPoll = ref(false);
const showPreview = ref(false);
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
const languageOptions = computed(() => {
	const configured = appState.instance?.languages ?? [];
	return postLanguage.value !== '' && !configured.includes(postLanguage.value)
		? [...configured, postLanguage.value]
		: configured;
});
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
const title = computed(() => t(isEdit.value
	? 'composer.titleEdit'
	: isDirect.value
		? 'composer.titleDirect'
		: isQuote.value
			? 'composer.titleQuote'
			: isReply.value
				? 'composer.titleReply'
				: 'composer.titleNew'));
const submitLabel = computed(() => {
	if (submitting.value) {
		return t(isEdit.value ? 'common.saving' : 'composer.sending');
	}
	if (crossPostBluesky.value) {
		return t('composer.postBsky');
	}

	if (scheduledAt.value !== '') {
		return t('composer.scheduleLabel');
	}

	return t(isEdit.value
		? 'common.save'
		: isDirect.value
			? 'composer.send'
			: isReply.value
				? 'composer.reply'
				: isQuote.value
					? 'composer.quote'
					: 'composer.post');
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
	postLanguage.value = draft.language ?? '';

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
					language: postLanguage.value,
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
		if (typeof parsed.language === 'string') {
			postLanguage.value = parsed.language;
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

watch([content, contentWarning, showWarning, visibility, postLanguage], saveDraft, { deep: true });

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
	postLanguage.value = post.language ?? '';
}

function resetExtras(): void {
	restoredAttachmentIds.value = [];
	postLanguage.value = '';
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

interface Suggestion {
	insert: string;
	label: string;
	sub: string;
}

const caretPos = ref(0);
const suggestKind = ref<'mention' | 'tag' | null>(null);
const suggestQuery = ref('');
const suggestStart = ref(0);
const suggestions = ref<Suggestion[]>([]);
const suggestIndex = ref(0);
let suggestTimer: number | null = null;
let suggestRequest = 0;

const suggestOpen = computed(() => suggestKind.value !== null && suggestions.value.length > 0);

/**
 * The `@partial` or `#partial` token ending at the caret, if any. Only a
 * whitespace boundary counts, so `mail@x` or `C#` never trigger.
 */
function tokenAtCaret(): { kind: 'mention' | 'tag'; query: string; start: number } | null {
	const before = content.value.slice(0, caretPos.value);
	const match = /(?:^|\s)([@#])([\p{L}\p{N}._-]*)$/u.exec(before);
	if (match === null) {
		return null;
	}

	return {
		kind: match[1] === '@' ? 'mention' : 'tag',
		query: match[2],
		start: before.length - match[0].length + (match[0].startsWith(match[1]) ? 0 : 1),
	};
}

function updateSuggestions(): void {
	const token = tokenAtCaret();
	if (token === null || token.query === '') {
		suggestKind.value = null;
		suggestions.value = [];
		return;
	}

	suggestKind.value = token.kind;
	suggestQuery.value = token.query;
	suggestStart.value = token.start;
	suggestIndex.value = 0;

	if (suggestTimer !== null) {
		window.clearTimeout(suggestTimer);
	}
	const request = ++suggestRequest;
	suggestTimer = window.setTimeout(() => {
		void loadSuggestions(token.kind, token.query, request);
	}, 200);
}

async function loadSuggestions(kind: 'mention' | 'tag', query: string, request: number): Promise<void> {
	try {
		if (kind === 'mention') {
			const data = await getApi().remoteSearch(query, 'accounts', 'all');
			if (request !== suggestRequest || suggestQuery.value !== query) {
				return;
			}
			suggestions.value = data.accounts.slice(0, 6).map((account) => ({
				insert: `@${account.handle}`,
				label: account.display_name !== '' ? account.display_name : account.handle,
				sub: `@${account.handle}`,
			}));
		} else {
			const data = await getApi().search(`#${query}`, 6);
			if (request !== suggestRequest || suggestQuery.value !== query) {
				return;
			}
			suggestions.value = data.hashtags.slice(0, 6).map((tag) => ({
				insert: `#${tag.name}`,
				label: `#${tag.name}`,
				sub: '',
			}));
		}
	} catch {
		if (request === suggestRequest) {
			suggestions.value = [];
		}
	}
}

function closeSuggestions(): void {
	suggestKind.value = null;
	suggestions.value = [];
	if (suggestTimer !== null) {
		window.clearTimeout(suggestTimer);
		suggestTimer = null;
	}
}

function pickSuggestion(index: number): void {
	const suggestion = suggestions.value[index];
	if (suggestion === undefined || suggestKind.value === null) {
		return;
	}

	const caret = caretPos.value;
	const next = content.value.slice(0, suggestStart.value) + suggestion.insert + ' ' + content.value.slice(caret);
	content.value = next;
	caretPos.value = suggestStart.value + suggestion.insert.length + 1;
	closeSuggestions();
	void nextTick(() => {
		textarea.value?.focus();
		textarea.value?.setSelectionRange(caretPos.value, caretPos.value);
	});
}

function onTextareaKeydown(event: KeyboardEvent): void {
	if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
		event.preventDefault();
		void submit();
		return;
	}

	if (!suggestOpen.value) {
		return;
	}

	if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
		event.preventDefault();
		const delta = event.key === 'ArrowDown' ? 1 : -1;
		suggestIndex.value = (suggestIndex.value + delta + suggestions.value.length) % suggestions.value.length;
		return;
	}

	if (event.key === 'Enter' || event.key === 'Tab') {
		event.preventDefault();
		pickSuggestion(suggestIndex.value);
		return;
	}

	if (event.key === 'Escape') {
		event.preventDefault();
		event.stopPropagation();
		closeSuggestions();
	}
}

function onTextareaCaret(): void {
	caretPos.value = textarea.value?.selectionStart ?? content.value.length;
	updateSuggestions();
}

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
		showToast(t('composer.mediaNotConfigured'), 'error');
		return;
	}

	for (const file of selected) {
		if (images.value.length + existing.value.length >= MAX_FILES) {
			showToast(t('composer.maxAttachments', { count: MAX_FILES }), 'error');
			break;
		}
		const kind = ACCEPTED_TYPES[file.type];
		if (kind === undefined) {
			showToast(t('composer.badFormats'), 'error');
			continue;
		}
		if (file.size > MAX_BYTES[kind]) {
			showToast(t('composer.tooLarge', {
				kind: t(kind === 'image' ? 'composer.kindImage' : kind === 'video' ? 'composer.kindVideo' : 'composer.kindAudio'),
				size: MAX_BYTES[kind] / 1024 / 1024,
			}), 'error');
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

function moveExisting(index: number, delta: number): void {
	const target = index + delta;
	if (target < 0 || target >= existing.value.length) {
		return;
	}

	const [attachment] = existing.value.splice(index, 1);
	existing.value.splice(target, 0, attachment);
}

function moveImage(key: number, delta: number): void {
	const index = images.value.findIndex((image) => image.key === key);
	const target = index + delta;
	if (index < 0 || target < 0 || target >= images.value.length) {
		return;
	}

	const [image] = images.value.splice(index, 1);
	images.value.splice(target, 0, image);
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
		showToast(t('composer.replyDraftBlocked'), 'error');
		return;
	}
	if (!canUploadMedia.value && hasMediaAttachments.value) {
		showToast(t('composer.removeMediaForDraft'), 'error');
		return;
	}

	const pending = images.value.length;
	if (
		content.value.trim() === ''
		&& pending === 0
		&& restoredAttachmentIds.value.length === 0
		&& !showPoll.value
	) {
		showToast(t('composer.draftEmpty'), 'error');
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
			language: postLanguage.value === '' ? null : postLanguage.value,
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
		showToast(t('composer.draftSaved', { drafts: t('composer.draftsName') }), 'success');
		closeComposer();
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('composer.draftFailed')), 'error');
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
		error.value = t('composer.removeMediaForPublish');
		return;
	}

	if (!canSubmit.value) {
		if (content.value.trim() === '' && images.value.length === 0 && restoredAttachmentIds.value.length === 0) {
			error.value = t('composer.needContent');
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
				language: postLanguage.value === '' ? null : postLanguage.value,
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
			showToast(t('composer.updated'), 'success');
			return;
		}

		const replying = isReply.value;
		const attachmentIds = [...restoredAttachmentIds.value, ...attachments.map((attachment) => attachment.id)];

		if (scheduledAt.value !== '') {
			if (scheduleDate.value === null) {
				throw new Error(t('composer.invalidTime'));
			}

			const pollOptionsClean = pollOptions.value.map((option) => option.trim()).filter((option) => option !== '');
			const scheduled = await getApi().createScheduledPost({
				content: content.value,
				visibility: visibility.value,
				content_warning: showWarning.value ? contentWarning.value : null,
				language: postLanguage.value === '' ? null : postLanguage.value,
				in_reply_to_id: replyId.value,
				attachment_ids: attachmentIds,
				scheduled_at: scheduleDate.value.toISOString(),
				poll: showPoll.value && pollOptionsClean.length >= 2
					? {
						options: pollOptionsClean,
						expires_in: Number(pollExpiresIn.value),
						multiple: pollMultiple.value,
						hide_totals: pollHideTotals.value,
					}
					: null,
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
			showToast(t('composer.scheduledFor', { time: new Date(scheduled.scheduled_at).toLocaleString() }), 'success');
			return;
		}

		if (showPoll.value) {
			const options = pollOptions.value.map((option) => option.trim()).filter((option) => option !== '');
			if (options.length < 2) {
				throw new Error(t('composer.pollMinOptions'));
			}

			const post = await getApi().createStatus({
				status: content.value,
				visibility: visibility.value,
				spoiler_text: showWarning.value ? contentWarning.value : null,
				language: postLanguage.value === '' ? null : postLanguage.value,
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
			showToast(t('composer.savedBg'), 'success');
			return;
		}

		const direct = isDirect.value && directTo.value !== null;
		const quoting = isQuote.value;
		const post = await getApi().createPost({
			content: content.value,
			visibility: direct ? 'direct' : visibility.value,
			content_warning: showWarning.value ? contentWarning.value : null,
			language: postLanguage.value === '' ? null : postLanguage.value,
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
				showToast(t('composer.savedBsky'), 'success');
			} catch (caught) {
				const reason = caught instanceof Error ? ` ${caught.message}` : '';
				showToast(t('composer.bskyFailed', { reason }), 'error');
			}
		} else {
			showToast(t(direct
				? 'composer.msgSaved'
				: replying
					? 'composer.replySaved'
					: quoting
						? 'composer.quoteSaved'
						: 'composer.savedBg'), 'success');
		}
	} catch (caught) {
		error.value = caught instanceof Error
			? caught.message
			: isEdit.value
				? t('composer.saveFailed')
				: t('composer.publishFailed');
	} finally {
		submitting.value = false;
	}
}
</script>

<template>
	<dialog ref="dialog" class="a-modal a-composer" aria-labelledby="a-composer-title" @cancel.prevent="requestClose">
		<div class="a-modal__panel">
			<header class="a-modal__head">
				<button type="button" class="a-icon-btn" :aria-label="t('composer.close')" @click="requestClose">
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
						{{ t('composer.replyUnavailable') }}
					</p>
				</div>
			</div>

			<div v-if="directTo" class="a-composer__direct">
				<Avatar :id="directTo.id" :name="directName" :src="directTo.avatar" :size="30" />
				<div>
					<strong>{{ t('composer.msgTo', { name: directName }) }}</strong>
					<span>{{ directHandle }} · {{ t('composer.visibleOnly') }}</span>
				</div>
			</div>

			<div v-if="quoteTo" class="a-composer__quote">
				<p class="a-composer__quote-head">
					<AppIcon name="quote" :size="15" />
					<span>{{ t('composer.quoting') }} <strong>{{ quoteHandle }}</strong></span>
				</p>
				<p class="a-composer__quote-text">{{ truncate(quoteTo.content, 240) }}</p>
				<p v-if="quoteTo.quote_state === 'pending'" class="a-composer__hint">
					{{ t('composer.quotePending') }}
				</p>
			</div>

			<form class="a-composer__body" @submit.prevent="submit">
				<div class="a-composer__identity">
					<Avatar
						:id="appState.session.user?.handle ?? 'aloha'"
						:name="appState.session.user?.displayName || appState.session.profile?.display_name"
						:size="38"
					/>
					<span>{{ t('composer.postAs') }} <strong>@{{ appState.session.user?.handle ?? t('composer.guest') }}</strong></span>
				</div>

				<div class="a-composer__field-wrap">
					<textarea
						ref="textarea"
						v-model="content"
						class="a-composer__textarea"
						rows="5"
						maxlength="5200"
						:placeholder="t('composer.placeholder')"
						:aria-label="t('composer.postTextAria')"
						@input="onTextareaCaret"
						@keyup="onTextareaCaret"
						@click="onTextareaCaret"
						@select="onTextareaCaret"
						@keydown="onTextareaKeydown"
					/>
					<ul v-if="suggestOpen" class="a-composer__suggest" role="listbox">
						<li
							v-for="(suggestion, index) in suggestions"
							:key="suggestion.insert"
							role="option"
							:aria-selected="index === suggestIndex"
							class="a-composer__suggest-item"
							:class="{ 'a-composer__suggest-item--active': index === suggestIndex }"
							@mousedown.prevent="pickSuggestion(index)"
							@mouseenter="suggestIndex = index"
						>
							<strong>{{ suggestion.label }}</strong>
							<span v-if="suggestion.sub !== ''" class="a-composer__suggest-sub">{{ suggestion.sub }}</span>
						</li>
					</ul>
				</div>

				<div v-if="showPreview" class="a-composer__preview" aria-live="polite">
					<p class="a-composer__preview-head">{{ t('composer.preview') }}</p>
					<p v-if="contentWarning.trim() !== ''" class="a-composer__preview-cw">
						<AppIcon name="info" :size="13" />
						{{ t('composer.cwPreview') }} <strong>{{ contentWarning }}</strong>
					</p>
					<div v-if="content.trim() !== ''" class="a-composer__preview-body">
						<RichText :text="content" />
					</div>
					<p v-else class="a-composer__preview-empty">{{ t('composer.previewEmpty') }}</p>
					<ul v-if="showPoll" class="a-composer__preview-poll">
						<li v-for="(option, index) in pollOptions.filter((entry) => entry.trim() !== '')" :key="index">
							{{ option }}
						</li>
					</ul>
					<div v-if="existing.length + images.length > 0" class="a-composer__preview-media">
						<template v-for="attachment in existing" :key="attachment.id">
							<img
								v-if="attachment.media_type === 'image'"
								:src="attachment.thumbnail_url ?? attachment.url"
								:alt="attachment.alt_text ?? ''"
							/>
							<span v-else class="a-composer__preview-media-note">
								<AppIcon :name="attachment.mime_type.startsWith('audio/') ? 'music' : 'image'" :size="15" />
								{{ attachment.media_type }}
							</span>
						</template>
						<template v-for="image in images" :key="image.key">
							<img v-if="image.kind === 'image'" :src="image.preview" :alt="image.alt" />
							<span v-else class="a-composer__preview-media-note">
								<AppIcon :name="image.kind === 'audio' ? 'music' : 'image'" :size="15" />
								{{ image.kind }}
							</span>
						</template>
					</div>
				</div>

				<div v-if="showWarning" class="a-composer__cw">
					<label for="a-composer-cw">{{ t('composer.cwLabel') }}</label>
					<input
						id="a-composer-cw"
						v-model="contentWarning"
						type="text"
						maxlength="220"
						:placeholder="t('composer.cwPlaceholder')"
					/>
					<span :class="{ 'a-count--over': warningOverLimit }">{{ warningLength }} / {{ MAX_WARNING }}</span>
				</div>

				<div v-if="scheduleOpen && !isEdit" class="a-composer__extras">
					<div class="a-composer__extras-head">
						<AppIcon name="calendar" :size="16" />
						<strong>{{ t('composer.scheduleTitle') }}</strong>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="scheduleOpen = false; scheduledAt = ''">
							{{ t('common.remove') }}
						</button>
					</div>
					<label class="a-composer__field">
						<span>{{ t('composer.publishAt') }}</span>
						<input v-model="scheduledAt" type="datetime-local" />
					</label>
					<p v-if="scheduledAt !== '' && !scheduleReady" class="a-form-error" role="alert">
						{{ t('composer.timeFuture') }}
					</p>
					<p class="a-composer__hint">
						{{ t('composer.pollFromSchedule') }}
					</p>
				</div>

				<div v-if="showPoll && !isEdit" class="a-composer__extras">
					<div class="a-composer__extras-head">
						<AppIcon name="hash" :size="16" />
						<strong>{{ t('composer.pollTitle') }}</strong>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="showPoll = false">
							{{ t('common.remove') }}
						</button>
					</div>
					<label v-for="(option, index) in pollOptions" :key="index" class="a-composer__field a-composer__poll-option">
						<span>{{ t('composer.pollOption', { n: index + 1 }) }}</span>
						<input v-model="pollOptions[index]" type="text" maxlength="50" :placeholder="t('composer.pollOption', { n: index + 1 })" />
						<button
							v-if="pollOptions.length > 2"
							type="button"
							class="a-icon-btn"
							:aria-label="t('composer.removeOption', { n: index + 1 })"
							@click="removePollOption(index)"
						>
							<AppIcon name="x" :size="16" />
						</button>
					</label>
					<button v-if="pollOptions.length < 4" type="button" class="a-btn a-btn--ghost a-btn--small" @click="addPollOption">
						<AppIcon name="plus" :size="14" /> {{ t('composer.addOption') }}
					</button>
					<div class="a-composer__poll-settings">
						<label class="a-composer__field">
							<span>{{ t('composer.durationLabel') }}</span>
							<select v-model="pollExpiresIn">
								<option v-for="secs in [300, 3600, 86400, 259200, 604800]" :key="secs" :value="secs">
									{{ t(`poll.duration.${secs}`) }}
								</option>
							</select>
						</label>
						<label class="a-composer__check">
							<input v-model="pollMultiple" type="checkbox" />
							<span>{{ t('poll.multiple') }}</span>
						</label>
						<label class="a-composer__check">
							<input v-model="pollHideTotals" type="checkbox" />
							<span>{{ t('poll.hideTotals') }}</span>
						</label>
					</div>
				</div>

				<p v-if="restoredAttachmentIds.length > 0" class="a-composer__hint">
					{{ t(restoredAttachmentIds.length === 1 ? 'composer.restoredOne' : 'composer.restoredMany', { count: restoredAttachmentIds.length }) }}
					<button v-if="!canUploadMedia" type="button" class="a-btn a-btn--ghost a-btn--small" @click="removeRestoredAttachments">
						{{ t('composer.removeSaved') }}
					</button>
				</p>
				<div v-if="images.length > 0 || existing.length > 0" class="a-composer__images">
					<figure v-for="(attachment, index) in existing" :key="attachment.id" class="a-composer__image">
						<span v-if="existing.length > 1" class="a-composer__image-order">
							<button
								type="button"
								class="a-btn a-btn--ghost a-btn--small"
								:disabled="index === 0"
								:aria-label="t('composer.moveEarlier')"
								@click="moveExisting(index, -1)"
							>
								<AppIcon name="arrowLeft" :size="13" />
							</button>
							<button
								type="button"
								class="a-btn a-btn--ghost a-btn--small"
								:disabled="index === existing.length - 1"
								:aria-label="t('composer.moveLater')"
								@click="moveExisting(index, 1)"
							>
								<AppIcon name="arrowRight" :size="13" />
							</button>
						</span>
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
							{{ t(attachment.mime_type.startsWith('audio/') ? 'composer.audio' : 'composer.attachment') }}
						</span>
						<button
							type="button"
							class="a-composer__image-remove"
							:aria-label="t('composer.removedAria', { label: t(attachment.media_type === 'image' ? 'composer.image' : 'composer.attachment') })"
							@click="removeExisting(attachment.id)"
						>
							<AppIcon name="x" :size="15" />
						</button>
						<span class="a-composer__image-note">{{ t('composer.existingAttachment') }}</span>
					</figure>
					<figure v-for="(image, index) in images" :key="image.key" class="a-composer__image">
						<span v-if="images.length > 1" class="a-composer__image-order">
							<button
								type="button"
								class="a-btn a-btn--ghost a-btn--small"
								:disabled="index === 0"
								:aria-label="t('composer.moveEarlier')"
								@click="moveImage(image.key, -1)"
							>
								<AppIcon name="arrowLeft" :size="13" />
							</button>
							<button
								type="button"
								class="a-btn a-btn--ghost a-btn--small"
								:disabled="index === images.length - 1"
								:aria-label="t('composer.moveLater')"
								@click="moveImage(image.key, 1)"
							>
								<AppIcon name="arrowRight" :size="13" />
							</button>
						</span>
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
						<button type="button" class="a-composer__image-remove" :aria-label="t('composer.removeAttachment')" @click="removeImage(image.key)">
							<AppIcon name="x" :size="15" />
						</button>
						<label>
							<span>{{ t(image.kind === 'image' ? 'composer.altImage' : 'composer.descAttachment', { n: index + 1 }) }}</span>
							<input
								v-model="image.alt"
								type="text"
								maxlength="1000"
								:placeholder="t('composer.altPlaceholder')"
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
						:aria-label="t(canUploadMedia ? 'composer.addMediaAria' : 'composer.mediaUnavailableAria')"
						:title="t(canUploadMedia ? 'composer.addMedia' : 'composer.addMediaUnavailable')"
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
						:aria-label="t('composer.addCw')"
						:title="t('composer.cwLabel')"
						@click="showWarning = !showWarning"
					>
						<AppIcon name="info" :size="20" />
					</button>
					<button
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': showPreview }"
						:aria-label="t('composer.previewPost')"
						:title="t('composer.preview')"
						@click="showPreview = !showPreview"
					>
						<AppIcon name="eye" :size="20" />
					</button>
					<button
						v-if="!isEdit && !isQuote && !isDirect && !isRemoteReply"
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': showPoll }"
						:aria-label="t('composer.addPoll')"
						:title="t('composer.pollTitle')"
						@click="togglePoll"
					>
						<AppIcon name="hash" :size="20" />
					</button>
					<button
						v-if="!isEdit && !isQuote && !isDirect && !isRemoteReply"
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': scheduleOpen }"
						:aria-label="t('composer.scheduleAria')"
						:title="t('composer.scheduleTitle')"
						@click="toggleSchedule"
					>
						<AppIcon name="calendar" :size="20" />
					</button>
					<label v-if="isDirect" class="a-composer__visibility">
						<AppIcon name="lock" :size="16" />
						<span>{{ t('composer.directMessage') }}</span>
					</label>
					<label v-else class="a-composer__visibility">
						<AppIcon name="globe" :size="16" />
						<select v-model="visibility" :aria-label="t('composer.visibilityAria')">
							<option value="public">{{ t('visibility.public') }}</option>
							<option value="unlisted">{{ t('visibility.unlisted') }}</option>
							<option value="followers">{{ t('visibility.followers') }}</option>
						</select>
					</label>
					<label v-if="languageOptions.length > 0 && !isDirect" class="a-composer__visibility">
						<span>{{ t('composer.language') }}</span>
						<select v-model="postLanguage" :aria-label="t('composer.language')">
							<option value="">{{ t('composer.auto') }}</option>
							<option v-for="language in languageOptions" :key="language" :value="language">{{ language }}</option>
						</select>
					</label>
					<label v-if="showBlueskyCrossPost" class="a-composer__visibility a-composer__cross-post">
						<input v-model="crossPostBluesky" type="checkbox" :disabled="submitting" />
						<span>{{ t('composer.alsoBsky') }}</span>
					</label>
			<label v-if="showBlueskyCrossPost && blueskyAccounts.length > 1" class="a-composer__visibility">
				<span>{{ t('composer.bskyAccount') }}</span>
				<select v-model="selectedBlueskyDid" :disabled="submitting" :aria-label="t('composer.bskyAccount')">
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
						{{ savingDraft ? t('common.saving') : t('composer.draftButton') }}
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
