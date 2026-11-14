<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, nextTick, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { mediaUrl } from '../lib/format';
import { appState, closeLightbox } from '../lib/state';

const dialog = ref<HTMLDialogElement | null>(null);

const current = computed(() => appState.lightbox.attachments[appState.lightbox.index] ?? null);
const multiple = computed(() => appState.lightbox.attachments.length > 1);
const counter = computed(() => `${appState.lightbox.index + 1} / ${appState.lightbox.attachments.length}`);

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
		aria-label="Bildansicht"
		@close="closeLightbox"
		@click="onBackdrop"
		@keydown="onKeydown"
	>
		<div v-if="current" class="a-lightbox__inner">
			<button type="button" class="a-icon-btn a-lightbox__close" aria-label="Schließen" @click="close">
				<AppIcon name="x" :size="22" />
			</button>

			<button
				v-if="multiple"
				type="button"
				class="a-icon-btn a-lightbox__nav a-lightbox__nav--prev"
				aria-label="Vorheriges Bild"
				@click.stop="previous"
			>
				<AppIcon name="arrowLeft" :size="24" />
			</button>

			<figure class="a-lightbox__figure">
				<img :src="mediaUrl(current.url)" :alt="current.alt_text ?? 'Anhang'" />
				<figcaption v-if="current.alt_text" class="a-lightbox__caption">
					{{ current.alt_text }}
				</figcaption>
			</figure>

			<button
				v-if="multiple"
				type="button"
				class="a-icon-btn a-lightbox__nav a-lightbox__nav--next"
				aria-label="Nächstes Bild"
				@click.stop="next"
			>
				<AppIcon name="arrowLeft" :size="24" />
			</button>

			<span v-if="multiple" class="a-lightbox__counter">{{ counter }}</span>
		</div>
	</dialog>
</template>
