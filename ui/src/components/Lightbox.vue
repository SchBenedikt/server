<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, nextTick, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { mediaUrl } from '../lib/format';
import { appState, closeLightbox } from '../lib/state';
import { t } from '../lib/i18n';

const dialog = ref<HTMLDialogElement | null>(null);

const current = computed(() => appState.lightbox.attachments[appState.lightbox.index] ?? null);
const multiple = computed(() => appState.lightbox.attachments.length > 1);
const counter = computed(() => `${appState.lightbox.index + 1} / ${appState.lightbox.attachments.length}`);
const currentIsVideo = computed(() => (
	current.value !== null
	&& (current.value.media_type === 'video'
		|| current.value.media_type === 'gifv'
		|| current.value.mime_type.startsWith('video/'))
));
const currentIsAudio = computed(() => (
	current.value !== null
	&& (current.value.media_type === 'audio' || current.value.mime_type.startsWith('audio/'))
));

function close(): void {
	closeLightbox();
	dialog.value?.close();
}

function previous(): void {
	const length = appState.lightbox.attachments.length;
	if (length === 0) {
		return;
	}

	appState.lightbox.index = (appState.lightbox.index - 1 + length) % length;
}

function next(): void {
	const length = appState.lightbox.attachments.length;
	if (length === 0) {
		return;
	}

	appState.lightbox.index = (appState.lightbox.index + 1) % length;
}

function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'ArrowLeft') {
		event.preventDefault();
		previous();
	} else if (event.key === 'ArrowRight') {
		event.preventDefault();
		next();
	}
}

function onBackdrop(event: MouseEvent): void {
	if (event.target === dialog.value) {
		close();
	}
}

watch(() => appState.lightbox.open, async (open) => {
	if (open) {
		await nextTick();
		if (dialog.value !== null && !dialog.value.open) {
			dialog.value.showModal();
		}
	} else if (dialog.value?.open) {
		dialog.value.close();
	}
});
</script>

<template>
	<dialog
		ref="dialog"
		class="a-lightbox"
		:aria-label="t('common.imageView')"
		@close="closeLightbox"
		@click="onBackdrop"
		@keydown="onKeydown"
	>
		<div v-if="current" class="a-lightbox__inner">
			<button type="button" class="a-icon-btn a-lightbox__close" :aria-label="t('common.close')" @click="close">
				<AppIcon name="x" :size="22" />
			</button>

			<button
				v-if="multiple"
				type="button"
				class="a-icon-btn a-lightbox__nav a-lightbox__nav--prev"
				:aria-label="t('common.previousImage')"
				@click.stop="previous"
			>
				<AppIcon name="arrowLeft" :size="24" />
			</button>

			<figure class="a-lightbox__figure">
				<video
					v-if="currentIsVideo"
					:src="mediaUrl(current.url)"
					:poster="current.thumbnail_url !== null ? mediaUrl(current.thumbnail_url) : undefined"
					controls
					autoplay
					playsinline
				/>
				<audio v-else-if="currentIsAudio" :src="mediaUrl(current.url)" controls />
				<img v-else :src="mediaUrl(current.url)" :alt="current.alt_text ?? 'attachment'" />
				<figcaption v-if="current.alt_text" class="a-lightbox__caption">
					{{ current.alt_text }}
				</figcaption>
			</figure>

			<button
				v-if="multiple"
				type="button"
				class="a-icon-btn a-lightbox__nav a-lightbox__nav--next"
				:aria-label="t('common.nextImage')"
				@click.stop="next"
			>
				<AppIcon name="arrowLeft" :size="24" />
			</button>

			<span v-if="multiple" class="a-lightbox__counter">{{ counter }}</span>
		</div>
	</dialog>
</template>
