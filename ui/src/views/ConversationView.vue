<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { handleLabel } from '../lib/format';
import { onPostCreated, onPostDeleted } from '../lib/events';
import { navigate, route } from '../lib/router';
import { appState, openDirectMessage } from '../lib/state';
import type { Account, TimelineItem } from '../lib/types';

const PAGE_SIZE = 40;
const PAGES = 5;

const signedIn = computed(() => appState.session.user !== null);
const myId = computed(() => appState.session.user?.handle ?? '');
const target = computed(() => route.value.name === 'conversation' ? route.value.id : '');
const counterpart = computed(() => target.value.split(',')[0] ?? '');

const loading = ref(false);
const error = ref('');
const items = ref<TimelineItem[]>([]);
const person = ref<Account | null>(null);
let generation = 0;

const title = computed(() => person.value?.display_name || (counterpart.value !== '' ? handleLabel(counterpart.value) : 'Conversation'));

onMounted(() => {
	void load();
});

watch(signedIn, (value) => {
	if (value) {
		void load();
	}
});

watch(target, () => {
	void load();
});

async function load(): Promise<void> {
	const current = ++generation;
	items.value = [];
	error.value = '';
	person.value = null;

	if (!signedIn.value || counterpart.value === '') {
		return;
	}

	loading.value = true;
	try {
		const account = await resolveAccount(counterpart.value);
		if (current === generation) {
			person.value = account;
		}

		const collected: TimelineItem[] = [];
		let cursor: string | null = null;
		for (let page = 0; page < PAGES; page += 1) {
			const batch = await getApi().homeTimeline(PAGE_SIZE, cursor);
			collected.push(...batch);
			if (batch.length < PAGE_SIZE) {
				break;
			}
			cursor = batch[batch.length - 1].id;
		}

		if (current !== generation) {
			return;
		}

		const direct = collected.filter((message) => message.visibility === 'direct');
		const hydrated = direct.length > 0 ? await getApi().posts(direct.map((message) => message.id)) : {};
		if (current !== generation) {
			return;
		}

		items.value = direct
			.map((message) => {
				const full = hydrated[message.id];
				return full === undefined
					? message
					: { ...message, ...full, interaction: message.interaction ?? full.interaction };
			})
			.filter((message) => belongsToConversation(message))
			.sort((left, right) => timeOf(left) - timeOf(right));
	} catch (caught) {
		if (current === generation) {
			error.value = caught instanceof Error ? caught.message : 'The conversation could not be loaded.';
		}
	} finally {
		if (current === generation) {
			loading.value = false;
		}
	}
}

function timeOf(item: TimelineItem): number {
	const value = new Date(item.published_at).getTime();
	return Number.isNaN(value) ? 0 : value;
}

function participantsOf(message: TimelineItem): string[] {
	return [...new Set([message.author_id, ...(message.recipient_ids ?? [])])]
		.filter((id) => id !== '')
		.sort();
}

function belongsToConversation(message: TimelineItem): boolean {
	const expected = target.value.split(',').filter((id) => id !== '').sort();
	const actual = participantsOf(message).filter((id) => id !== myId.value);
	return expected.length > 0 && expected.every((id) => actual.includes(id)) && actual.length === expected.length;
}

function compose(): void {
	const account = person.value;
	if (account !== null) {
		openDirectMessage(account);
		return;
	}

	if (counterpart.value !== '') {
		openDirectMessage({
			id: counterpart.value,
			handle: `@${counterpart.value}`,
			display_name: handleLabel(counterpart.value),
			url: '',
			followers_count: null,
			following_count: null,
			relationship: null,
		});
	}
}

const stopCreated = onPostCreated((created) => {
	if (created.visibility === 'direct' && belongsToConversation(created)) {
		items.value = [...items.value, created];
	}
});

const stopDeleted = onPostDeleted((deletedId) => {
	items.value = items.value.filter((item) => item.id !== deletedId);
	if (items.value.length === 0) {
		navigate('/messages');
	}
});

// The listeners live as long as the module; the component is recreated per route.
void stopCreated;
void stopDeleted;
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="title"
			:subtitle="counterpart !== '' ? handleLabel(counterpart) : 'Private Conversation'"
			back-href="#/messages"
			back-label="All messages"
		>
			<template #actions>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
					<AppIcon name="pen" :size="15" />
					Write
				</button>
			</template>
		</PageHeader>

		<p v-if="!signedIn" class="a-muted">Sign in to see this conversation.</p>

		<template v-else>
			<div v-if="person" class="a-dm-person">
				<Avatar :id="person.id" :name="person.display_name" :src="person.avatar" :size="52" />
				<div>
					<strong>{{ person.display_name }}</strong>
					<span>{{ person.handle }}</span>
				</div>
				<a :href="`#/u/${encodeURIComponent(person.handle || person.id)}`" class="a-btn a-btn--ghost a-btn--small">
					profiles
				</a>
			</div>

			<p v-if="error !== ''" class="a-feed__inline-error">{{ error }}</p>

			<TimelineSkeleton v-if="loading && items.length === 0" :count="2" />

			<ol v-else-if="items.length > 0" class="a-dm-thread">
				<li
					v-for="message in items"
					:key="message.id"
					:class="message.author_id === myId ? 'a-dm-thread__item a-dm-thread__item--mine' : 'a-dm-thread__item'"
				>
					<PostCard :post="message" />
				</li>
			</ol>

			<EmptyState
				v-else-if="!loading"
				icon="message"
				title="No messages yet"
				text="Write the first private message. Only you and this account can see it."
			>
				<button type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">Write message</button>
			</EmptyState>

			<button v-if="items.length > 0" type="button" class="a-inline-reply" @click="compose">
				<AppIcon name="pen" :size="18" />
				<span>Write a message to {{ title }}…</span>
			</button>
		</template>
	</div>
</template>
