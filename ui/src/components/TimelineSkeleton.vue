<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
withDefaults(defineProps<{ count?: number }>(), { count: 3 });
</script>

<template>
	<div class="a-skeletons" aria-hidden="true">
		<div v-for="index in count" :key="index" class="a-skeleton-card">
			<div class="a-skeleton-card__avatar a-skeleton" />
			<div class="a-skeleton-card__body">
				<div class="a-skeleton a-skeleton--name" />
				<div class="a-skeleton a-skeleton--line" />
				<div class="a-skeleton a-skeleton--line a-skeleton--short" />
			</div>
		</div>
	</div>
</template>
