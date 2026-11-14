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
import { displayNameFor, hasDistinctDisplayName, resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { handleLabel, relativeTime } from '../lib/format';
import { appState, setUnread, showToast } from '../lib/state';
import type { NotificationItem } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const items = ref<NotificationItem[]>([]);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const cursor = ref<string | null>(null);
const hasMore = ref(true);
const mutating = ref('');
const confirmClear = ref(false);

const typeMeta: Record<NotificationItem['type'], { icon: string; label: string; tone: string }> = {
	favourite: { icon: 'heart', label: 'hat deinen Beitrag favorisiert', tone: 'rose' },
	follow: { icon: 'user', label: 'folgt dir jetzt', tone: 'mint' },
	mention: { icon: 'message', label: 'hat dich erwähnt', tone: 'violet' },
	reblog: { icon: 'repeat', label: 'hat deinen Beitrag geboostet', tone: 'amber' },
};

watch(items, (list) => {
	for (const item of list) {
		void resolveAccount(item.actor_id);
	}
}, { immediate: true, deep: true });

onMounted(() => {
	if (signedIn.value) {
		void load(true);
	}
});

watch(signedIn, (value) => {
	if (value) {
		void load(true);
	} else {
		items.value = [];
	}
});

async function load(reset: boolean): Promise<void> {
	if (reset) {
		loading.value = true;
		error.value = '';
	} else {
		loadingMore.value = true;
	}

	try {
		const page = await getApi().notifications(20, reset ? null : cursor.value);
		if (reset) {
			items.value = page.notifications;
			cursor.value = page.notifications.length > 0 ? page.notifications[page.notifications.length - 1].id : null;
		} else {
			const seen = new Set(items.value.map((item) => item.id));
			items.value = [...items.value, ...page.notifications.filter((item) => !seen.has(item.id))];
			cursor.value = page.notifications.length > 0 ? page.notifications[page.notifications.length - 1].id : cursor.value;
		}
		hasMore.value = page.notifications.length >= 20;
		setUnread(page.unread_count);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Benachrichtigungen konnten nicht geladen werden.';
	} finally {
		loading.value = false;
		loadingMore.value = false;
	}
}

async function dismiss(item: NotificationItem): Promise<void> {
	if (mutating.value !== '') {
		return;
	}

	mutating.value = item.id;
	try {
		await getApi().dismissNotification(item.id);
		items.value = items.value.filter((entry) => entry.id !== item.id);
		appState.unread = Math.max(0, appState.unread - 1);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Benachrichtigung konnte nicht entfernt werden.', 'error');
	} finally {
		mutating.value = '';
	}
}

async function clearAll(): Promise<void> {
	if (!confirmClear.value) {
		confirmClear.value = true;
		window.setTimeout(() => {
			confirmClear.value = false;
		}, 3500);
		return;
	}

	mutating.value = 'clear';
	try {
		const cleared = await getApi().clearNotifications();
		items.value = [];
		hasMore.value = false;
		setUnread(0);
		confirmClear.value = false;
		showToast(`${cleared} ${cleared === 1 ? 'Benachrichtigung' : 'Benachrichtigungen'} als gelesen markiert.`, 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Benachrichtigungen konnten nicht geleert werden.', 'error');
	} finally {
		mutating.value = '';
	}
}

function metaFor(type: NotificationItem['type']): { icon: string; label: string; tone: string } {
	return typeMeta[type] ?? typeMeta.mention;
}
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Benachrichtigungen"
			:subtitle="appState.unread > 0 ? `${appState.unread} ungelesen` : 'Alles gelesen.'"
		>
			<template #actions>
				<button
					v-if="signedIn && items.length > 0"
					type="button"
					class="a-btn a-btn--small"
					:class="confirmClear ? 'a-btn--danger' : 'a-btn--ghost'"
					:disabled="mutating === 'clear'"
					@click="clearAll"
				>
					<AppIcon name="check" :size="15" />
					{{ mutating === 'clear' ? 'Wird geleert…' : confirmClear ? 'Wirklich alle?' : 'Alle als gelesen' }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="bell" :size="26" /></span>
			<h2>Verpasse nichts</h2>
			<p>Melde dich an, um zu sehen, wer dir folgt, deine Beiträge teilt oder dich erwähnt.</p>
			<a class="a-btn a-btn--primary" href="/login">Anmelden</a>
		</div>

		<TimelineSkeleton v-else-if="loading && items.length === 0" :count="3" />

		<div v-else-if="error !== '' && items.length === 0" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>Benachrichtigungen nicht erreichbar</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load(true)">Erneut versuchen</button>
		</div>

		<EmptyState
			v-else-if="items.length === 0"
			icon="bell"
			title="Noch keine Benachrichtigungen"
			text="Sobald jemand auf dich reagiert, erscheint es hier."
		>
			<a class="a-btn a-btn--primary a-btn--small" href="#/explore">Menschen entdecken</a>
		</EmptyState>

		<ol v-else class="a-notifications">
			<li v-for="item in items" :key="item.id" class="a-notification">
				<span class="a-notification__badge" :class="`a-notification__badge--${metaFor(item.type).tone}`">
					<AppIcon :name="metaFor(item.type).icon" :size="16" />
				</span>
				<a :href="`#/u/${encodeURIComponent(item.actor_id)}`" class="a-notification__avatar">
					<Avatar :id="item.actor_id" :name="displayNameFor(item.actor_id)" :size="38" />
				</a>
				<div class="a-notification__body">
					<p>
						<a :href="`#/u/${encodeURIComponent(item.actor_id)}`" class="a-notification__actor">
							{{ displayNameFor(item.actor_id) }}
						</a>
						{{ metaFor(item.type).label }}
					</p>
					<p class="a-notification__meta">
						<template v-if="hasDistinctDisplayName(item.actor_id)">
							<span>{{ handleLabel(item.actor_id) }}</span>
							<span aria-hidden="true">·</span>
						</template>
						<time :datetime="item.created_at">{{ relativeTime(item.created_at) }}</time>
					</p>
					<a
						v-if="item.post_id"
						:href="`#/p/${encodeURIComponent(item.post_id)}`"
						class="a-notification__post-link"
					>
						Beitrag ansehen <AppIcon name="arrowLeft" :size="13" />
					</a>
				</div>
				<button
					type="button"
					class="a-icon-btn a-notification__dismiss"
					aria-label="Benachrichtigung ausblenden"
					:disabled="mutating === item.id"
					@click="dismiss(item)"
				>
					<AppIcon name="x" :size="15" />
				</button>
			</li>
		</ol>

		<button
			v-if="hasMore && items.length > 0"
			type="button"
			class="a-btn a-btn--ghost a-feed__more"
			:disabled="loadingMore"
			@click="load(false)"
		>
			{{ loadingMore ? 'Wird geladen…' : 'Ältere Benachrichtigungen' }}
		</button>
	</div>
</template>
