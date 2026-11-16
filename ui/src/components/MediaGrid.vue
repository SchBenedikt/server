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

function isVideo(attachment: Attachment): boolean {
	return attachment.media_type === 'video'
		|| attachment.media_type === 'gifv'
		|| attachment.mime_type.startsWith('video/');
}

function isAudio(attachment: Attachment): boolean {
	return attachment.media_type === 'audio' || attachment.mime_type.startsWith('audio/');
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
		<template v-for="(attachment, index) in attachments" :key="attachment.id">
			<div
				v-if="isVideo(attachment)"
				class="a-media__item a-media__item--video"
			>
				<video
					:src="mediaUrl(attachment.url)"
					:poster="attachment.thumbnail_url !== null ? mediaUrl(attachment.thumbnail_url) : undefined"
					controls
					playsinline
					preload="metadata"
					:aria-label="attachment.alt_text !== null && attachment.alt_text !== ''
						? `Video: ${attachment.alt_text}`
						: `Video ${index + 1} of ${attachments.length}`"
				/>
				<span v-if="attachment.alt_text" class="a-media__alt">ALT</span>
			</div>

			<div
				v-else-if="isAudio(attachment)"
				class="a-media__item a-media__item--audio"
			>
				<span class="a-media__audio-label">
					<AppIcon name="music" :size="16" />
					{{ attachment.alt_text !== null && attachment.alt_text !== ''
						? attachment.alt_text
						: `Audio ${index + 1} of ${attachments.length}` }}
				</span>
				<audio :src="mediaUrl(attachment.url)" controls preload="metadata" />
			</div>

			<button
				v-else
				type="button"
				class="a-media__item"
				:aria-label="attachment.alt_text !== null && attachment.alt_text !== ''
					? `Open image: ${attachment.alt_text}`
					: `Open image ${index + 1} of ${attachments.length}`"
				@click="open(index)"
			>
				<img
					v-if="!broken.has(attachment.id)"
					:src="mediaUrl(attachment.thumbnail_url ?? attachment.url)"
					:alt="attachment.alt_text ?? `attachment by ${authorHandle}`"
					loading="lazy"
					decoding="async"
					@error="markBroken(attachment.id)"
				/>
				<span v-else class="a-media__fallback">
					<AppIcon name="image" :size="28" />
					<span>Image unavailable</span>
				</span>
				<span v-if="attachment.alt_text" class="a-media__alt">ALT</span>
			</button>
		</template>
	</div>
</template>
