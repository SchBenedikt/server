<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import { openComposer, showToast } from '../lib/state';

const emit = defineEmits<{ dismissed: [] }>();
const dismissing = ref(false);

const steps = [
	{ icon: 'user', label: 'Complete your profile', href: '#/settings' },
	{ icon: 'compass', label: 'Discover people worth following', href: '#/explore' },
	{ icon: 'users', label: 'Try a starter pack', href: '#/starter-packs' },
	{ icon: 'pen', label: 'Write your first post', href: null },
] as const;

function activate(step: (typeof steps)[number]): void {
	if (step.href === null) {
		openComposer();
	}
}

async function dismiss(): Promise<void> {
	if (dismissing.value) {
		return;
	}
	dismissing.value = true;
	try {
		await getApi().updatePreferences({ onboarding_dismissed: true });
		emit('dismissed');
	} catch {
		showToast('The dismissal could not be saved. Please try again.', 'error');
		dismissing.value = false;
	}
}
</script>

<template>
	<section class="a-onboarding" aria-label="Getting started">
		<div class="a-onboarding__copy">
			<p class="a-onboarding__eyebrow">
				<AppIcon name="sparkles" :size="15" />
				Welcome aboard
			</p>
			<h2>Four quick steps to your first feed</h2>
			<p class="a-onboarding__lead">
				Everything here is optional — dismiss this card any time and it
				stays dismissed on every device you sign in from.
			</p>
		</div>
		<ol class="a-onboarding__steps">
			<li v-for="step in steps" :key="step.label">
				<span class="a-onboarding__icon" aria-hidden="true">
					<AppIcon :name="step.icon" :size="17" />
				</span>
				<a
					v-if="step.href !== null"
					:href="step.href"
					class="a-onboarding__step"
				>{{ step.label }}</a>
				<button
					v-else
					type="button"
					class="a-onboarding__step a-onboarding__step--button"
					@click="activate(step)"
				>{{ step.label }}</button>
			</li>
		</ol>
		<div class="a-onboarding__actions">
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="dismissing" @click="dismiss">
				{{ dismissing ? 'Saving…' : 'Dismiss' }}
			</button>
		</div>
	</section>
</template>
