<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, watch } from 'vue';
import { avatarInitials, avatarStyle } from '../lib/format';

const props = withDefaults(defineProps<{
	id: string;
	name?: string;
	size?: number;
	ring?: boolean;
	src?: string | null;
}>(), {
	name: '',
	size: 44,
	ring: false,
	src: null,
});

const imageFailed = ref(false);

watch(() => props.src, () => {
	imageFailed.value = false;
});

const image = computed(() => {
	const value = props.src?.trim() ?? '';
	return value !== '' && !imageFailed.value ? value : '';
});

const style = computed(() => ({
	...avatarStyle(props.id),
	width: `${props.size}px`,
	height: `${props.size}px`,
	fontSize: `${Math.max(11, Math.round(props.size * 0.34))}px`,
}));

const initials = computed(() => avatarInitials(props.name, props.id));
const label = computed(() => props.name !== '' ? props.name : `@${props.id}`);
</script>

<template>
	<span
		class="a-avatar"
		:class="{ 'a-avatar--ring': ring, 'a-avatar--image': image !== '' }"
		:style="style"
		role="img"
		:aria-label="label"
	>
		<img
			v-if="image !== ''"
			class="a-avatar__image"
			:src="image"
			alt=""
			loading="lazy"
			decoding="async"
			referrerpolicy="no-referrer"
			@error="imageFailed = true"
		/>
		<span v-else>{{ initials }}</span>
	</span>
</template>
