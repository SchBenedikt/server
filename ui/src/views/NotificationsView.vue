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
import { accountName, handleLabel, postHref, relativeTime, truncate } from '../lib/format';
import { getApi } from '../lib/api';
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

const typeMeta: Record<string, { icon: string; label: string; tone: string }> = {
	favourite: { icon: 'heart', label: 'hat deinen Beitrag favorisiert', tone: 'rose' },
	follow: { icon: 'user', label: 'folgt dir jetzt', tone: 'mint' },
	mention: { icon: 'message', label: 'hat dich erwähnt', tone: 'violet' },
	reblog: { icon: 'repeat', label: 'hat deinen Beitrag geboostet', tone: 'amber' },
	poll: { icon: 'hash', label: 'hat an deiner Umfrage teilgenommen', tone: 'amber' },
	update: { icon: 'pen', label: 'hat einen Beitrag bearbeitet', tone: 'violet' },
	'follow_request': { icon: 'user', label: 'möchte dir folgen', tone: 'mint' },
};

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
		const page = await getApi().mastodonNotifications(20, reset ? null : cursor.value);
		if (reset) {
			items.value = page;
		} else {
			const seen = new Set(items.value.map((item) => item.id));
			items.value = [...items.value, ...page.filter((item) => !seen.has(item.id))];
		}
		cursor.value = page.length > 0 ? page[page.length - 1].id : cursor.value;
		hasMore.value = page.length === 20;
		try {
			setUnread(await getApi().unreadCount());
		} catch {
			// The Mastodon surface has no unread counter; the core one is optional.
		}
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

function metaFor(type: string): { icon: string; label: string; tone: string } {
	return typeMeta[type] ?? typeMeta.mention;
}

function actorId(item: NotificationItem): string {
	return item.account?.id ?? item.actor_id;
}

function actorName(item: NotificationItem): string {
	return item.account ? accountName(item.account, actorId(item)) : handleLabel(item.actor_id);
}

function actorHandle(item: NotificationItem): string {
	return handleLabel(item.account?.handle ?? item.actor_id);
}

function actorHref(item: NotificationItem): string {
	const account = item.account;
	if (account && account.is_remote === true && account.url !== '') {
		return account.url;
	}

	return `#/u/${encodeURIComponent(account?.handle ?? item.actor_id)}`;
}

function actorIsExternal(item: NotificationItem): boolean {
	return item.account?.is_remote === true;
}

function statusHref(item: NotificationItem): string | null {
	return item.status ? postHref(item.status) : (item.post_id ? `#/p/${encodeURIComponent(item.post_id)}` : null);
}

function statusPreview(item: NotificationItem): string {
	return item.status ? truncate(item.status.content.replace(/\s+/g, ' ').trim(), 160) : '';
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
			<li v-for="item in items" :key="item.id" class="a-notification" :class="{ 'a-notification--unread': !item.read }">
				<span class="a-notification__badge" :class="`a-notification__badge--${metaFor(item.type).tone}`">
					<AppIcon :name="metaFor(item.type).icon" :size="16" />
				</span>
				<a
					:href="actorHref(item)"
					class="a-notification__avatar"
					:target="actorIsExternal(item) ? '_blank' : undefined"
					:rel="actorIsExternal(item) ? 'noopener noreferrer' : undefined"
				>
					<Avatar :id="actorId(item)" :name="actorName(item)" :src="item.account?.avatar" :size="38" />
				</a>
				<div class="a-notification__body">
					<p>
						<a
							:href="actorHref(item)"
							class="a-notification__actor"
							:target="actorIsExternal(item) ? '_blank' : undefined"
							:rel="actorIsExternal(item) ? 'noopener noreferrer' : undefined"
						>{{ actorName(item) }}</a>
						{{ metaFor(item.type).label }}
					</p>
					<p class="a-notification__meta">
						<span>{{ actorHandle(item) }}</span>
						<span aria-hidden="true">·</span>
						<time :datetime="item.created_at">{{ relativeTime(item.created_at) }}</time>
					</p>

					<a v-if="statusPreview(item) !== ''" :href="statusHref(item) ?? '#'" class="a-notification__excerpt">
						{{ statusPreview(item) }}
					</a>

					<a
						v-if="statusHref(item)"
						:href="statusHref(item) ?? '#'"
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
