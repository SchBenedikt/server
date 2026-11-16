<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { absoluteTime } from '../lib/format';
import { appState, showToast } from '../lib/state';
import type { FollowRequest } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const requests = ref<FollowRequest[]>([]);
const locked = ref(false);
const loading = ref(false);
const error = ref('');
const busy = ref('');

async function load(): Promise<void> {
	if (!signedIn.value) {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		const data = await getApi().followRequests();
		requests.value = data.requests;
		locked.value = data.locked;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Follow requests could not be loaded.';
	} finally {
		loading.value = false;
	}
}

async function accept(request: FollowRequest): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = request.id;
	try {
		await getApi().acceptFollowRequest(request.id);
		requests.value = requests.value.filter((entry) => entry.id !== request.id);
		showToast(`@${request.actor_handle} now follows you.`, 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The request could not be confirmed.', 'error');
	} finally {
		busy.value = '';
	}
}

async function reject(request: FollowRequest): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = request.id;
	try {
		await getApi().rejectFollowRequest(request.id);
		requests.value = requests.value.filter((entry) => entry.id !== request.id);
		showToast('Request declined.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The request could not be declined.', 'error');
	} finally {
		busy.value = '';
	}
}

onMounted(load);
watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		requests.value = [];
	}
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Follow requests"
			subtitle="People who want to follow you because your profile is protected."
			back-href="#/settings"
			back-label="Settings"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" aria-label="Reload" title="Reload" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="lock" :size="26" /></span>
			<h2>Follow requests</h2>
			<p>Sign in to see who wants to follow you.</p>
			<a class="a-btn a-btn--primary" href="/login">Sign in</a>
		</div>

		<div v-else-if="loading && requests.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>Requests unavailable</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Try again</button>
		</div>

		<div v-else class="a-follow-requests">
			<div v-if="!locked" class="a-settings__section a-follow-requests__note">
				<h2>Your profile is public</h2>
				<p class="a-settings__hint">
					New followers are added immediately. Enable protected mode in settings
					if you want to approve every request.
				</p>
				<a class="a-btn a-btn--ghost a-btn--small" href="#/settings">Go to settings</a>
			</div>

			<EmptyState
				v-if="requests.length === 0"
				icon="lock"
				title="No open requests"
				text="As soon as someone wants to follow you, the request appears here."
			/>

			<ul v-else class="a-management-people">
				<li v-for="request in requests" :key="request.id" class="a-account-row">
					<a class="a-account-row__link" :href="`#/profile/${encodeURIComponent(request.actor_handle)}`">
						<span class="a-management-avatar">
							<AppIcon name="user" :size="18" />
						</span>
						<span class="a-account-row__copy">
							<strong>@{{ request.actor_handle }}</strong>
							<small>Requested {{ absoluteTime(request.created_at) }}</small>
						</span>
					</a>
					<div class="a-account-row__actions">
						<button
							type="button"
							class="a-btn a-btn--primary a-btn--small"
							:disabled="busy === request.id"
							@click="accept(request)"
						>
							<AppIcon name="check" :size="14" />
							Confirm
						</button>
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busy === request.id"
							@click="reject(request)"
						>
							<AppIcon name="x" :size="14" />
							Decline
						</button>
					</div>
				</li>
			</ul>
		</div>
	</div>
</template>
