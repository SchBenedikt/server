<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import { getApi } from '../lib/api';
import { handleLabel, relativeTime } from '../lib/format';
import { appState, showToast } from '../lib/state';
import type { Story, StoryGroup } from '../lib/types';

const backgrounds = [
	{ id: 'ocean', label: 'Ozean' },
	{ id: 'sunset', label: 'Sonnenuntergang' },
	{ id: 'forest', label: 'Wald' },
	{ id: 'berry', label: 'Beere' },
	{ id: 'night', label: 'Nacht' },
	{ id: 'sand', label: 'Sand' },
];

const groups = ref<StoryGroup[]>([]);
const loading = ref(true);
const activeGroup = ref<number | null>(null);
const activeIndex = ref(0);
const composing = ref(false);
const draft = ref('');
const tone = ref('ocean');
const saving = ref(false);
const deleting = ref('');

const signedIn = computed(() => appState.session.user !== null);
const selfHandle = computed(() => appState.session.user?.handle ?? '');
const ownGroupIndex = computed(() => groups.value.findIndex((group) => group.account_id === selfHandle.value));
const visibleGroups = computed(() => groups.value.filter((group) => group.stories.length > 0));
const activeStory = computed<Story | null>(() => {
	const groupIndex = activeGroup.value;
	if (groupIndex === null) {
		return null;
	}

	const group = visibleGroups.value[groupIndex];
	return group?.stories[activeIndex.value] ?? null;
});
const activeAuthor = computed(() => activeStory.value?.account_id ?? '');
const canDeleteActive = computed(() => signedIn.value && activeAuthor.value === selfHandle.value);

async function load(): Promise<void> {
	loading.value = true;
	try {
		groups.value = (await getApi().stories()).groups;
	} catch {
		groups.value = [];
	} finally {
		loading.value = false;
	}
}

function open(groupIndex: number): void {
	activeGroup.value = groupIndex;
	activeIndex.value = 0;
}

function close(): void {
	activeGroup.value = null;
	activeIndex.value = 0;
}

function step(delta: number): void {
	const groupIndex = activeGroup.value;
	if (groupIndex === null) {
		return;
	}

	const group = visibleGroups.value[groupIndex];
	if (group === undefined) {
		close();
		return;
	}

	const next = activeIndex.value + delta;
	if (next >= 0 && next < group.stories.length) {
		activeIndex.value = next;
		return;
	}

	const nextGroup = groupIndex + delta;
	if (nextGroup >= 0 && nextGroup < visibleGroups.value.length) {
		activeGroup.value = nextGroup;
		activeIndex.value = delta > 0 ? 0 : Math.max(0, visibleGroups.value[nextGroup].stories.length - 1);
		return;
	}

	close();
}

async function submit(): Promise<void> {
	if (saving.value) {
		return;
	}

	const content = draft.value.trim();
	if (content === '') {
		return;
	}

	saving.value = true;
	try {
		await getApi().createStory(content, tone.value);
		draft.value = '';
		composing.value = false;
		await load();
		showToast('Story ist 24 Stunden sichtbar.', 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'Die Story konnte nicht gespeichert werden.', 'error');
	} finally {
		saving.value = false;
	}
}

async function removeActive(): Promise<void> {
	const story = activeStory.value;
	if (story === null || deleting.value !== '') {
		return;
	}

	deleting.value = story.id;
	try {
		await getApi().deleteStory(story.id);
		step(1);
		await load();
		showToast('Story gelöscht.', 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'Die Story konnte nicht gelöscht werden.', 'error');
	} finally {
		deleting.value = '';
	}
}

function authorName(accountId: string): string {
	if (accountId === selfHandle.value) {
		return appState.session.user?.displayName || handleLabel(accountId);
	}

	return handleLabel(accountId);
}

onMounted(load);
</script>

<template>
	<section v-if="loading || visibleGroups.length > 0 || signedIn" class="a-stories" aria-label="Storys">
		<header class="a-stories__head">
			<h2>Storys</h2>
			<button
				v-if="signedIn"
				type="button"
				class="a-btn a-btn--ghost a-btn--small"
				@click="composing = !composing"
			>
				<AppIcon name="plus" :size="15" />
				Story teilen
			</button>
		</header>

		<div class="a-stories__row">
			<button
				v-if="signedIn"
				type="button"
				class="a-story a-story--add"
				aria-label="Neue Story schreiben"
				@click="composing = true"
			>
				<span class="a-story__add">
					<AppIcon name="plus" :size="20" />
				</span>
				<small>Deine Story</small>
			</button>

			<button
				v-for="(group, index) in visibleGroups"
				:key="group.account_id"
				type="button"
				class="a-story"
				:class="{ 'a-story--own': group.account_id === selfHandle }"
				:aria-label="`Story von ${authorName(group.account_id)} ansehen`"
				@click="open(index)"
			>
				<span class="a-story__ring">
					<Avatar :id="group.account_id" :name="authorName(group.account_id)" :size="54" />
				</span>
				<small>{{ group.account_id === selfHandle ? 'Du' : authorName(group.account_id) }}</small>
			</button>
		</div>

		<form v-if="composing" class="a-story-compose" @submit.prevent="submit">
			<label>
				<span>Story-Text</span>
				<textarea
					v-model="draft"
					maxlength="500"
					rows="3"
					placeholder="Was passiert gerade?"
				/>
			</label>
			<div class="a-story-compose__tones" role="group" aria-label="Hintergrund">
				<button
					v-for="background in backgrounds"
					:key="background.id"
					type="button"
					class="a-story-tone"
					:class="[`a-story-tone--${background.id}`, { 'a-story-tone--active': tone === background.id }]"
					:aria-label="background.label"
					:aria-pressed="tone === background.id"
					@click="tone = background.id"
				/>
			</div>
			<div class="a-story-compose__actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="composing = false">Abbrechen</button>
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || draft.trim() === ''">
					{{ saving ? 'Wird geteilt …' : 'Story teilen' }}
				</button>
			</div>
		</form>

		<div v-if="activeStory" class="a-story-viewer" role="dialog" aria-modal="true" aria-label="Story">
			<div class="a-story-viewer__backdrop" @click="close" />
			<article class="a-story-viewer__card" :class="`a-story-tone--${activeStory.background}`">
				<header class="a-story-viewer__head">
					<Avatar :id="activeStory.account_id" :name="authorName(activeStory.account_id)" :size="36" />
					<div>
						<strong>{{ authorName(activeStory.account_id) }}</strong>
						<small>{{ relativeTime(activeStory.created_at) }}</small>
					</div>
					<button type="button" class="a-icon-btn" aria-label="Story schließen" @click="close">
						<AppIcon name="x" :size="20" />
					</button>
				</header>

				<div class="a-story-viewer__progress" aria-hidden="true">
					<span
						v-for="(_, index) in (visibleGroups[activeGroup ?? 0]?.stories ?? [])"
						:key="index"
						class="a-story-viewer__segment"
						:class="{ 'a-story-viewer__segment--done': index <= activeIndex }"
					/>
				</div>

				<p class="a-story-viewer__text">{{ activeStory.content }}</p>

				<footer class="a-story-viewer__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="step(-1)">Zurück</button>
					<button v-if="canDeleteActive" type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="deleting !== ''" @click="removeActive">
						<AppIcon name="trash" :size="15" />
						Löschen
					</button>
					<button type="button" class="a-btn a-btn--primary a-btn--small" @click="step(1)">Weiter</button>
				</footer>
			</article>
		</div>
	</section>
</template>
