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
import type { FilterAction, FilterContext, FilterKeyword, FilterPayload, FilterRule } from '../lib/types';

interface KeywordDraft {
	text: string;
	whole_word: boolean;
}

const CONTEXTS: { value: FilterContext; labelKey: string }[] = [
	{ value: 'home', labelKey: 'nav.home' },
	{ value: 'public', labelKey: 'filters.ctx.public' },
	{ value: 'notifications', labelKey: 'nav.notifications' },
	{ value: 'thread', labelKey: 'filters.ctx.thread' },
	{ value: 'account', labelKey: 'nav.profile' },
];

const signedIn = computed(() => appState.session.user !== null);
const rules = ref<FilterRule[]>([]);
const loading = ref(false);
const error = ref('');
const busy = ref('');
const confirmDelete = ref('');

const dialogOpen = ref(false);
const editing = ref<FilterRule | null>(null);
const name = ref('');
const action = ref<FilterAction>('warn');
const contexts = ref<FilterContext[]>(['home']);
const keywords = ref<KeywordDraft[]>([{ text: '', whole_word: false }]);
const expiresAt = ref('');
const dialogError = ref('');
const saving = ref(false);

onMounted(() => {
	if (signedIn.value) {
		void load();
	}
});

watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		rules.value = [];
	}
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		rules.value = await getApi().filters();
	} catch (caught) {
		error.value = loadErrorMessage(caught, t('filters.loadFailed'));
	} finally {
		loading.value = false;
	}
}

function openCreate(): void {
	editing.value = null;
	name.value = '';
	action.value = 'warn';
	contexts.value = ['home'];
	keywords.value = [{ text: '', whole_word: false }];
	expiresAt.value = '';
	dialogError.value = '';
	dialogOpen.value = true;
}

function openEdit(rule: FilterRule): void {
	editing.value = rule;
	name.value = rule.name;
	action.value = rule.action;
	contexts.value = [...rule.contexts];
	keywords.value = rule.keywords.length > 0
		? rule.keywords.map((keyword) => ({ ...keyword }))
		: [{ text: '', whole_word: false }];
	expiresAt.value = rule.expires_at === null ? '' : toLocalInput(rule.expires_at);
	dialogError.value = '';
	dialogOpen.value = true;
}

function closeDialog(): void {
	if (saving.value) {
		return;
	}

	dialogOpen.value = false;
	editing.value = null;
}

function toLocalInput(value: string): string {
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return '';
	}

	const pad = (part: number): string => String(part).padStart(2, '0');
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toggleContext(value: FilterContext): void {
	contexts.value = contexts.value.includes(value)
		? contexts.value.filter((entry) => entry !== value)
		: [...contexts.value, value];
}

function addKeyword(): void {
	if (keywords.value.length < 20) {
		keywords.value.push({ text: '', whole_word: false });
	}
}

function removeKeyword(index: number): void {
	if (keywords.value.length <= 1) {
		keywords.value = [{ text: '', whole_word: false }];
		return;
	}

	keywords.value.splice(index, 1);
}

async function save(): Promise<void> {
	const title = name.value.trim();
	const cleaned: FilterKeyword[] = keywords.value
		.map((keyword) => ({ text: keyword.text.trim(), whole_word: keyword.whole_word }))
		.filter((keyword) => keyword.text !== '');

	if (title === '') {
		dialogError.value = t('filters.nameRequired');
		return;
	}
	if (contexts.value.length === 0) {
		dialogError.value = t('filters.contextRequired');
		return;
	}
	if (cleaned.length === 0) {
		dialogError.value = t('filters.keywordRequired');
		return;
	}

	const payload: FilterPayload = {
		name: title,
		action: action.value,
		contexts: contexts.value,
		keywords: cleaned,
		expires_at: expiresAt.value === '' ? null : new Date(expiresAt.value).toISOString(),
	};

	saving.value = true;
	dialogError.value = '';
	try {
		if (editing.value === null) {
			const created = await getApi().createFilter(payload);
			rules.value = [created, ...rules.value];
			showToast(t('filters.created'), 'success');
		} else {
			const updated = await getApi().updateFilter(editing.value.id, payload);
			rules.value = rules.value.map((rule) => rule.id === updated.id ? updated : rule);
			showToast(t('filters.updated'), 'success');
		}
		dialogOpen.value = false;
		editing.value = null;
	} catch (caught) {
		dialogError.value = loadErrorMessage(caught, t('filters.saveFailed'));
	} finally {
		saving.value = false;
	}
}

async function remove(rule: FilterRule): Promise<void> {
	if (confirmDelete.value !== rule.id) {
		confirmDelete.value = rule.id;
		window.setTimeout(() => {
			if (confirmDelete.value === rule.id) {
				confirmDelete.value = '';
			}
		}, 3500);
		return;
	}

	if (busy.value !== '') {
		return;
	}

	busy.value = rule.id;
	try {
		await getApi().deleteFilter(rule.id);
		rules.value = rules.value.filter((entry) => entry.id !== rule.id);
		confirmDelete.value = '';
		showToast(t('filters.deleted'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('filters.deleteFailed')), 'error');
	} finally {
		busy.value = '';
	}
}

function contextLabel(value: FilterContext): string {
	const entry = CONTEXTS.find((candidate) => candidate.value === value);
	return entry === undefined ? value : t(entry.labelKey);
}
</script>

