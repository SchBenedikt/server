<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import Avatar from './Avatar.vue';
import { getApi } from '../lib/api';
import { t } from '../lib/i18n';
import { handleLabel, relativeTime } from '../lib/format';
import { appState, showToast } from '../lib/state';
import type { Story, StoryGroup } from '../lib/types';

const STORY_DURATION = 8000;
const TICK = 80;

const backgrounds = [
	{ id: 'ocean', labelKey: 'story.tone.ocean' },
	{ id: 'sunset', labelKey: 'story.tone.sunset' },
	{ id: 'forest', labelKey: 'story.tone.forest' },
	{ id: 'berry', labelKey: 'story.tone.berry' },
	{ id: 'night', labelKey: 'story.tone.night' },
	{ id: 'sand', labelKey: 'story.tone.sand' },
];

const groups = ref<StoryGroup[]>([]);
const loading = ref(true);
const loadFailed = ref(false);
const activeGroup = ref<number | null>(null);
const activeIndex = ref(0);
const composing = ref(false);
const draft = ref('');
const tone = ref('ocean');
const saving = ref(false);
const deleting = ref('');
const progress = ref(0);
let timer = 0;

const signedIn = computed(() => appState.session.user !== null);
const selfHandle = computed(() => appState.session.user?.handle ?? '');
const selfId = computed(() => appState.session.user?.id ?? '');
const visibleGroups = computed(() => groups.value.filter((group) => group.stories.length > 0));
const ownGroupIndex = computed(() => visibleGroups.value.findIndex((group) => group.account_id === selfId.value));
const activeStory = computed<Story | null>(() => {
	const groupIndex = activeGroup.value;
	if (groupIndex === null) {
		return null;
	}

	const group = visibleGroups.value[groupIndex];
	return group?.stories[activeIndex.value] ?? null;
});
const activeGroupStories = computed(() => {
	const groupIndex = activeGroup.value;
	if (groupIndex === null) {
		return [];
	}

	return visibleGroups.value[groupIndex]?.stories ?? [];
});
const activeAuthor = computed(() => activeStory.value?.account_id ?? '');
const canDeleteActive = computed(() => signedIn.value && activeAuthor.value === selfId.value);

async function load(): Promise<void> {
	loading.value = true;
	loadFailed.value = false;
	try {
		groups.value = (await getApi().stories()).groups;
	} catch {
		groups.value = [];
		loadFailed.value = true;
	} finally {
		loading.value = false;
	}
}

async function markSeen(id: string): Promise<void> {
	if (id === '') {
		return;
	}

	try {
		await getApi().markStoriesSeen([id]);
	} catch {
		// A failed marker is cosmetic; the viewer still works.
		return;
	}

	groups.value = groups.value.map((group) => {
		const stories = group.stories.map((story) => (
			story.id === id ? { ...story, seen: true } : story
		));

		return { ...group, stories, unseen: stories.some((story) => story.seen !== true) };
	});
}

function open(groupIndex: number): void {
	activeGroup.value = groupIndex;
	activeIndex.value = 0;
}

function close(): void {
	stopTimer();
	activeGroup.value = null;
	activeIndex.value = 0;
	progress.value = 0;
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

function startTimer(): void {
	stopTimer();
	progress.value = 0;
	timer = window.setInterval(() => {
		progress.value = Math.min(1, progress.value + TICK / STORY_DURATION);
		if (progress.value >= 1) {
			step(1);
		}
	}, TICK);
}

function stopTimer(): void {
	if (timer !== 0) {
		window.clearInterval(timer);
		timer = 0;
	}
}

function onKeydown(event: KeyboardEvent): void {
	if (activeStory.value === null) {
		return;
	}

	if (event.key === 'Escape') {
		event.preventDefault();
		close();
	} else if (event.key === 'ArrowLeft') {
		event.preventDefault();
		step(-1);
	} else if (event.key === 'ArrowRight') {
		event.preventDefault();
		step(1);
	}
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
		showToast(t('story.saved'), 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('story.saveFailed'), 'error');
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
		showToast(t('story.deleted'), 'success');
	} catch (error) {
		showToast(error instanceof Error ? error.message : t('story.deleteFailed'), 'error');
	} finally {
		deleting.value = '';
	}
}

function authorName(accountId: string, handle?: string, name?: string): string {
	if (accountId === selfId.value) {
		return appState.session.user?.displayName || handleLabel(selfHandle.value);
	}

	return name !== undefined && name.trim() !== '' ? name : handleLabel(handle ?? accountId);
}

watch(activeStory, (story) => {
	if (story === null) {
		stopTimer();
		document.body.style.removeProperty('overflow');
	} else {
		startTimer();
		document.body.style.overflow = 'hidden';
		void markSeen(story.id);
	}
});

