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
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { handleLabel, relativeTime, truncate } from '../lib/format';
import { navigate } from '../lib/router';
import { appState, openDirectMessage, showToast } from '../lib/state';
import type { Account, TimelineItem } from '../lib/types';

interface ConversationSummary {
	id: string;
	counterpart: string;
	last: TimelineItem;
	count: number;
	unread: boolean;
}

const PAGE_SIZE = 40;
const PAGES = 4;

const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const messages = ref<TimelineItem[]>([]);
const hasMore = ref(false);
const cursor = ref<{ before: string; beforeId: string } | null>(null);
const pickerOpen = ref(false);
const pickerQuery = ref('');
const pickerLoading = ref(false);
const pickerResults = ref<Account[]>([]);
let pickerTimer: number | null = null;

const signedIn = computed(() => appState.session.user !== null);
const myId = computed(() => appState.session.user?.handle ?? '');

const conversations = computed<ConversationSummary[]>(() => {
	const groups = new Map<string, ConversationSummary>();
	for (const message of messages.value) {
		if (message.visibility !== 'direct') {
			continue;
		}

		const counterpart = counterpartOf(message);
		if (counterpart === '') {
			continue;
		}

		const id = message.conversation_id ?? `participants:${counterpart}`;
		const existing = groups.get(id);
		if (existing === undefined) {
			groups.set(id, { id, counterpart, last: message, count: 1, unread: message.unread === true });
			continue;
		}

		existing.count += 1;
		existing.unread ||= message.unread === true;
		if (compareOldToNew(message, existing.last) > 0) {
			existing.last = message;
		}
	}

	return [...groups.values()]
		.filter((conversation) => conversation.last.dismissed !== true)
		.sort((left, right) => compareOldToNew(right.last, left.last));
});

onMounted(() => {
	if (signedIn.value) {
		void load();
	}
});

watch(signedIn, (value) => {
	if (value && messages.value.length === 0 && !loading.value) {
		void load();
	}
});

function timeOf(item: TimelineItem): number {
	const value = new Date(item.published_at).getTime();
	return Number.isNaN(value) ? 0 : value;
}

function compareOldToNew(left: TimelineItem, right: TimelineItem): number {
	return timeOf(left) - timeOf(right) || left.id.localeCompare(right.id);
}

function counterpartOf(message: TimelineItem): string {
	const participants = new Set<string>([message.author_id, ...(message.recipient_ids ?? [])]);
	participants.delete(myId.value);
	const remaining = [...participants].filter((id) => id !== '');
	if (remaining.length === 0) {
		return message.author_id === myId.value ? (message.recipient_ids?.[0] ?? '') : message.author_id;
	}

	return remaining.sort().join(',');
}

function counterpartLabel(counterpart: string): string {
	const first = counterpart.split(',')[0] ?? '';
	return first === '' ? '@unknown' : handleLabel(first);
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	cursor.value = null;
	hasMore.value = false;
	try {
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
		messages.value = collected;

		for (const conversation of conversations.value) {
			void resolveAccount(conversation.counterpart.split(',')[0] ?? '');
		}
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Messages could not be loaded.';
	} finally {
		loading.value = false;
	}
}

async function loadMore(): Promise<void> {
	if (loadingMore.value || !hasMore.value || cursor.value === null) {
		return;
	}

	loadingMore.value = true;
	error.value = '';
	try {
		const result = await getApi().directTimelinePage(PAGE_SIZE, cursor.value.before, cursor.value.beforeId);
		const seen = new Set(messages.value.map((message) => message.id));
		messages.value = [...messages.value, ...result.posts.filter((message) => !seen.has(message.id))];
		cursor.value = result.nextCursor;
		hasMore.value = result.hasMore;
		for (const conversation of conversations.value) {
			void resolveAccount(conversation.counterpart.split(',')[0] ?? '');
		}
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Older messages could not be loaded.';
	} finally {
		loadingMore.value = false;
	}
}

function openConversation(conversation: ConversationSummary): void {
	void getApi().markConversationRead(conversation.last.id).catch((caught: unknown) => {
		showToast(caught instanceof Error ? caught.message : 'The conversation could not be marked as read.', 'error');
	});
	navigate(`/messages/${encodeURIComponent(`thread:${conversation.id}`)}`);
}

async function dismissConversation(conversation: ConversationSummary): Promise<void> {
	try {
		await getApi().dismissConversation(conversation.last.id);
		messages.value = messages.value.map((message) => message.conversation_id === conversation.id
			? { ...message, dismissed: true }
			: message);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The conversation could not be dismissed.', 'error');
	}
}

