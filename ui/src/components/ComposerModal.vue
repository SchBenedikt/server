<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, nextTick, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import { accountName, handleLabel, postRouteId, truncate } from '../lib/format';
import { getApi } from '../lib/api';
import { hostDisplayName, resolveAccount } from '../lib/account';
import { announcePostCreated, announcePostUpdated } from '../lib/events';
import { appState, closeComposer, showToast } from '../lib/state';
import type { Attachment, TimelineItem } from '../lib/types';

const MAX_LENGTH = 5000;
const MAX_WARNING = 200;
const MAX_FILES = 4;
const MAX_FILE_BYTES = 5 * 1024 * 1024;
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

interface PendingImage {
	key: number;
	file: File;
	preview: string;
	alt: string;
}

const dialog = ref<HTMLDialogElement | null>(null);
const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

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
let imageSequence = 0;
let draftTimer: number | null = null;

const editPost = computed(() => appState.composer.editPost);
const isEdit = computed(() => editPost.value !== null);
const replyTo = computed(() => appState.composer.replyTo);
const isReply = computed(() => replyTo.value !== null);
const quoteTo = computed(() => appState.composer.quoteTo);
const isQuote = computed(() => quoteTo.value !== null);
const directTo = computed(() => appState.composer.directTo);
const isDirect = computed(() => directTo.value !== null);
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
	isEdit.value ? 'Beitrag bearbeiten' : isDirect.value ? 'Neue Nachricht' : isQuote.value ? 'Beitrag zitieren' : isReply.value ? 'Antwort schreiben' : 'Neuer Beitrag'
));
const submitLabel = computed(() => {
	if (submitting.value) {
		return isEdit.value ? 'Wird gespeichert…' : 'Wird gesendet…';
	}

	if (scheduledAt.value !== '') {
		return 'Planen';
	}

	return isEdit.value ? 'Speichern' : isDirect.value ? 'Senden' : isReply.value ? 'Antworten' : isQuote.value ? 'Zitieren' : 'Posten';
});
const replyAuthor = computed(() => replyTo.value === null ? '' : displayAuthor(replyTo.value));
const replyHandle = computed(() => replyTo.value === null ? '' : handleLabel(replyTo.value.object_author_id ?? replyTo.value.author_id));
const replyId = computed(() => replyTo.value === null ? null : postRouteId(replyTo.value));

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
	&& content.value.trim() !== ''
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
	scheduledAt.value = '';
	scheduleOpen.value = false;
	showPoll.value = false;
	pollOptions.value = ['', ''];
	pollMultiple.value = false;
	pollHideTotals.value = false;
	pollExpiresIn.value = '86400';
}

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
	if (isQuote.value) {
		return;
	}

	showPoll.value = !showPoll.value;
	if (showPoll.value) {
		scheduleOpen.value = false;
		scheduledAt.value = '';
	}
}

