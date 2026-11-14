<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { reactive } from 'vue';
import AppIcon from './AppIcon.vue';
import { mediaUrl } from '../lib/format';
import { openLightbox } from '../lib/state';
import type { Attachment } from '../lib/types';

const props = withDefaults(defineProps<{
	attachments: Attachment[];
	authorHandle?: string;
}>(), {
	authorHandle: '',
});

const broken = reactive(new Set<string>());

function markBroken(id: string): void {
	broken.add(id);
}

function open(index: number): void {
	openLightbox(props.attachments, index);
}
</script>

<template>
	<div
		v-if="attachments.length > 0"
		class="a-media"
		:class="`a-media--${Math.min(attachments.length, 4)}`"
	>
		<button
			v-for="(attachment, index) in attachments"
			:key="attachment.id"
			type="button"
			class="a-media__item"
			:aria-label="attachment.alt_text !== null && attachment.alt_text !== ''
				? `Bild öffnen: ${attachment.alt_text}`
				: `Bild ${index + 1} von ${attachments.length} öffnen`"
			@click="open(index)"
		>
			<img
				v-if="!broken.has(attachment.id)"
				:src="mediaUrl(attachment.thumbnail_url ?? attachment.url)"
				:alt="attachment.alt_text ?? `Anhang von ${authorHandle}`"
				loading="lazy"
				decoding="async"
				@error="markBroken(attachment.id)"
			/>
			<span v-else class="a-media__fallback">
				<AppIcon name="image" :size="28" />
				<span>Bild nicht verfügbar</span>
			</span>
			<span v-if="attachment.alt_text" class="a-media__alt">ALT</span>
		</button>
	</div>
</template>
