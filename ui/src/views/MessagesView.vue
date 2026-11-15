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
	counterpart: string;
	last: TimelineItem;
	count: number;
}

const PAGE_SIZE = 40;
const PAGES = 4;

const loading = ref(false);
const error = ref('');
const messages = ref<TimelineItem[]>([]);
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

		const existing = groups.get(counterpart);
		if (existing === undefined) {
			groups.set(counterpart, { counterpart, last: message, count: 1 });
			continue;
		}

		existing.count += 1;
		if (timeOf(message) > timeOf(existing.last)) {
			existing.last = message;
		}
	}

	return [...groups.values()].sort((left, right) => timeOf(right.last) - timeOf(left.last));
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
	return first === '' ? '@unbekannt' : handleLabel(first);
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
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

		// The core's home timeline omits recipient ids, so direct posts have to
		// be hydrated before a conversation can be identified.
		const direct = collected.filter((message) => message.visibility === 'direct');
		const hydrated = direct.length > 0 ? await getApi().posts(direct.map((message) => message.id)) : {};
		messages.value = direct.map((message) => {
			const full = hydrated[message.id];
			return full === undefined
				? message
				: { ...message, ...full, interaction: message.interaction ?? full.interaction };
		});

		for (const conversation of conversations.value) {
			void resolveAccount(conversation.counterpart.split(',')[0] ?? '');
		}
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Die Nachrichten konnten nicht geladen werden.';
	} finally {
		loading.value = false;
	}
}

function openConversation(counterpart: string): void {
	navigate(`/messages/${encodeURIComponent(counterpart)}`);
}

function preview(message: TimelineItem): string {
	const text = message.content.trim();
	return text !== '' ? truncate(text, 90) : 'Anhang';
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
			showToast(caught instanceof Error ? caught.message : 'Die Suche ist fehlgeschlagen.', 'error');
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
		<PageHeader title="Nachrichten" subtitle="Private Unterhaltungen zwischen Konten dieser Instanz.">
			<template #actions>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="openPicker">
					<AppIcon name="pen" :size="15" />
					Neue Nachricht
				</button>
			</template>
		</PageHeader>

		<p v-if="!signedIn" class="a-muted">Melde dich an, um deine Nachrichten zu sehen.</p>

		<template v-else>
			<div v-if="pickerOpen" class="a-dm-picker">
				<header class="a-section-head">
					<h2>An wen?</h2>
					<button type="button" class="a-icon-btn" aria-label="Auswahl schließen" @click="closePicker">
						<AppIcon name="x" :size="16" />
					</button>
				</header>
				<div class="a-search a-search--compact">
					<AppIcon name="search" :size="18" />
					<input
						v-model="pickerQuery"
						type="search"
						placeholder="Name oder @konto"
						aria-label="Konto suchen"
						@input="searchPeople"
					/>
				</div>
				<p v-if="pickerLoading" class="a-muted">Suche läuft …</p>
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
				<p v-else class="a-muted">Tippe einen Namen ein, um ein Konto zu finden.</p>
			</div>

			<p v-if="error !== ''" class="a-feed__inline-error">{{ error }}</p>

			<TimelineSkeleton v-if="loading && conversations.length === 0" :count="3" />

			<ul v-else-if="conversations.length > 0" class="a-dm-list">
				<li v-for="conversation in conversations" :key="conversation.counterpart">
					<button type="button" class="a-dm-row" @click="openConversation(conversation.counterpart)">
						<Avatar
							:id="conversation.counterpart.split(',')[0] ?? ''"
							:name="counterpartLabel(conversation.counterpart)"
							:size="44"
						/>
						<span class="a-dm-row__copy">
							<strong>{{ counterpartLabel(conversation.counterpart) }}</strong>
							<small>{{ preview(conversation.last) }}</small>
						</span>
						<span class="a-dm-row__meta">
							<time :datetime="conversation.last.published_at">{{ relativeTime(conversation.last.published_at) }}</time>
							<small v-if="conversation.count > 1">{{ conversation.count }}</small>
						</span>
					</button>
				</li>
			</ul>

			<EmptyState
				v-else-if="!loading"
				icon="message"
				title="Noch keine Nachrichten"
				text="Private Nachrichten bleiben zwischen den beteiligten Konten dieser Instanz. Beginne eine neue Unterhaltung."
			>
				<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openPicker">Neue Nachricht</button>
			</EmptyState>
		</template>
	</div>
</template>