<template>
	<div class="a-page">
		<PageHeader :title="t('filters.title')" :subtitle="t('filters.subtitle')">
			<template #actions>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="openCreate">
					<AppIcon name="plus" :size="15" /> {{ t('filters.new') }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="filter" :size="26" /></span>
			<h2>{{ t('filters.loginTitle') }}</h2>
			<p>{{ t('filters.loginText') }}</p>
			<a class="a-btn a-btn--primary" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<div v-else-if="loading && rules.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>{{ t('filters.unavailable') }}</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</div>

		<EmptyState
			v-else-if="rules.length === 0"
			icon="filter"
			:title="t('filters.emptyTitle')"
			:text="t('filters.emptyText')"
		>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openCreate">{{ t('filters.create') }}</button>
		</EmptyState>

		<ul v-else class="a-management-list">
			<li v-for="rule in rules" :key="rule.id" class="a-management-card">
				<div class="a-management-card__head">
					<div class="a-management-card__title">
						<span class="a-badge" :class="rule.action === 'hide' ? 'a-badge--rose' : 'a-badge--amber'">
							{{ rule.action === 'hide' ? t('filters.action.hide') : t('filters.action.warn') }}
						</span>
						<strong>{{ rule.name }}</strong>
					</div>
					<div class="a-management-item__actions">
						<button type="button" class="a-icon-btn" :aria-label="t('filters.edit')" :title="t('filters.editTitle')" @click="openEdit(rule)">
							<AppIcon name="pen" :size="16" />
						</button>
						<button
							type="button"
							class="a-icon-btn"
							:class="{ 'a-icon-btn--danger': confirmDelete === rule.id }"
							:aria-label="t('filters.delete')"
							:title="confirmDelete === rule.id ? t('filters.confirmDelete') : t('filters.deleteTitle')"
							:disabled="busy === rule.id"
							@click="remove(rule)"
						>
							<AppIcon name="trash" :size="16" />
						</button>
					</div>
				</div>

				<div class="a-management-tags">
					<span v-for="context in rule.contexts" :key="context" class="a-management-tag">
						{{ contextLabel(context) }}
					</span>
				</div>

				<p class="a-management-keywords">
					<span v-for="keyword in rule.keywords" :key="keyword.text" class="a-keyword">
						{{ keyword.text }}<small v-if="keyword.whole_word">{{ t('filters.wholeWordTag') }}</small>
					</span>
				</p>

				<p v-if="rule.expires_at !== null" class="a-management-footnote">
					{{ t('filters.expires', { date: absoluteTime(rule.expires_at) }) }}
				</p>
			</li>
		</ul>

		<Teleport to="body">
			<div
				v-if="dialogOpen"
				class="a-modal-overlay"
				role="presentation"
				@click.self="closeDialog"
				@keydown.esc="closeDialog"
			>
				<form class="a-management-dialog a-management-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="a-filter-dialog-title" @submit.prevent="save">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">{{ t('filters.eyebrow') }}</p>
							<h2 id="a-filter-dialog-title">{{ editing === null ? t('filters.newDialog') : t('filters.editDialog') }}</h2>
						</div>
						<button type="button" class="a-icon-btn" :aria-label="t('common.closeDialog')" @click="closeDialog">
							<AppIcon name="x" :size="19" />
						</button>
					</header>

					<label class="a-profile-edit__label" for="a-filter-name">{{ t('filters.name') }}</label>
					<input id="a-filter-name" v-model="name" type="text" maxlength="100" required autocomplete="off">

					<label class="a-profile-edit__label" for="a-filter-action">{{ t('filters.actionLabel') }}</label>
					<select id="a-filter-action" v-model="action" class="a-management-select">
						<option value="warn">{{ t('filters.actionWarn') }}</option>
						<option value="hide">{{ t('filters.actionHide') }}</option>
					</select>

					<p class="a-profile-edit__label">{{ t('filters.areas') }}</p>
					<div class="a-management-checks">
						<label v-for="context in CONTEXTS" :key="context.value" class="a-management-check">
							<input
								type="checkbox"
								:checked="contexts.includes(context.value)"
								@change="toggleContext(context.value)"
							>
							<span>{{ t(context.labelKey) }}</span>
						</label>
					</div>

					<p class="a-profile-edit__label">{{ t('filters.keywords') }}</p>
					<div class="a-management-keyword-editor">
						<div v-for="(keyword, index) in keywords" :key="index" class="a-management-keyword-row">
							<input v-model="keyword.text" type="text" maxlength="100" :placeholder="t('filters.keywordPlaceholder', { n: index + 1 })">
							<label class="a-management-check a-management-check--inline">
								<input v-model="keyword.whole_word" type="checkbox">
								<span>{{ t('filters.wholeWord') }}</span>
							</label>
							<button type="button" class="a-icon-btn" :aria-label="t('filters.removeKeyword')" @click="removeKeyword(index)">
								<AppIcon name="x" :size="15" />
							</button>
						</div>
					</div>
					<button v-if="keywords.length < 20" type="button" class="a-btn a-btn--ghost a-btn--small" @click="addKeyword">
						<AppIcon name="plus" :size="14" /> {{ t('filters.addKeyword') }}
					</button>

					<label class="a-profile-edit__label" for="a-filter-expiry">{{ t('filters.expiry') }}</label>
					<input id="a-filter-expiry" v-model="expiresAt" type="datetime-local">

					<p class="a-profile-edit__hint">{{ t('filters.expiryHint') }}</p>
					<p v-if="dialogError !== ''" class="a-form-error" role="alert">{{ dialogError }}</p>

					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="saving" @click="closeDialog">{{ t('common.cancel') }}</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving">
							<AppIcon v-if="saving" name="refresh" :size="15" class="a-spin" />
							{{ saving ? t('common.saving') : t('common.save') }}
						</button>
					</footer>
				</form>
			</div>
		</Teleport>
	</div>
</template>
