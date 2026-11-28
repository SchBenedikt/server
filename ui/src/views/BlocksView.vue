<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AccountRow from '../components/AccountRow.vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { handleLabel, relativeTime } from '../lib/format';
import { t } from '../lib/i18n';
import { appState, showToast } from '../lib/state';
import type { Account, BlockEntry } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const entries = ref<BlockEntry[]>([]);
const loading = ref(false);
const error = ref('');
const busy = ref('');
const profiles = reactive<Record<string, Account | null>>({});

onMounted(() => {
	if (signedIn.value) {
		void load();
	}
});

watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		entries.value = [];
	}
});

function localHandle(actor: string): string | null {
	if (typeof window === 'undefined') {
		return null;
	}

	try {
		const url = new URL(actor);
		if (url.origin !== window.location.origin) {
			return null;
		}

		const match = url.pathname.match(/^\/@([^/]+)$/);
		return match ? decodeURIComponent(match[1]) : null;
	} catch {
		return null;
	}
}

function actorText(actor: string): string {
	const local = localHandle(actor);
	if (local !== null) {
		return `@${local}`;
	}

	try {
		const url = new URL(actor);
		const segment = decodeURIComponent(url.pathname.split('/').filter(Boolean).pop() ?? '');
		const name = segment.replace(/^@/, '');
		return name !== '' ? `@${name}@${url.hostname.replace(/^www\./, '')}` : actor;
	} catch {
		return actor;
	}
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		entries.value = await getApi().blocks();
		void resolveProfiles();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('blocks.loadFailed');
	} finally {
		loading.value = false;
	}
}

async function resolveProfiles(): Promise<void> {
	for (const entry of entries.value) {
		const local = localHandle(entry.actor);
		if (local === null || Object.prototype.hasOwnProperty.call(profiles, local)) {
			continue;
		}

		profiles[local] = null;
		try {
			profiles[local] = await getApi().account(local);
		} catch {
			profiles[local] = null;
		}
	}
}

async function unblock(entry: BlockEntry): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = entry.id;
	try {
		await getApi().unblockActor(entry.id);
		entries.value = entries.value.filter((item) => item.id !== entry.id);
		showToast(t('blocks.removed'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('blocks.removeFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

function accountFor(entry: BlockEntry): Account | null {
	const local = localHandle(entry.actor);
	return local === null ? null : (profiles[local] ?? null);
}
</script>

<template>
	<div class="a-page">
		<PageHeader :title="t('nav.blocked')" :subtitle="t('blocks.subtitle')">
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('common.reload')" :title="t('common.reload')" :disabled="loading" @click="load">
					<AppIcon name="refresh" :size="19" :class="{ 'a-spin': loading }" />
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="ban" :size="26" /></span>
			<h2>{{ t('nav.blocked') }}</h2>
			<p>{{ t('blocks.loginText') }}</p>
			<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<div v-else-if="loading && entries.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>{{ t('blocks.unavailable') }}</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</div>

		<EmptyState
			v-else-if="entries.length === 0"
			icon="ban"
			:title="t('blocks.emptyTitle')"
			:text="t('blocks.emptyText')"
		/>

		<ul v-else class="a-management-people">
			<li v-for="entry in entries" :key="entry.id">
				<AccountRow v-if="accountFor(entry)" :account="accountFor(entry) as Account">
					<template #actions>
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busy === entry.id"
							@click="unblock(entry)"
						>
							<AppIcon name="ban" :size="14" />
							{{ busy === entry.id ? t('common.removing') : t('blocks.unblock') }}
						</button>
					</template>
				</AccountRow>
				<div v-else class="a-account-row">
					<div class="a-account-row__link">
						<span class="a-management-avatar" aria-hidden="true"><AppIcon name="ban" :size="18" /></span>
						<span class="a-account-row__copy">
							<strong>{{ actorText(entry.actor) }}</strong>
							<small>{{ handleLabel(entry.actor) !== actorText(entry.actor) ? handleLabel(entry.actor) : t('blocks.since', { time: relativeTime(entry.blocked_at) }) }}</small>
						</span>
					</div>
					<div class="a-account-row__actions">
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busy === entry.id"
							@click="unblock(entry)"
						>
							<AppIcon name="ban" :size="14" />
							{{ busy === entry.id ? 'Removing…' : 'Unblock account' }}
						</button>
					</div>
				</div>
			</li>
		</ul>

		<p v-if="entries.length > 0" class="a-management-footnote">
			{{ t('blocks.footnote') }}
		</p>
	</div>
</template>