watch(activeIndex, () => {
	if (activeStory.value !== null) {
		startTimer();
	}
});

onMounted(() => {
	void load();
	window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
	stopTimer();
	window.removeEventListener('keydown', onKeydown);
	document.body.style.removeProperty('overflow');
});
</script>

<template>
	<section v-if="loading || visibleGroups.length > 0 || signedIn" class="a-stories" :aria-label="t('story.aria')">
		<header class="a-stories__head">
			<h2>{{ t('story.title') }}</h2>
			<button
				v-if="signedIn"
				type="button"
				class="a-btn a-btn--ghost a-btn--small"
				@click="composing = !composing"
			>
				<AppIcon name="plus" :size="15" />
				{{ t('story.share') }}
			</button>
		</header>

		<p v-if="loadFailed" class="a-form-error" role="alert">
			{{ t('story.loadFailed') }}
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</p>

		<div class="a-stories__row">
			<button
				v-if="signedIn"
				type="button"
				class="a-story a-story--add"
				:aria-label="t('story.writeNew')"
				@click="composing = true"
			>
				<span class="a-story__add">
					<AppIcon name="plus" :size="20" />
				</span>
				<small>{{ ownGroupIndex >= 0 ? t('story.addToYours') : t('story.yours') }}</small>
			</button>

			<button
				v-for="(group, index) in visibleGroups"
				:key="group.account_id"
				type="button"
				class="a-story"
				:class="{ 'a-story--own': group.account_id === selfId, 'a-story--seen': group.unseen === false }"
				:aria-label="t('story.viewBy', { name: authorName(group.account_id, group.handle, group.name) })"
				@click="open(index)"
			>
				<span class="a-story__ring">
					<Avatar :id="group.handle ?? group.account_id" :name="authorName(group.account_id, group.handle, group.name)" :size="54" />
				</span>
				<small>{{ group.account_id === selfId ? t('story.you') : authorName(group.account_id, group.handle, group.name) }}</small>
			</button>
		</div>

		<form v-if="composing" class="a-story-compose" @submit.prevent="submit">
			<label>
				<span>{{ t('story.textLabel') }}</span>
				<textarea
					v-model="draft"
					maxlength="500"
					rows="3"
					:placeholder="t('story.placeholder')"
				/>
			</label>
			<div class="a-story-compose__tones" role="group" :aria-label="t('story.background')">
				<button
					v-for="background in backgrounds"
					:key="background.id"
					type="button"
					class="a-story-tone"
					:class="[`a-story-tone--${background.id}`, { 'a-story-tone--active': tone === background.id }]"
					:aria-label="t(background.labelKey)"
					:aria-pressed="tone === background.id"
					@click="tone = background.id"
				/>
			</div>
			<div class="a-story-compose__actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="composing = false">{{ t('common.cancel') }}</button>
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || draft.trim() === ''">
					{{ saving ? t('story.sharing') : t('story.shareSubmit') }}
				</button>
			</div>
		</form>

		<div v-if="activeStory" class="a-story-viewer" role="dialog" aria-modal="true" :aria-label="t('story.viewerAria')">
			<div class="a-story-viewer__backdrop" @click="close" />
			<article class="a-story-viewer__card" :class="`a-story-tone--${activeStory.background}`">
				<header class="a-story-viewer__head">
					<Avatar :id="activeStory.author_handle ?? activeStory.account_id" :name="authorName(activeStory.account_id, activeStory.author_handle, activeStory.author_name)" :size="36" />
					<div>
						<strong>{{ authorName(activeStory.account_id, activeStory.author_handle, activeStory.author_name) }}</strong>
						<small>{{ relativeTime(activeStory.created_at) }}</small>
					</div>
					<button type="button" class="a-icon-btn" :aria-label="t('story.close')" @click="close">
						<AppIcon name="x" :size="20" />
					</button>
				</header>

				<div class="a-story-viewer__progress" aria-hidden="true">
					<span
						v-for="(_, index) in activeGroupStories"
						:key="index"
						class="a-story-viewer__segment"
						:class="{ 'a-story-viewer__segment--done': index < activeIndex }"
					>
						<span
							v-if="index === activeIndex"
							class="a-story-viewer__segment-fill"
							:style="{ width: `${Math.round(progress * 100)}%` }"
						/>
					</span>
				</div>

				<p class="a-story-viewer__text">{{ activeStory.content }}</p>

				<footer class="a-story-viewer__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="step(-1)">{{ t('common.back') }}</button>
					<button v-if="canDeleteActive" type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="deleting !== ''" @click="removeActive">
						<AppIcon name="trash" :size="15" />
						{{ t('story.delete') }}
					</button>
					<button type="button" class="a-btn a-btn--primary a-btn--small" @click="step(1)">{{ t('common.next') }}</button>
				</footer>
			</article>
		</div>
	</section>
</template>
