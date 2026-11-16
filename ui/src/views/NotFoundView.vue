<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
</script>

<template>
	<div class="a-page">
		<EmptyState
			icon="compass"
			title="Dead end"
			text="This page does not exist or has moved. Head back home to get back on track."
		>
			<a class="a-btn a-btn--primary a-btn--small" href="#/">
				<AppIcon name="home" :size="15" /> Back to home
			</a>
		</EmptyState>
	</div>
</template>
