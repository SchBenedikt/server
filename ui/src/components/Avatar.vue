<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';
import { avatarInitials, avatarStyle } from '../lib/format';

const props = withDefaults(defineProps<{
	id: string;
	name?: string;
	size?: number;
	ring?: boolean;
}>(), {
	name: '',
	size: 44,
	ring: false,
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
		:class="{ 'a-avatar--ring': ring }"
		:style="style"
		role="img"
		:aria-label="label"
	>
		<span>{{ initials }}</span>
	</span>
</template>
