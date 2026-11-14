<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from './AppIcon.vue';

withDefaults(defineProps<{
	icon?: string;
	title: string;
	text: string;
}>(), {
	icon: 'sparkles',
});
</script>

<template>
	<div class="a-empty">
		<span class="a-empty__icon">
			<AppIcon :name="icon" :size="26" />
		</span>
		<h2 class="a-empty__title">{{ title }}</h2>
		<p class="a-empty__text">{{ text }}</p>
		<div v-if="$slots.default" class="a-empty__actions">
			<slot />
		</div>
	</div>
</template>