function preview(message: TimelineItem): string {
	const text = message.content.trim();
	return text !== '' ? truncate(text, 90) : 'attachment';
}

function openPicker(): void {
	pickerOpen.value = true;
	pickerQuery.value = '';
	pickerResults.value = [];
}

function closePicker(): void {
	pickerOpen.value = false;
	pickerQuery.value = '';
	pickerResults.value = [];
}

function searchPeople(): void {
	if (pickerTimer !== null) {
		window.clearTimeout(pickerTimer);
	}

	const term = pickerQuery.value.trim();
	if (term === '') {
		pickerResults.value = [];
		return;
	}

	pickerTimer = window.setTimeout(async () => {
		pickerLoading.value = true;
		try {
			const results = await getApi().search(term, 12);
			pickerResults.value = results.accounts.filter((account) => account.id !== myId.value);
		} catch (caught) {
			showToast(caught instanceof Error ? caught.message : 'The search failed.', 'error');
			pickerResults.value = [];
		} finally {
			pickerLoading.value = false;
		}
	}, 250);
}

function startWith(account: Account): void {
	closePicker();
	openDirectMessage(account);
	navigate(`/messages/${encodeURIComponent(account.id)}`);
}
</script>

<template>
	<div class="a-page">
		<PageHeader title="Messages" subtitle="Private conversations between accounts on this instance.">
			<template #actions>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="openPicker">
					<AppIcon name="pen" :size="15" />
					New message
				</button>
			</template>
		</PageHeader>

		<p v-if="!signedIn" class="a-muted">Sign in to see your messages.</p>

		<template v-else>
			<div v-if="pickerOpen" class="a-dm-picker">
				<header class="a-section-head">
					<h2>Send to?</h2>
					<button type="button" class="a-icon-btn" aria-label="Close selection" @click="closePicker">
						<AppIcon name="x" :size="16" />
					</button>
				</header>
				<div class="a-search a-search--compact">
					<AppIcon name="search" :size="18" />
					<input
						v-model="pickerQuery"
						type="search"
						placeholder="Name or @account"
						aria-label="Search accounts"
						@input="searchPeople"
					/>
				</div>
				<p v-if="pickerLoading" class="a-muted">Searching…</p>
				<ul v-else-if="pickerResults.length > 0" class="a-people">
					<li v-for="account in pickerResults" :key="account.id" class="a-person">
						<button type="button" class="a-person__link" @click="startWith(account)">
							<Avatar :id="account.id" :name="account.display_name" :src="account.avatar" :size="38" />
							<span class="a-person__copy">
								<strong>{{ account.display_name }}</strong>
								<small>{{ account.handle }}</small>
							</span>
						</button>
					</li>
				</ul>
				<p v-else class="a-muted">Type a name to find an account.</p>
			</div>

			<p v-if="error !== ''" class="a-feed__inline-error">{{ error }}</p>

			<TimelineSkeleton v-if="loading && conversations.length === 0" :count="3" />

			<ul v-else-if="conversations.length > 0" class="a-dm-list">
				<li v-for="conversation in conversations" :key="conversation.id" class="a-dm-list__item">
					<button type="button" class="a-dm-row" @click="openConversation(conversation)">
						<Avatar
							:id="conversation.counterpart.split(',')[0] ?? ''"
							:name="counterpartLabel(conversation.counterpart)"
							:size="44"
						/>
						<span class="a-dm-row__copy">
							<strong>{{ counterpartLabel(conversation.counterpart) }} <span v-if="conversation.unread" class="a-dm-unread">Unread</span></strong>
							<small>{{ preview(conversation.last) }}</small>
						</span>
						<span class="a-dm-row__meta">
							<time :datetime="conversation.last.published_at">{{ relativeTime(conversation.last.published_at) }}</time>
							<small v-if="conversation.count > 1">{{ conversation.count }}</small>
						</span>
					</button>
					<button type="button" class="a-icon-btn a-dm-dismiss" :aria-label="`Dismiss conversation with ${counterpartLabel(conversation.counterpart)}`" @click="dismissConversation(conversation)">
						<AppIcon name="x" :size="15" />
					</button>
				</li>
			</ul>

			<button v-if="hasMore" type="button" class="a-btn a-btn--ghost" :disabled="loadingMore" @click="loadMore">
				{{ loadingMore ? 'Loading…' : 'Load older messages' }}
			</button>

			<EmptyState
				v-else-if="!loading"
				icon="message"
				title="No messages yet"
				text="Private messages stay between the accounts on this instance. Start a new conversation."
			>
				<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openPicker">New message</button>
			</EmptyState>
		</template>
	</div>
</template>
