<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import {
	getAdminApi,
	type DomainBlock,
	type DomainImportResult,
	type ModerationAction,
	type ModerationActionList,
	type ModerationKind,
} from '../../lib/admin';
import { absoluteTime, relativeTime } from '../../lib/format';
import { t } from '../../lib/i18n';
import { appState, showToast } from '../../lib/state';

const actions = ref<ModerationActionList | null>(null);
const blocks = ref<DomainBlock[]>([]);
const loading = ref(false);
const error = ref('');

const kind = ref<ModerationKind>('silence_actor');
const target = ref('');
const note = ref('');
const publicComment = ref('');
const actionBusy = ref(false);
const actionError = ref('');

const importText = ref('');
const importFile = ref<{ name: string; content: string; format: 'lines' | 'csv' | 'json' } | null>(null);
const importFileInput = ref<HTMLInputElement | null>(null);
const importResult = ref<DomainImportResult | null>(null);
const importBusy = ref(false);
const importError = ref('');

const reverseOpen = ref(false);
const reverseTarget = ref<ModerationAction | null>(null);
const reverseBusy = ref(false);

const kindOptions: { id: ModerationKind; labelKey: string; hintKey: string }[] = [
	{ id: 'silence_actor', labelKey: 'amod.kind.silence_actor', hintKey: 'amod.kind.silence_actor.hint' },
	{ id: 'suspend_actor', labelKey: 'amod.kind.suspend_actor', hintKey: 'amod.kind.suspend_actor.hint' },
	{ id: 'block_domain', labelKey: 'amod.kind.block_domain', hintKey: 'amod.kind.block_domain.hint' },
];

const activeActions = computed(() => (actions.value?.actions ?? []).filter((action) => action.reversed_at === null));
const historyActions = computed(() => actions.value?.actions ?? []);
const importCounts = computed(() => importResult.value?.counts ?? {});
const canApplyImport = computed(() => {
	const result = importResult.value;
	return result !== null && result.applied === false && (importCounts.value['new'] ?? 0) > 0;
});

function parseImportLines(): (string | { domain: string; comment: string })[] {
	return importText.value
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
		.map((line) => {
			const hash = line.indexOf('#');
			if (hash === -1) {
				return line;
			}
			const domain = line.slice(0, hash).trim();
			const comment = line.slice(hash + 1).trim();
			return comment === '' ? domain : { domain, comment };
		});
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		const [actionList, domainBlocks] = await Promise.all([
			getAdminApi().moderation(),
			getAdminApi().domainBlocks(),
		]);
		actions.value = actionList;
		blocks.value = domainBlocks;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('amod.loadFailed');
	} finally {
		loading.value = false;
	}
}

async function submitAction(): Promise<void> {
	if (actionBusy.value || target.value.trim() === '') {
		return;
	}
	actionBusy.value = true;
	actionError.value = '';
	try {
		await getAdminApi().applyModeration({
			kind: kind.value,
			target: target.value,
			note: note.value.trim() === '' ? null : note.value,
			public_comment: publicComment.value.trim() === '' ? null : publicComment.value,
		});
		showToast(t('amod.applied'), 'success');
		target.value = '';
		note.value = '';
		publicComment.value = '';
		await load();
	} catch (caught) {
		actionError.value = caught instanceof Error ? caught.message : t('amod.applyFailed');
	} finally {
		actionBusy.value = false;
	}
}

function importFileFormat(name: string): 'lines' | 'csv' | 'json' {
	const lower = name.toLowerCase();
	if (lower.endsWith('.csv')) {
		return 'csv';
	}
	if (lower.endsWith('.json')) {
		return 'json';
	}

	return 'lines';
}

