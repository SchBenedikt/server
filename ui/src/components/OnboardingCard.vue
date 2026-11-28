<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import { t } from '../lib/i18n';
import { appState, openComposer, showToast } from '../lib/state';
import type { StarterPack } from '../lib/types';

const emit = defineEmits<{ dismissed: [] }>();
const dismissing = ref(false);
const myPacks = ref<StarterPack[]>([]);
const stats = ref<{ following: number; posts: number; bio: string; avatar: string | null } | null>(null);

const baseSteps = [
	{ key: 'profile', icon: 'user', labelKey: 'onboard.step.profile', href: '#/settings' },
	{ key: 'discover', icon: 'compass', labelKey: 'onboard.step.discover', href: '#/explore' },
	{ key: 'pack', icon: 'users', labelKey: 'onboard.step.pack', href: '#/starter-packs' },
	{ key: 'post', icon: 'pen', labelKey: 'onboard.step.post', href: null },
] as const;

const steps = computed(() => {
	const profile = stats.value ?? {
		following: appState.session.profile?.following_count ?? 0,
		posts: appState.session.profile?.posts_count ?? 0,
		bio: appState.session.profile?.bio ?? '',
		avatar: appState.session.profile?.avatar ?? null,
	};
	const done: Record<string, boolean> = {
		profile: (profile.bio ?? '').trim() !== '' || (profile.avatar ?? '') !== '',
		discover: profile.following > 0,
		pack: myPacks.value.length > 0,
		post: profile.posts > 0,
	};

	return baseSteps.map((step) => ({ ...step, done: done[step.key] === true }));
});

const doneCount = computed(() => steps.value.filter((step) => step.done).length);

onMounted(async () => {
	const handle = appState.session.user?.handle;
	if (handle === undefined || handle === '') {
		return;
	}
	try {
		const [account, packs] = await Promise.all([
			getApi().account(handle),
			getApi().starterPacks().catch(() => ({ mine: [] as StarterPack[], discover: [] as StarterPack[] })),
		]);
		stats.value = {
			following: account.following_count ?? 0,
			posts: account.posts_count ?? 0,
			bio: account.bio ?? '',
			avatar: account.avatar ?? null,
		};
		myPacks.value = packs.mine;
	} catch {
		// Completion markers are a nicety; the steps still work without them.
	}
});

function activate(step: (typeof baseSteps)[number]): void {
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
		showToast(t('onboard.dismissFailed'), 'error');
		dismissing.value = false;
	}
}
</script>

<template>
	<section class="a-onboarding" :aria-label="t('onboard.aria')">
		<div class="a-onboarding__copy">
			<p class="a-onboarding__eyebrow">
				<AppIcon name="sparkles" :size="15" />
				{{ t('onboard.eyebrow') }}
			</p>
			<h2>{{ t('onboard.heading') }}</h2>
			<p class="a-onboarding__lead">
				{{ t('onboard.lead', { done: doneCount, total: steps.length }) }}
			</p>
		</div>
		<ol class="a-onboarding__steps">
			<li v-for="step in steps" :key="step.key" :class="{ 'a-onboarding__step--done': step.done }">
				<span class="a-onboarding__icon" aria-hidden="true">
					<AppIcon :name="step.done ? 'check' : step.icon" :size="17" />
				</span>
				<a
					v-if="step.href !== null"
					:href="step.href"
					class="a-onboarding__step"
				>{{ t(step.labelKey) }}</a>
				<button
					v-else
					type="button"
					class="a-onboarding__step a-onboarding__step--button"
					@click="activate(step)"
				>{{ t(step.labelKey) }}</button>
				<span v-if="step.done" class="a-onboarding__done">{{ t('onboard.done') }}</span>
			</li>
		</ol>
		<div class="a-onboarding__actions">
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="dismissing" @click="dismiss">
				{{ dismissing ? t('common.saving') : t('onboard.dismiss') }}
			</button>
		</div>
	</section>
</template>