function toggleSchedule(): void {
	if (isQuote.value) {
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

	for (const file of selected) {
		if (images.value.length + existing.value.length >= MAX_FILES) {
			showToast(`Höchstens ${MAX_FILES} Bilder pro Beitrag.`, 'error');
			break;
		}
		if (!ACCEPTED_TYPES.includes(file.type)) {
			showToast('Nur JPEG, PNG und WebP sind erlaubt.', 'error');
			continue;
		}
		if (file.size > MAX_FILE_BYTES) {
			showToast('Ein Bild darf höchstens 5 MiB groß sein.', 'error');
			continue;
		}

		images.value.push({
			key: ++imageSequence,
			file,
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

function resetImages(): void {
	for (const image of images.value) {
		URL.revokeObjectURL(image.preview);
	}
	images.value = [];
}

async function submit(): Promise<void> {
	error.value = '';

	if (!canSubmit.value) {
		if (content.value.trim() === '') {
			error.value = 'Ein Beitrag braucht Text.';
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
			showToast('Beitrag aktualisiert.', 'success');
			return;
		}

		const replying = isReply.value;
		const attachmentIds = attachments.map((attachment) => attachment.id);

		if (scheduledAt.value !== '') {
			if (scheduleDate.value === null) {
				throw new Error('Bitte wähle einen gültigen Zeitpunkt.');
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
			resetImages();
			existing.value = [];
			content.value = '';
			contentWarning.value = '';
			showWarning.value = false;
			resetExtras();
			closeComposer();
			showToast(
				`Beitrag für ${new Date(scheduled.scheduled_at).toLocaleString('de-DE')} geplant.`,
				'success',
			);
			return;
		}

		if (showPoll.value) {
			const options = pollOptions.value.map((option) => option.trim()).filter((option) => option !== '');
			if (options.length < 2) {
				throw new Error('Eine Umfrage braucht mindestens zwei Antworten.');
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
			resetImages();
			existing.value = [];
			content.value = '';
			contentWarning.value = '';
			showWarning.value = false;
			resetExtras();
			closeComposer();
			showToast('Beitrag mit Umfrage veröffentlicht.', 'success');
			return;
		}

		const direct = isDirect.value && directTo.value !== null;
		const post = await getApi().createPost({
			content: content.value,
			visibility: direct ? 'direct' : visibility.value,
			content_warning: showWarning.value ? contentWarning.value : null,
			in_reply_to_id: replyId.value,
			quoted_post_id: isQuote.value ? quoteTargetId.value : null,
			attachment_ids: attachmentIds,
			recipient_ids: direct && directTo.value !== null ? [directTo.value.id] : [],
		});

		announcePostCreated(post);
		clearDraft();
		resetImages();
		existing.value = [];
		content.value = '';
		contentWarning.value = '';
		showWarning.value = false;
		visibility.value = 'public';
		resetExtras();
		closeComposer();
		showToast(
			direct
				? 'Nachricht gesendet.'
				: replying ? 'Antwort veröffentlicht.' : isQuote.value ? 'Zitat veröffentlicht.' : 'Beitrag veröffentlicht.',
			'success',
		);
	} catch (caught) {
		error.value = caught instanceof Error
			? caught.message
			: isEdit.value
				? 'Der Beitrag konnte nicht gespeichert werden.'
				: 'Der Beitrag konnte nicht veröffentlicht werden.';
	} finally {
		submitting.value = false;
	}
}
</script>

<template>
	<dialog ref="dialog" class="a-modal a-composer" aria-labelledby="a-composer-title" @cancel.prevent="requestClose">
		<div class="a-modal__panel">
			<header class="a-modal__head">
				<button type="button" class="a-icon-btn" aria-label="Composer schließen" @click="requestClose">
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
				</div>
			</div>

			<div v-if="directTo" class="a-composer__direct">
				<Avatar :id="directTo.id" :name="directName" :src="directTo.avatar" :size="30" />
				<div>
					<strong>Nachricht an {{ directName }}</strong>
					<span>{{ directHandle }} · nur für dieses Konto sichtbar</span>
				</div>
			</div>

			<div v-if="quoteTo" class="a-composer__quote">
				<p class="a-composer__quote-head">
					<AppIcon name="quote" :size="15" />
					<span>Du zitierst <strong>{{ quoteHandle }}</strong></span>
				</p>
				<p class="a-composer__quote-text">{{ truncate(quoteTo.content, 240) }}</p>
				<p v-if="quoteTo.quote_state === 'pending'" class="a-composer__hint">
					Bei entfernten Beiträgen kann die Freigabe noch ausstehen; der Beitrag wird trotzdem veröffentlicht.
				</p>
			</div>

			<form class="a-composer__body" @submit.prevent="submit">
				<div class="a-composer__identity">
					<Avatar
						:id="appState.session.user?.handle ?? 'aloha'"
						:name="appState.session.user?.displayName || appState.session.profile?.display_name"
						:size="38"
					/>
					<span>Beitrag als <strong>@{{ appState.session.user?.handle ?? 'Gast' }}</strong></span>
				</div>

				<textarea
					ref="textarea"
					v-model="content"
					class="a-composer__textarea"
					rows="5"
					maxlength="5200"
					placeholder="Was beschäftigt dich gerade?"
					aria-label="Beitragstext"
				/>

				<div v-if="showWarning" class="a-composer__cw">
					<label for="a-composer-cw">Inhaltswarnung</label>
					<input
						id="a-composer-cw"
						v-model="contentWarning"
						type="text"
						maxlength="220"
						placeholder="Worum geht es? (optional)"
					/>
					<span :class="{ 'a-count--over': warningOverLimit }">{{ warningLength }} / {{ MAX_WARNING }}</span>
				</div>

				<div v-if="scheduleOpen && !isEdit" class="a-composer__extras">
					<div class="a-composer__extras-head">
						<AppIcon name="calendar" :size="16" />
						<strong>Beitrag planen</strong>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="scheduleOpen = false; scheduledAt = ''">
							Entfernen
						</button>
					</div>
					<label class="a-composer__field">
						<span>Veröffentlichen am</span>
						<input v-model="scheduledAt" type="datetime-local" />
					</label>
					<p v-if="scheduledAt !== '' && !scheduleReady" class="a-form-error" role="alert">
						Der Zeitpunkt muss in der Zukunft liegen.
					</p>
					<p class="a-composer__hint">
						Geplante Beiträge können keine Umfrage enthalten.
					</p>
				</div>

				<div v-if="showPoll && !isEdit" class="a-composer__extras">
					<div class="a-composer__extras-head">
						<AppIcon name="hash" :size="16" />
						<strong>Umfrage</strong>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="showPoll = false">
							Entfernen
						</button>
					</div>
					<label v-for="(option, index) in pollOptions" :key="index" class="a-composer__field a-composer__poll-option">
						<span>Antwort {{ index + 1 }}</span>
						<input v-model="pollOptions[index]" type="text" maxlength="50" :placeholder="`Antwort ${index + 1}`" />
						<button
							v-if="pollOptions.length > 2"
							type="button"
							class="a-icon-btn"
							:aria-label="`Antwort ${index + 1} entfernen`"
							@click="removePollOption(index)"
						>
							<AppIcon name="x" :size="16" />
						</button>
					</label>
					<button v-if="pollOptions.length < 4" type="button" class="a-btn a-btn--ghost a-btn--small" @click="addPollOption">
						<AppIcon name="plus" :size="14" /> Antwort hinzufügen
					</button>
					<div class="a-composer__poll-settings">
						<label class="a-composer__field">
							<span>Laufzeit</span>
							<select v-model="pollExpiresIn">
								<option value="300">5 Minuten</option>
								<option value="3600">1 Stunde</option>
								<option value="86400">1 Tag</option>
								<option value="259200">3 Tage</option>
								<option value="604800">7 Tage</option>
							</select>
						</label>
						<label class="a-composer__check">
							<input v-model="pollMultiple" type="checkbox" />
							<span>Mehrfachauswahl</span>
						</label>
						<label class="a-composer__check">
							<input v-model="pollHideTotals" type="checkbox" />
							<span>Ergebnisse erst nach Ablauf</span>
						</label>
					</div>
				</div>

				<div v-if="images.length > 0 || existing.length > 0" class="a-composer__images">
					<figure v-for="attachment in existing" :key="attachment.id" class="a-composer__image">
						<img
							v-if="attachment.media_type === 'image'"
							:src="attachment.url"
							:alt="attachment.alt_text ?? ''"
						/>
						<span v-else class="a-composer__image-note">
							<AppIcon name="image" :size="18" /> {{ attachment.media_type }}
						</span>
						<button
							type="button"
							class="a-composer__image-remove"
							:aria-label="`${attachment.media_type === 'image' ? 'Bild' : 'Anhang'} entfernen`"
							@click="removeExisting(attachment.id)"
						>
							<AppIcon name="x" :size="15" />
						</button>
						<span class="a-composer__image-note">Vorhandener Anhang</span>
					</figure>
					<figure v-for="(image, index) in images" :key="image.key" class="a-composer__image">
						<img :src="image.preview" :alt="image.alt || `Bild ${index + 1}`" />
						<button type="button" class="a-composer__image-remove" aria-label="Bild entfernen" @click="removeImage(image.key)">
							<AppIcon name="x" :size="15" />
						</button>
						<label>
							<span>Alt-Text für Bild {{ index + 1 }}</span>
							<input
								v-model="image.alt"
								type="text"
								maxlength="1000"
								placeholder="Beschreibe das Bild für alle, die es nicht sehen können."
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
						accept="image/jpeg,image/png,image/webp"
						multiple
						@change="addFiles"
					/>
					<button
						type="button"
						class="a-icon-btn"
						:disabled="images.length + existing.length >= MAX_FILES || submitting"
						aria-label="Bilder hinzufügen"
						title="Bilder hinzufügen"
						@click="fileInput?.click()"
					>
						<AppIcon name="image" :size="20" />
					</button>
					<button
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': showWarning }"
						aria-label="Inhaltswarnung hinzufügen"
						title="Inhaltswarnung"
						@click="showWarning = !showWarning"
					>
						<AppIcon name="info" :size="20" />
					</button>
					<button
						v-if="!isEdit && !isQuote && !isDirect"
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': showPoll }"
						aria-label="Umfrage hinzufügen"
						title="Umfrage"
						@click="togglePoll"
					>
						<AppIcon name="hash" :size="20" />
					</button>
					<button
						v-if="!isEdit && !isQuote && !isDirect"
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--active': scheduleOpen }"
						aria-label="Beitrag planen"
						title="Beitrag planen"
						@click="toggleSchedule"
					>
						<AppIcon name="calendar" :size="20" />
					</button>
					<label v-if="isDirect" class="a-composer__visibility">
						<AppIcon name="lock" :size="16" />
						<span>Direktnachricht</span>
					</label>
					<label v-else class="a-composer__visibility">
						<AppIcon name="globe" :size="16" />
						<select v-model="visibility" aria-label="Sichtbarkeit">
							<option value="public">Öffentlich</option>
							<option value="unlisted">Nicht gelistet</option>
							<option value="followers">Nur Follower</option>
						</select>
					</label>
					<span class="a-composer__count" :class="{ 'a-count--over': overLimit }">
						{{ length }} / {{ MAX_LENGTH }}
					</span>
					<button type="submit" class="a-btn a-btn--primary" :disabled="!canSubmit">
						<AppIcon v-if="submitting" name="refresh" :size="16" class="a-spin" />
						{{ submitLabel }}
					</button>
				</div>
			</form>
		</div>
	</dialog>
</template>
