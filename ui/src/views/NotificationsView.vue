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
import { t } from '../lib/i18n';
import { accountName, handleLabel, notificationPostHref, relativeTime, truncate } from '../lib/format';
import { getApi, loadErrorMessage } from '../lib/api';
import { appState, setUnread, showToast } from '../lib/state';
import type { NotificationItem, NotificationSenderRequest } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const items = ref<NotificationItem[]>([]);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const cursor = ref<string | null>(null);
const hasMore = ref(true);
const mutating = ref('');
const confirmClear = ref(false);
const senderRequests = ref<NotificationSenderRequest[]>([]);
const requestsError = ref('');
const mutatingSender = ref('');

const typeMeta: Record<string, { icon: string; labelKey: string; tone: string }> = {
	favourite: { icon: 'heart', labelKey: 'notif.type.favourite', tone: 'rose' },
	follow: { icon: 'user', labelKey: 'notif.type.follow', tone: 'mint' },
	mention: { icon: 'message', labelKey: 'notif.type.mention', tone: 'violet' },
	reblog: { icon: 'repeat', labelKey: 'notif.type.reblog', tone: 'amber' },
	poll: { icon: 'hash', labelKey: 'notif.type.poll', tone: 'amber' },
	update: { icon: 'pen', labelKey: 'notif.type.update', tone: 'violet' },
	'follow_request': { icon: 'user', labelKey: 'notif.type.follow_request', tone: 'mint' },
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
		error.value = loadErrorMessage(caught, t('notif.loadFailed'));
	} finally {
		loading.value = false;
		loadingMore.value = false;
	}

	if (reset) {
		try {
			senderRequests.value = await getApi().notificationRequests();
			requestsError.value = '';
		} catch (caught) {
			requestsError.value = loadErrorMessage(caught, t('notif.requestsLoadFailed'));
		}
	}
}

async function reviewSender(id: string, decision: 'accept' | 'dismiss'): Promise<void> {
	if (mutatingSender.value !== '') {
		return;
	}
	mutatingSender.value = id;
	try {
		const count = decision === 'accept'
			? await getApi().acceptNotificationSender(id)
			: await getApi().dismissNotificationSender(id);
		senderRequests.value = senderRequests.value.filter((request) => request.id !== id);
		showToast(
			t(
				decision === 'accept'
					? (count === 1 ? 'notif.acceptedOne' : 'notif.accepted')
					: (count === 1 ? 'notif.dismissedOne' : 'notif.dismissed'),
				{ count },
			),
			'success',
		);
		await load(true);
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('notif.requestUpdateFailed')), 'error');
	} finally {
		mutatingSender.value = '';
	}
}

function conversationPostId(item: NotificationItem): string | null {
	return item.status?.id ?? item.post_id;
}

async function toggleNotificationConversation(item: NotificationItem): Promise<void> {
	const postId = conversationPostId(item);
	if (postId !== null) {
		await toggleConversationMute(postId);
	}
}

async function toggleConversationMute(postId: string): Promise<void> {
	const key = `mute:${postId}`;
	if (mutating.value !== '') {
		return;
	}
	mutating.value = key;
	try {
		const isMuted = await getApi().toggleConversationMute(postId);
		showToast(t(isMuted ? 'notif.conversationMuted' : 'notif.conversationResumed'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('notif.conversationFailed')), 'error');
	} finally {
		mutating.value = '';
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
		showToast(loadErrorMessage(caught, t('notif.dismissFailed')), 'error');
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
		showToast(t(cleared === 1 ? 'notif.clearToastOne' : 'notif.clearToast', { count: cleared }), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('notif.clearFailed')), 'error');
	} finally {
		mutating.value = '';
	}
}

function metaFor(type: string): { icon: string; labelKey: string; tone: string } {
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
	return notificationPostHref(item.status, item.post_id);
}

function statusPreview(item: NotificationItem): string {
	return item.status ? truncate(item.status.content.replace(/\s+/g, ' ').trim(), 160) : '';
}
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="t('notif.title')"
			:subtitle="appState.unread > 0 ? t('notif.unreadCount', { count: appState.unread }) : t('notif.allRead')"
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
					{{ mutating === 'clear' ? t('notif.clearing') : confirmClear ? t('notif.reallyAll') : t('notif.markAllRead') }}
				</button>
			</template>
		</PageHeader>

		<section v-if="signedIn && (senderRequests.length > 0 || requestsError !== '')" class="a-notification-requests">
			<div class="a-section-head">
				<div><h2>{{ t('notif.requestsTitle') }}</h2><p>{{ t('notif.requestsHint') }}</p></div>
				<span>{{ senderRequests.length }}</span>
			</div>
			<p v-if="requestsError !== ''" class="a-form-error" role="alert">{{ requestsError }}</p>
			<article v-for="request in senderRequests" :key="request.id" class="a-notification-request">
				<div>
					<strong>{{ handleLabel(request.actor_id) }}</strong>
					<small>{{ request.category }} · {{ relativeTime(request.created_at) }}</small>
				</div>
				<div class="a-notification-request__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="mutatingSender !== ''" @click="reviewSender(request.id, 'dismiss')">{{ t('notif.dismiss') }}</button>
					<button type="button" class="a-btn a-btn--primary a-btn--small" :disabled="mutatingSender !== ''" @click="reviewSender(request.id, 'accept')">{{ t('notif.accept') }}</button>
				</div>
			</article>
		</section>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="bell" :size="26" /></span>
			<h2>{{ t('notif.loginTitle') }}</h2>
			<p>{{ t('notif.loginText') }}</p>
			<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<TimelineSkeleton v-else-if="loading && items.length === 0" :count="3" />

		<div v-else-if="error !== '' && items.length === 0" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>{{ t('notif.unavailable') }}</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load(true)">{{ t('common.tryAgain') }}</button>
		</div>

		<EmptyState
			v-else-if="items.length === 0"
			icon="bell"
			:title="t('notif.emptyTitle')"
			:text="t('notif.emptyText')"
		>
			<a class="a-btn a-btn--primary a-btn--small" href="#/explore">{{ t('home.discoverPeople') }}</a>
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
						{{ t(metaFor(item.type).labelKey) }}
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
						{{ t('notif.viewPost') }} <AppIcon name="arrowLeft" :size="13" />
					</a>
					<button
						v-if="conversationPostId(item)"
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="mutating !== ''"
						@click="toggleNotificationConversation(item)"
					>
		{{ t('notif.toggleAlerts') }}
					</button>
				</div>
				<button
					type="button"
					class="a-icon-btn a-notification__dismiss"
					:aria-label="t('notif.dismissAria')"
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
			{{ loadingMore ? t('common.loading') : t('notif.older') }}
		</button>
	</div>
</template>
