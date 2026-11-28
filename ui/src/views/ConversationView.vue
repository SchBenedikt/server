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
const threadRoot = computed(() => target.value.startsWith('thread:') ? target.value.slice('thread:'.length) : '');
const counterpart = computed(() => {
	if (threadRoot.value !== '') {
		const latest = items.value.at(-1);
		return latest === undefined
			? ''
			: participantsOf(latest).find((id) => id !== myId.value) ?? '';
	}
	return target.value.split(',')[0] ?? '';
});

const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const items = ref<TimelineItem[]>([]);
const person = ref<Account | null>(null);
const hasMore = ref(false);
const cursor = ref<{ before: string; beforeId: string } | null>(null);
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
	hasMore.value = false;
	cursor.value = null;

	// A thread link only carries the root id. The counterpart is derived from
	// the messages after loading, so requiring it here left every conversation
	// linked from the inbox permanently empty.
	if (!signedIn.value || (threadRoot.value === '' && counterpart.value === '')) {
		return;
	}

	loading.value = true;
	try {
		if (threadRoot.value === '') {
			const account = await resolveAccount(counterpart.value);
			if (current === generation) {
				person.value = account;
			}
		}

		const collected: TimelineItem[] = [];
		for (let page = 0; page < PAGES; page += 1) {
			const result = await getApi().directTimelinePage(PAGE_SIZE, cursor.value?.before, cursor.value?.beforeId);
			collected.push(...result.posts);
			cursor.value = result.nextCursor;
			hasMore.value = result.hasMore;
			if (!result.hasMore) {
				break;
			}
		}

		if (current !== generation) {
			return;
		}

		items.value = collected
			.filter((message) => belongsToConversation(message))
			.sort(compareOldToNew);
		if (counterpart.value !== '') {
			person.value = await resolveAccount(counterpart.value);
		}
		const latest = items.value.at(-1);
		if (latest !== undefined) {
			await getApi().markConversationRead(latest.id);
			items.value = items.value.map((message) => ({ ...message, unread: false }));
		}
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

async function loadMore(): Promise<void> {
	if (loadingMore.value || !hasMore.value || cursor.value === null) {
		return;
	}
	const current = generation;
	loadingMore.value = true;
	error.value = '';
	try {
		const result = await getApi().directTimelinePage(PAGE_SIZE, cursor.value.before, cursor.value.beforeId);
		if (current !== generation) {
			return;
		}
		cursor.value = result.nextCursor;
		hasMore.value = result.hasMore;
		const seen = new Set(items.value.map((item) => item.id));
		items.value = [...items.value, ...result.posts.filter((message) => (
			belongsToConversation(message) && !seen.has(message.id)
		))].sort(compareOldToNew);
	} catch (caught) {
		if (current === generation) {
			error.value = caught instanceof Error ? caught.message : 'Older messages could not be loaded.';
		}
	} finally {
		if (current === generation) {
			loadingMore.value = false;
		}
	}
}

function timeOf(item: TimelineItem): number {
	const value = new Date(item.published_at).getTime();
	return Number.isNaN(value) ? 0 : value;
}

function compareOldToNew(left: TimelineItem, right: TimelineItem): number {
	return timeOf(left) - timeOf(right) || left.id.localeCompare(right.id);
}

function participantsOf(message: TimelineItem): string[] {
	return [...new Set([message.author_id, ...(message.recipient_ids ?? [])])]
		.filter((id) => id !== '')
		.sort();
}

function belongsToConversation(message: TimelineItem): boolean {
	if (threadRoot.value !== '') {
		if (message.conversation_id === threadRoot.value || message.id === threadRoot.value) {
			return true;
		}
		const parent = items.value.find((item) => item.id === message.in_reply_to_id);
		return parent?.conversation_id === threadRoot.value;
	}
	const expected = target.value.split(',').filter((id) => id !== '').sort();
	const actual = participantsOf(message).filter((id) => id !== myId.value);
	return expected.length > 0 && expected.every((id) => actual.includes(id)) && actual.length === expected.length;
}

function compose(): void {
	// A message inside a conversation must reply to the newest message. Without
	// that link the core treats it as a new conversation, so the thread splits.
	const replyTo = items.value.at(-1) ?? null;
	const account = person.value;
	if (account !== null) {
		openDirectMessage(account, replyTo);
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
		}, replyTo);
	}
}

const stopCreated = onPostCreated((created) => {
	if (created.visibility !== 'direct') {
		return;
	}

	// The create response does not always carry the conversation root, so it
	// cannot be matched against the thread reliably. Reloading the direct
	// timeline is the authoritative update and keeps the sent message visible.
	void load();
	void getApi().markConversationRead(created.id);
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
					Profile
				</a>
			</div>

			<p v-if="error !== ''" class="a-feed__inline-error" role="alert">
				{{ error }}
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">
					Try again
				</button>
			</p>

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

			<button v-if="hasMore" type="button" class="a-btn a-btn--ghost" :disabled="loadingMore" @click="loadMore">
				{{ loadingMore ? 'Loading…' : 'Load older messages' }}
			</button>

			<button v-if="items.length > 0" type="button" class="a-inline-reply" @click="compose">
				<AppIcon name="pen" :size="18" />
				<span>Write a message to {{ title }}…</span>
			</button>
		</template>
	</div>
</template>
