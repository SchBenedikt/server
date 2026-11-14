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
import { announcePostCreated } from '../lib/events';
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
const submitting = ref(false);
const error = ref('');
let imageSequence = 0;
let draftTimer: number | null = null;

const replyTo = computed(() => appState.composer.replyTo);
const isReply = computed(() => replyTo.value !== null);
const title = computed(() => isReply.value ? 'Antwort schreiben' : 'Neuer Beitrag');
const replyAuthor = computed(() => replyTo.value === null ? '' : displayAuthor(replyTo.value));
const replyHandle = computed(() => replyTo.value === null ? '' : handleLabel(replyTo.value.object_author_id ?? replyTo.value.author_id));
const replyId = computed(() => replyTo.value === null ? null : postRouteId(replyTo.value));

const length = computed(() => Array.from(content.value).length);
const warningLength = computed(() => Array.from(contentWarning.value).length);
const overLimit = computed(() => length.value > MAX_LENGTH);
const warningOverLimit = computed(() => warningLength.value > MAX_WARNING);
const canSubmit = computed(() => (
	appState.session.user !== null
	&& content.value.trim() !== ''
	&& !overLimit.value
	&& !warningOverLimit.value
	&& !submitting.value
	&& (isReply.value ? replyId.value !== null : true)
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
	if (isReply.value || currentHandle.value === '') {
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
	if (isReply.value) {
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

watch(() => appState.composer.open, async (open) => {
	if (open) {
		error.value = '';
		restoreDraft();
		await nextTick();
		if (dialog.value !== null && !dialog.value.open) {
			dialog.value.showModal();
		}
		window.setTimeout(() => textarea.value?.focus(), 60);
		if (replyTo.value !== null) {
			void resolveAccount(replyTo.value.object_author_id ?? replyTo.value.author_id);
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
		if (images.value.length >= MAX_FILES) {
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

		const replying = isReply.value;
		const post = await getApi().createPost({
			content: content.value,
			visibility: visibility.value,
			content_warning: showWarning.value ? contentWarning.value : null,
			in_reply_to_id: replyId.value,
			attachment_ids: attachments.map((attachment) => attachment.id),
		});

		announcePostCreated(post);
		clearDraft();
		resetImages();
		content.value = '';
		contentWarning.value = '';
		showWarning.value = false;
		visibility.value = 'public';
		closeComposer();
		showToast(replying ? 'Antwort veröffentlicht.' : 'Beitrag veröffentlicht.', 'success');
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Der Beitrag konnte nicht veröffentlicht werden.';
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

				<div v-if="images.length > 0" class="a-composer__images">
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
						:disabled="images.length >= MAX_FILES || submitting"
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
					<label class="a-composer__visibility">
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
						{{ submitting ? 'Wird gesendet…' : isReply ? 'Antworten' : 'Posten' }}
					</button>
				</div>
			</form>
		</div>
	</dialog>
</template>
