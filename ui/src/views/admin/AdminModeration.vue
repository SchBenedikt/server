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
import { showToast } from '../../lib/state';

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
const importResult = ref<DomainImportResult | null>(null);
const importBusy = ref(false);
const importError = ref('');

const reverseOpen = ref(false);
const reverseTarget = ref<ModerationAction | null>(null);
const reverseBusy = ref(false);

const kindOptions: { id: ModerationKind; label: string; hint: string }[] = [
	{ id: 'silence_actor', label: 'Silence actor', hint: 'Hidden from timelines and search; still reachable directly.' },
	{ id: 'suspend_actor', label: 'Suspend actor', hint: 'Content hidden and interactions refused.' },
	{ id: 'block_domain', label: 'Block domain', hint: 'Rejects the whole domain for federation and feeds.' },
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
		error.value = caught instanceof Error ? caught.message : 'Moderation data could not be loaded.';
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
		showToast('Moderation action applied.', 'success');
		target.value = '';
		note.value = '';
		publicComment.value = '';
		await load();
	} catch (caught) {
		actionError.value = caught instanceof Error ? caught.message : 'The action could not be applied.';
	} finally {
		actionBusy.value = false;
	}
}

async function previewImport(): Promise<void> {
	const entries = parseImportLines();
	if (importBusy.value || entries.length === 0) {
		return;
	}
	importBusy.value = true;
	importError.value = '';
	try {
		importResult.value = await getAdminApi().importDomainBlocks(entries, false);
	} catch (caught) {
		importError.value = caught instanceof Error ? caught.message : 'The import could not be checked.';
	} finally {
		importBusy.value = false;
	}
}

async function applyImport(): Promise<void> {
	const entries = parseImportLines();
	if (importBusy.value || entries.length === 0) {
		return;
	}
	importBusy.value = true;
	importError.value = '';
	try {
		importResult.value = await getAdminApi().importDomainBlocks(entries, true);
		showToast(`${importResult.value.applied_count} domain(s) blocked.`, 'success');
		importText.value = '';
		await load();
	} catch (caught) {
		importError.value = caught instanceof Error ? caught.message : 'The import could not be applied.';
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
		showToast('Moderation action reversed.', 'success');
		reverseOpen.value = false;
		reverseTarget.value = null;
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The action could not be reversed.', 'error');
	} finally {
		reverseBusy.value = false;
	}
}

