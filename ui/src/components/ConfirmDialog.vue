<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from './AppIcon.vue';

withDefaults(defineProps<{
	open: boolean;
	title: string;
	message: string;
	confirmLabel?: string;
	cancelLabel?: string;
	tone?: 'primary' | 'danger';
	busy?: boolean;
}>(), {
	confirmLabel: 'Confirm',
	cancelLabel: 'Cancel',
	tone: 'primary',
	busy: false,
});

const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
	<Teleport to="body">
		<div
			v-if="open"
			class="a-modal-overlay"
			role="presentation"
			@click.self="emit('cancel')"
			@keydown.esc="emit('cancel')"
		>
			<div class="a-management-dialog a-admin-confirm" role="dialog" aria-modal="true" :aria-label="title">
				<header class="a-profile-edit__head">
					<div>
						<p class="a-profile-edit__eyebrow">Confirmation</p>
						<h2>{{ title }}</h2>
					</div>
					<button type="button" class="a-icon-btn" aria-label="Close dialog" :disabled="busy" @click="emit('cancel')">
						<AppIcon name="x" :size="19" />
					</button>
				</header>
				<p class="a-admin-confirm__message">{{ message }}</p>
				<footer class="a-profile-edit__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="busy" @click="emit('cancel')">
						{{ cancelLabel }}
					</button>
					<button
						type="button"
						class="a-btn a-btn--small"
						:class="tone === 'danger' ? 'a-btn--danger' : 'a-btn--primary'"
						:disabled="busy"
						@click="emit('confirm')"
					>
						<AppIcon v-if="busy" name="refresh" :size="15" class="a-spin" />
						{{ busy ? 'Running…' : confirmLabel }}
					</button>
				</footer>
			</div>
		</div>
	</Teleport>
</template>
