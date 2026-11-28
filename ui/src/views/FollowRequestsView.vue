<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi, loadErrorMessage } from '../lib/api';
import { absoluteTime } from '../lib/format';
import { t } from '../lib/i18n';
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
		error.value = loadErrorMessage(caught, t('requests.loadFailed'));
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
		showToast(t('requests.accepted', { handle: request.actor_handle }), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('requests.acceptFailed')), 'error');
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
		showToast(t('requests.declined'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('requests.declineFailed')), 'error');
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
			:title="t('nav.followRequests')"
			:subtitle="t('requests.subtitle')"
			back-href="#/settings"
			:back-label="t('nav.settings')"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('common.reload')" :title="t('common.reload')" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="lock" :size="26" /></span>
			<h2>{{ t('nav.followRequests') }}</h2>
			<p>{{ t('requests.loginText') }}</p>
			<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<div v-else-if="loading && requests.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>{{ t('requests.unavailable') }}</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</div>

		<div v-else class="a-follow-requests">
			<div v-if="!locked" class="a-settings__section a-follow-requests__note">
				<h2>{{ t('requests.publicTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('requests.publicText') }}
				</p>
				<a class="a-btn a-btn--ghost a-btn--small" href="#/settings">{{ t('requests.goToSettings') }}</a>
			</div>

			<EmptyState
				v-if="requests.length === 0"
				icon="lock"
				:title="t('requests.emptyTitle')"
				:text="t('requests.emptyText')"
			/>

			<ul v-else class="a-management-people">
				<li v-for="request in requests" :key="request.id" class="a-account-row">
					<a class="a-account-row__link" :href="`#/profile/${encodeURIComponent(request.actor_handle)}`">
						<span class="a-management-avatar">
							<AppIcon name="user" :size="18" />
						</span>
						<span class="a-account-row__copy">
							<strong>@{{ request.actor_handle }}</strong>
							<small>{{ t('requests.requested', { time: absoluteTime(request.created_at) }) }}</small>
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
							{{ t('common.confirm') }}
						</button>
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busy === request.id"
							@click="reject(request)"
						>
							<AppIcon name="x" :size="14" />
							{{ t('requests.decline') }}
						</button>
					</div>
				</li>
			</ul>
		</div>
	</div>
</template>