function kindLabel(value: string): string {
	return kindOptions.find((option) => option.id === value)?.label ?? value;
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
				<p class="a-admin-eyebrow">Safety</p>
				<h2>Moderation</h2>
				<p class="a-admin-lead">
					Silence or suspend actors, block whole domains and import blocklists.
					Every action is reversible and written to the audit log.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loading" @click="load">
				<AppIcon name="refresh" :size="15" :class="{ 'a-spin': loading }" />
				Refresh
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
						<p class="a-admin-stat__label">Active actions</p>
						<strong class="a-admin-stat__value">{{ actions?.active ?? 0 }}</strong>
						<p class="a-admin-stat__meta">{{ activeActions.length }} listed below</p>
					</div>
				</article>
				<article class="a-admin-stat">
					<span class="a-admin-stat__icon"><AppIcon name="ban" :size="19" /></span>
					<div>
						<p class="a-admin-stat__label">Blocked domains</p>
						<strong class="a-admin-stat__value">{{ blocks.length }}</strong>
						<p class="a-admin-stat__meta">Published when enabled in settings</p>
					</div>
				</article>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>New action</h3>
							<p>Actor actions need an absolute actor URL; domain actions take a bare domain.</p>
						</div>
					</header>
					<form class="a-admin-form" @submit.prevent="submitAction">
						<div class="a-admin-form__grid">
							<label>
								<span>Action</span>
								<select v-model="kind" class="a-input">
									<option v-for="option in kindOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
								</select>
								<small class="a-admin-muted">{{ kindOptions.find((option) => option.id === kind)?.hint }}</small>
							</label>
							<label>
								<span>Target</span>
								<input v-model="target" type="text" class="a-input" :placeholder="kind === 'block_domain' ? 'example.com' : 'https://peer.example/users/alice'" />
							</label>
							<label>
								<span>Private note</span>
								<input v-model="note" type="text" class="a-input" placeholder="Only visible to admins" />
							</label>
							<label>
								<span>Public comment</span>
								<input v-model="publicComment" type="text" class="a-input" placeholder="Optional reason shown on domain_blocks" />
							</label>
						</div>
						<p v-if="actionError !== ''" class="a-form-error" role="alert">{{ actionError }}</p>
						<div class="a-admin-form__actions">
							<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="actionBusy || target.trim() === ''">
								<AppIcon v-if="actionBusy" name="refresh" :size="15" class="a-spin" />
								{{ actionBusy ? 'Applying…' : 'Apply action' }}
							</button>
						</div>
					</form>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Import blocklist</h3>
							<p>One domain per line; append <code># comment</code> for a public reason. Always dry-run first.</p>
						</div>
					</header>
					<textarea v-model="importText" class="a-admin-textarea" rows="7" placeholder="spam.example&#10;bad.example # repeated abuse" />
					<p v-if="importError !== ''" class="a-form-error" role="alert">{{ importError }}</p>
					<div class="a-admin-form__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="importBusy || importText.trim() === ''" @click="previewImport">
							Check import
						</button>
						<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="importBusy || !canApplyImport" @click="applyImport">
							Apply import
						</button>
					</div>

					<div v-if="importResult" class="a-admin-table-wrap">
						<table class="a-admin-table a-admin-table--compact">
							<thead>
								<tr>
									<th scope="col">Input</th>
									<th scope="col">Domain</th>
									<th scope="col">Verdict</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(entry, index) in importResult.entries" :key="index">
									<td>{{ entry.input }}</td>
									<td>{{ entry.domain ?? '—' }}</td>
									<td><span class="a-admin-badge" :class="verdictBadge(entry.status)">{{ entry.status.replace('_', ' ') }}</span></td>
								</tr>
							</tbody>
						</table>
						<p class="a-admin-muted">
							{{ importCounts['new'] ?? 0 }} new · {{ importCounts['duplicate'] ?? 0 }} duplicate ·
							{{ importCounts['already_blocked'] ?? 0 }} already blocked · {{ importCounts['invalid'] ?? 0 }} invalid
							<template v-if="importResult.applied"> · {{ importResult.applied_count }} applied</template>
						</p>
					</div>
				</section>
			</div>

			<div class="a-admin-columns">
				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Blocked domains</h3>
							<p>Active domain blocks with their publishable comment.</p>
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
						<li v-if="blocks.length === 0" class="a-admin-empty">No domain blocks.</li>
					</ul>
				</section>

				<section class="a-admin-card">
					<header class="a-admin-card__head">
						<div>
							<h3>Active actions</h3>
							<p>Silences, suspensions and domain blocks currently in force.</p>
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
							<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="askReverse(action)">Reverse</button>
						</li>
						<li v-if="activeActions.length === 0" class="a-admin-empty">No active actions.</li>
					</ul>
				</section>
			</div>

			<section class="a-admin-card">
				<header class="a-admin-card__head">
					<div>
						<h3>History</h3>
						<p>Every moderation action, including reversed ones.</p>
					</div>
				</header>
				<div class="a-admin-table-wrap">
					<table class="a-admin-table a-admin-table--compact">
						<thead>
							<tr>
								<th scope="col">Action</th>
								<th scope="col">Target</th>
								<th scope="col">Moderator</th>
								<th scope="col">Created</th>
								<th scope="col">State</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="action in historyActions" :key="action.id">
								<td>{{ kindLabel(action.kind) }}</td>
								<td class="a-admin-mono">{{ action.target }}</td>
								<td>{{ action.moderator_id }}</td>
								<td>{{ absoluteTime(action.created_at) }}</td>
								<td>
									<span v-if="action.reversed_at === null" class="a-admin-badge a-admin-badge--ok">active</span>
									<span v-else class="a-admin-badge a-admin-badge--muted">reversed {{ relativeTime(action.reversed_at) }}</span>
								</td>
							</tr>
							<tr v-if="historyActions.length === 0">
								<td colspan="5" class="a-admin-empty">No moderation actions yet.</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>

		<ConfirmDialog
			:open="reverseOpen"
			title="Reverse moderation action"
			:message="reverseTarget === null ? '' : `${kindLabel(reverseTarget.kind)} on ${reverseTarget.target} will be lifted.`"
			confirm-label="Reverse"
			tone="danger"
			:busy="reverseBusy"
			@confirm="confirmReverse"
			@cancel="reverseOpen = false; reverseTarget = null"
		/>
	</section>
</template>
