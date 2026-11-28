<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AccountRow from '../components/AccountRow.vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { t } from '../lib/i18n';
import { appState, showToast } from '../lib/state';
import type { Account } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const accounts = ref<Account[]>([]);
const loading = ref(false);
const error = ref('');
const busy = ref('');

onMounted(() => {
	if (signedIn.value) {
		void load();
	}
});

watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		accounts.value = [];
	}
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		accounts.value = await getApi().mutes();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('mutes.loadFailed');
	} finally {
		loading.value = false;
	}
}

async function unmute(account: Account): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = account.id;
	try {
		await getApi().unmuteAccount(account.id);
		accounts.value = accounts.value.filter((entry) => entry.id !== account.id);
		showToast(t('mutes.removed'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('mutes.removeFailed'), 'error');
	} finally {
		busy.value = '';
	}
}
</script>

<template>
	<div class="a-page">
		<PageHeader :title="t('nav.muted')" :subtitle="t('mutes.subtitle')">
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('common.reload')" :title="t('common.reload')" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="bellOff" :size="26" /></span>
			<h2>{{ t('nav.muted') }}</h2>
			<p>{{ t('mutes.loginText') }}</p>
			<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<div v-else-if="loading && accounts.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>{{ t('mutes.unavailable') }}</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</div>

		<EmptyState
			v-else-if="accounts.length === 0"
			icon="bellOff"
			:title="t('mutes.emptyTitle')"
			:text="t('mutes.emptyText')"
		/>

		<ul v-else class="a-management-people">
			<li v-for="account in accounts" :key="account.id">
				<AccountRow :account="account">
					<template #actions>
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busy === account.id"
							@click="unmute(account)"
						>
							<AppIcon name="bellOff" :size="14" />
							{{ busy === account.id ? t('common.removing') : t('mutes.unmute') }}
						</button>
					</template>
				</AccountRow>
			</li>
		</ul>
	</div>
</template>
