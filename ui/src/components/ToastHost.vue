<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from './AppIcon.vue';
import { appState, dismissToast } from '../lib/state';

function iconFor(tone: string): string {
	if (tone === 'success') {
		return 'check';
	}
	if (tone === 'error') {
		return 'info';
	}

	return 'sparkles';
}
</script>

<template>
	<div class="a-toasts" aria-live="polite" aria-atomic="false">
		<TransitionGroup name="a-toast">
			<div
				v-for="toast in appState.toasts"
				:key="toast.id"
				class="a-toast"
				:class="`a-toast--${toast.tone}`"
				role="status"
			>
				<AppIcon :name="iconFor(toast.tone)" :size="18" />
				<span class="a-toast__text">{{ toast.message }}</span>
				<button type="button" class="a-toast__close" aria-label="Hinweis schließen" @click="dismissToast(toast.id)">
					<AppIcon name="x" :size="15" />
				</button>
			</div>
		</TransitionGroup>
	</div>
</template>