async function pickImportFile(event: Event): Promise<void> {
	const input = event.target as HTMLInputElement;
	const file = input.files?.[0];
	input.value = '';
	if (file === undefined || importBusy.value) {
		return;
	}

	importBusy.value = true;
	importError.value = '';
	importResult.value = null;
	try {
		const content = await file.text();
		const format = importFileFormat(file.name);
		importFile.value = { name: file.name, content, format };
		importResult.value = await getAdminApi().importDomainBlockFile(content, format, false);
	} catch (caught) {
		importFile.value = null;
		importError.value = caught instanceof Error ? caught.message : t('amod.fileFailed');
	} finally {
		importBusy.value = false;
	}
}

function clearImportFile(): void {
	importFile.value = null;
	importResult.value = null;
	importError.value = '';
}

async function previewImport(): Promise<void> {
	if (importBusy.value) {
		return;
	}
	if (importFile.value !== null) {
		importBusy.value = true;
		importError.value = '';
		try {
			importResult.value = await getAdminApi().importDomainBlockFile(
				importFile.value.content,
				importFile.value.format,
				false,
			);
		} catch (caught) {
			importError.value = caught instanceof Error ? caught.message : t('amod.checkFailed');
		} finally {
			importBusy.value = false;
		}

		return;
	}

	const entries = parseImportLines();
	if (entries.length === 0) {
		return;
	}
	importBusy.value = true;
	importError.value = '';
	try {
		importResult.value = await getAdminApi().importDomainBlocks(entries, false);
	} catch (caught) {
		importError.value = caught instanceof Error ? caught.message : t('amod.checkFailed');
	} finally {
		importBusy.value = false;
	}
}

async function applyImport(): Promise<void> {
	if (importBusy.value || !canApplyImport.value) {
		return;
	}
	importBusy.value = true;
	importError.value = '';
	try {
		importResult.value = importFile.value !== null
			? await getAdminApi().importDomainBlockFile(importFile.value.content, importFile.value.format, true)
			: await getAdminApi().importDomainBlocks(parseImportLines(), true);
		showToast(t('amod.importApplied', { count: importResult.value.applied_count }), 'success');
		importText.value = '';
		importFile.value = null;
		await load();
	} catch (caught) {
		importError.value = caught instanceof Error ? caught.message : t('amod.importFailed');
	} finally {
		importBusy.value = false;
	}
}

function askReverse(action: ModerationAction): void {
	reverseTarget.value = action;
	reverseOpen.value = true;
}

async function confirmReverse(): Promise<void> {
	if (reverseBusy.value || reverseTarget.value === null) {
		return;
	}
	reverseBusy.value = true;
	try {
		await getAdminApi().reverseModeration(reverseTarget.value.id);
		showToast(t('amod.reversed'), 'success');
		reverseOpen.value = false;
		reverseTarget.value = null;
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('amod.reverseFailed'), 'error');
	} finally {
		reverseBusy.value = false;
	}
}

function kindLabel(value: string): string {
	const option = kindOptions.find((entry) => entry.id === value);
	return option === undefined ? value : t(option.labelKey);
}

function verdictLabel(status: string): string {
	const key = `amod.verdict.${status}`;
	const translated = t(key);
	return translated === key ? status.replace('_', ' ') : translated;
}

function verdictBadge(status: string): string {
	if (status === 'new') {
		return 'a-admin-badge--ok';
	}
	if (status === 'invalid') {
		return 'a-admin-badge--danger';
	}
	return 'a-admin-badge--amber';
}

