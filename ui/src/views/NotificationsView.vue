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

const typeMeta: Record<string, { icon: string; label: string; tone: string }> = {
	favourite: { icon: 'heart', label: 'liked your post', tone: 'rose' },
	follow: { icon: 'user', label: 'now follows you', tone: 'mint' },
	mention: { icon: 'message', label: 'mentioned you', tone: 'violet' },
	reblog: { icon: 'repeat', label: 'boosted your post', tone: 'amber' },
	poll: { icon: 'hash', label: 'voted in your poll', tone: 'amber' },
	update: { icon: 'pen', label: 'edited a post', tone: 'violet' },
	'follow_request': { icon: 'user', label: 'wants to follow you', tone: 'mint' },
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
		error.value = caught instanceof Error ? caught.message : 'Notifications could not be loaded.';
	} finally {
		loading.value = false;
		loadingMore.value = false;
	}

	if (reset) {
		try {
			senderRequests.value = await getApi().notificationRequests();
			requestsError.value = '';
		} catch (caught) {
			requestsError.value = caught instanceof Error ? caught.message : 'Notification requests could not be loaded.';
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
			decision === 'accept'
				? `Sender accepted; ${count} held ${count === 1 ? 'notification was' : 'notifications were'} released.`
				: `Sender dismissed; ${count} held ${count === 1 ? 'notification was' : 'notifications were'} suppressed.`,
			'success',
		);
		await load(true);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The sender request could not be updated.', 'error');
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
		showToast(isMuted ? 'Conversation notifications muted.' : 'Conversation notifications resumed.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The conversation setting could not be updated.', 'error');
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
		showToast(caught instanceof Error ? caught.message : 'The notification could not be removed.', 'error');
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
		showToast(`${cleared} ${cleared === 1 ? 'notification' : 'Notifications'} marked as read.`, 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Notifications could not be cleared.', 'error');
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
			title="Notifications"
			:subtitle="appState.unread > 0 ? `${appState.unread} unread` : 'All read.'"
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
					{{ mutating === 'clear' ? 'Clearing…' : confirmClear ? 'Really all?' : 'Mark all as read' }}
				</button>
			</template>
		</PageHeader>

		<section v-if="signedIn && (senderRequests.length > 0 || requestsError !== '')" class="a-notification-requests">
			<div class="a-section-head">
				<div><h2>Notification requests</h2><p>Review senders whose notifications are held by your policy.</p></div>
				<span>{{ senderRequests.length }}</span>
			</div>
			<p v-if="requestsError !== ''" class="a-form-error" role="alert">{{ requestsError }}</p>
			<article v-for="request in senderRequests" :key="request.id" class="a-notification-request">
				<div>
					<strong>{{ handleLabel(request.actor_id) }}</strong>
					<small>{{ request.category }} · {{ relativeTime(request.created_at) }}</small>
				</div>
				<div class="a-notification-request__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="mutatingSender !== ''" @click="reviewSender(request.id, 'dismiss')">Dismiss</button>
					<button type="button" class="a-btn a-btn--primary a-btn--small" :disabled="mutatingSender !== ''" @click="reviewSender(request.id, 'accept')">Accept</button>
				</div>
			</article>
		</section>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="bell" :size="26" /></span>
			<h2>Don't miss a thing</h2>
			<p>Sign in to see who follows you, shares your posts or mentions you.</p>
			<a class="a-btn a-btn--primary" href="/login">Sign in</a>
		</div>

		<TimelineSkeleton v-else-if="loading && items.length === 0" :count="3" />

		<div v-else-if="error !== '' && items.length === 0" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>Notifications unavailable</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load(true)">Try again</button>
		</div>

		<EmptyState
			v-else-if="items.length === 0"
			icon="bell"
			title="No notifications yet"
			text="As soon as someone interacts with you, it appears here."
		>
			<a class="a-btn a-btn--primary a-btn--small" href="#/explore">Discover people</a>
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
						View post <AppIcon name="arrowLeft" :size="13" />
					</a>
					<button
						v-if="conversationPostId(item)"
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="mutating !== ''"
						@click="toggleNotificationConversation(item)"
					>
		Toggle conversation alerts
					</button>
				</div>
				<button
					type="button"
					class="a-icon-btn a-notification__dismiss"
					aria-label="Dismiss notification"
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
			{{ loadingMore ? 'Loading…' : 'Older notifications' }}
		</button>
	</div>
</template>
