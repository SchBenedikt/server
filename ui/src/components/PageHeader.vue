<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from './AppIcon.vue';

withDefaults(defineProps<{
	title: string;
	subtitle?: string;
	backHref?: string;
	backLabel?: string;
}>(), {
	subtitle: '',
	backHref: '',
	backLabel: 'Back',
});
</script>

<template>
	<header class="a-page-header">
		<a v-if="backHref" :href="backHref" class="a-icon-btn a-page-header__back" :aria-label="backLabel">
			<AppIcon name="arrowLeft" :size="20" />
		</a>
		<div class="a-page-header__copy">
			<h1 class="a-page-header__title">{{ title }}</h1>
			<p v-if="subtitle" class="a-page-header__subtitle">{{ subtitle }}</p>
		</div>
		<div v-if="$slots.actions" class="a-page-header__actions">
			<slot name="actions" />
		</div>
	</header>
</template>