onMounted(() => {
	void load();
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">{{ t('amod.eyebrow') }}</p>
				<h2>{{ t('amod.title') }}</h2>
				<p class="a-admin-lead">
					{{ t('amod.lead') }}
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				{{ t('amod.refresh') }}
			</button>
		</div>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && actions === null" class="a-admin-skeleton-grid">
			<span v-for="index in 3" :key="index" class="a-admin-skeleton" />
		</div>

		<template v-else>
			<div class="a-admin-stat-grid a-admin-stat-grid--compact">
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="shield" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('amod.statActive') }}</p>
						<strong class="a-admin-stat__value">{{ actions?.active ?? 0 }}</strong>
						<p class="a-admin-stat__meta">{{ t('amod.statListed', { count: activeActions.length }) }}</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="ban" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">{{ t('amod.statBlocked') }}</p>
						<strong class="a-admin-stat__value">{{ blocks.length }}</strong>
						<p class="a-admin-stat__meta">{{ t('amod.statPublished') }}</p>
					</div>
				</article>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('amod.newAction') }}</h3>
							<p>{{ t('amod.newActionHint') }}</p>
						</div>
					</header>
					<form class="a-admin-form" @submit.prevent="submitAction">
						<div class="a-admin-form__grid">
							<label>
								<span>{{ t('amod.fieldAction') }}</span>
								<select v-model="kind" class="a-input">
									<option v-for="option in kindOptions" :key="option.id" :value="option.id">{{ t(option.labelKey) }}</option>
								</select>
								<small class="a-admin-muted">{{ t(kindOptions.find((option) => option.id === kind)?.hintKey ?? '') }}</small>
							</label>
							<label>
								<span>{{ t('amod.fieldTarget') }}</span>
								<input v-model="target" type="text" class="a-input" :placeholder="t(kind === 'block_domain' ? 'amod.targetPlaceholderDomain' : 'amod.targetPlaceholderActor')" />
							</label>
							<label>
								<span>{{ t('amod.fieldNote') }}</span>
								<input v-model="note" type="text" class="a-input" :placeholder="t('amod.notePlaceholder')" />
							</label>
							<label>
								<span>{{ t('amod.fieldPublic') }}</span>
								<input v-model="publicComment" type="text" class="a-input" :placeholder="t('amod.publicPlaceholder')" />
							</label>
						</div>
						<p v-if="actionError !== ''" class="a-form-error" role="alert">{{ actionError }}</p>
						<div class="a-admin-form__actions">
							<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="actionBusy || target.trim() === ''">
								<AppIcon v-if="actionBusy" name="refresh" :size="15" class="a-spin" />
								{{ actionBusy ? t('amod.applying') : t('amod.apply') }}
							</button>
						</div>
					</form>
				</section>

				<section v-if="appState.session.admin" class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('amod.importTitle') }}</h3>
							<p>{{ t('amod.importHint') }}</p>
						</div>
					</header>
					<div class="a-admin-import-source">
						<input
							ref="importFileInput"
							type="file"
							accept=".txt,.csv,.json,text/plain,text/csv,application/json"
							class="a-visually-hidden"
							@change="pickImportFile"
						/>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="importBusy" @click="importFileInput?.click()">
							<AppIcon name="archive" :size="15" />
							{{ t('amod.upload') }}
						</button>
						<span v-if="importFile !== null" class="a-admin-import-file">
							{{ importFile.name }} ({{ importFile.format }})
							<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="clearImportFile">{{ t('amod.clear') }}</button>
						</span>
					</div>
					<textarea v-model="importText" class="a-admin-textarea" rows="7" :placeholder="'spam.example\nbad.example # repeated abuse'" :disabled="importFile !== null" />
					<p v-if="importError !== ''" class="a-form-error" role="alert">{{ importError }}</p>
					<div class="a-admin-form__actions">
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="importBusy || (importFile === null && importText.trim() === '')"
							@click="previewImport"
						>
							{{ t('amod.checkImport') }}
						</button>
						<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="importBusy || !canApplyImport" @click="applyImport">
							{{ t('amod.applyImport') }}
						</button>
					</div>

					<div v-if="importResult" class="a-admin-table-wrap">
						<table class="a-admin-table a-admin-table--compact">
							<thead>
								<tr>
									<th scope="col">{{ t('amod.colInput') }}</th>
									<th scope="col">{{ t('amod.colDomain') }}</th>
									<th scope="col">{{ t('amod.colVerdict') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(entry, index) in importResult.entries" :key="index">
									<td>{{ entry.input }}</td>
									<td>{{ entry.domain ?? '—' }}</td>
									<td><span class="a-admin-badge" :class="verdictBadge(entry.status)">{{ verdictLabel(entry.status) }}</span></td>
								</tr>
							</tbody>
						</table>
						<p class="a-admin-muted">
							{{ t('amod.importCounts', {
								new: importCounts['new'] ?? 0,
								duplicate: importCounts['duplicate'] ?? 0,
								blocked: importCounts['already_blocked'] ?? 0,
								invalid: importCounts['invalid'] ?? 0,
							}) }}<template v-if="importResult.applied">{{ t('amod.importAppliedCount', { count: importResult.applied_count }) }}</template>
						</p>
					</div>
				</section>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('amod.blockedTitle') }}</h3>
							<p>{{ t('amod.blockedHint') }}</p>
						</div>
					</header>
					<ul class="a-admin-list">
						<li v-for="block in blocks" :key="block.digest" class="a-admin-list__row">
							<div>
								<strong>{{ block.domain }}</strong>
								<small v-if="block.comment !== ''">{{ block.comment }}</small>
							</div>
							<span class="a-admin-badge a-admin-badge--danger">{{ block.severity }}</span>
						</li>
						<li v-if="blocks.length === 0" class="a-admin-empty">{{ t('amod.noBlocks') }}</li>
					</ul>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>{{ t('amod.activeTitle') }}</h3>
							<p>{{ t('amod.activeHint') }}</p>
						</div>
					</header>
					<ul class="a-admin-list">
						<li v-for="action in activeActions" :key="action.id" class="a-admin-list__row">
							<div>
								<strong>{{ kindLabel(action.kind) }} · {{ action.target }}</strong>
								<small>
									{{ relativeTime(action.created_at) }}<template v-if="action.note"> · {{ action.note }}</template>
								</small>
							</div>
							<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="askReverse(action)">{{ t('amod.reverse') }}</button>
						</li>
						<li v-if="activeActions.length === 0" class="a-admin-empty">{{ t('amod.noActive') }}</li>
					</ul>
				</section>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>{{ t('amod.historyTitle') }}</h3>
						<p>{{ t('amod.historyHint') }}</p>
					</div>
				</header>
				<div class="a-admin-table-wrap">
					<table class="a-admin-table a-admin-table--compact">
						<thead>
							<tr>
								<th scope="col">{{ t('colAction') }}</th>
								<th scope="col">{{ t('colDomain') }}</th>
								<th scope="col">{{ t('colModerator') }}</th>
								<th scope="col">{{ t('ausers.colCreated') }}</th>
								<th scope="col">{{ t('colState') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="action in historyActions" :key="action.id">
								<td>{{ kindLabel(action.kind) }}</td>
								<td class="a-admin-mono">{{ action.target }}</td>
								<td>{{ action.moderator_id }}</td>
								<td>{{ absoluteTime(action.created_at) }}</td>
								<td>
									<span v-if="action.reversed_at === null" class="a-admin-badge a-admin-badge--ok">{{ t('amod.stateActive') }}</span>
									<span v-else class="a-admin-badge a-admin-badge--muted">{{ t('amod.stateReversed', { time: relativeTime(action.reversed_at) }) }}</span>
								</td>
							</tr>
							<tr v-if="historyActions.length === 0">
								<td colspan="5" class="a-admin-empty">{{ t('amod.noHistory') }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>

		<ConfirmDialog
			:open="reverseOpen"
			:title="t('amod.confirmTitle')"
			:message="reverseTarget === null ? '' : t('amod.confirmMessage', { kind: kindLabel(reverseTarget.kind), target: reverseTarget.target })"
			:confirm-label="t('amod.reverse')"
			tone="danger"
			:busy="reverseBusy"
			@confirm="confirmReverse"
			@cancel="reverseOpen = false; reverseTarget = null"
		/>
	</section>
</template>
