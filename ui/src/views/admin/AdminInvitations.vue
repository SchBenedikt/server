<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import { ApiError } from '../../lib/api';
import { getAdminApi, type AdminInvitation } from '../../lib/admin';
import { absoluteTime, relativeTime } from '../../lib/format';
import { t } from '../../lib/i18n';
import { showToast } from '../../lib/state';

const invitations = ref<AdminInvitation[]>([]);
const loading = ref(false);
const error = ref('');
const busy = ref('');

const maxUses = ref('1');
const expiresInDays = ref('');
const note = ref('');
const createError = ref('');
const createFields = ref<Record<string, string>>({});
const creating = ref(false);
const createdCode = ref('');
const copied = ref(false);

const confirmOpen = ref(false);
const confirmTarget = ref<AdminInvitation | null>(null);

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		invitations.value = await getAdminApi().invitations();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('ainv.loadFailed');
	} finally {
		loading.value = false;
	}
}

async function create(): Promise<void> {
	if (creating.value) {
		return;
	}

	const uses = Number.parseInt(maxUses.value, 10);
	const days = expiresInDays.value.trim() === '' ? null : Number.parseInt(expiresInDays.value, 10);
	if (!Number.isInteger(uses) || uses < 1 || uses > 500) {
		createError.value = t('ainv.usesInvalid');
		return;
	}
	if (days !== null && (!Number.isInteger(days) || days < 1 || days > 3650)) {
		createError.value = t('ainv.daysInvalid');
		return;
	}

	creating.value = true;
	createError.value = '';
	createFields.value = {};
	try {
		const result = await getAdminApi().createInvitation({
			max_uses: uses,
			expires_in_days: days,
			note: note.value.trim() === '' ? null : note.value.trim(),
		});
		createdCode.value = result.code;
		copied.value = false;
		maxUses.value = '1';
		expiresInDays.value = '';
		note.value = '';
		await load();
	} catch (caught) {
		if (caught instanceof ApiError) {
			createError.value = caught.message;
			createFields.value = caught.fields;
		} else {
			createError.value = caught instanceof Error ? caught.message : t('ainv.createFailed');
		}
	} finally {
		creating.value = false;
	}
}

async function copyCode(): Promise<void> {
	try {
		await navigator.clipboard.writeText(createdCode.value);
		copied.value = true;
		showToast(t('ainv.codeCopied'), 'success');
	} catch {
		showToast(t('ainv.copyFailed'), 'error');
	}
}

function askRevoke(invitation: AdminInvitation): void {
	confirmTarget.value = invitation;
	confirmOpen.value = true;
}

async function confirmRevoke(): Promise<void> {
	const target = confirmTarget.value;
	if (target === null || busy.value !== '') {
		return;
	}

	busy.value = `revoke:${target.id}`;
	try {
		await getAdminApi().revokeInvitation(target.id);
		confirmOpen.value = false;
		showToast(t('ainv.revoked', { code: target.code_prefix }), 'success');
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('ainv.revokeFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

function stateLabel(state: string): string {
	const key = `ainv.state.${state}`;
	const translated = t(key);
	return translated === key ? state : translated;
}

onMounted(() => {
	void load();
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">{{ t('ainv.eyebrow') }}</p>
				<h2>{{ t('ainv.title') }}</h2>
				<p class="a-admin-lead">
					{{ t('ainv.lead') }}
				</p>
			</div>
		</div>

		<section class="a-admin-card">
			<header class="a-admin-card__head">
				<h3>{{ t('ainv.newTitle') }}</h3>
			</header>
			<form class="a-admin-form-grid" @submit.prevent="create">
				<label class="a-profile-edit__label" for="a-invite-uses">{{ t('ainv.uses') }}</label>
				<input id="a-invite-uses" v-model="maxUses" type="number" min="1" max="500" step="1">
				<p v-if="createFields.max_uses" class="a-form-error">{{ createFields.max_uses }}</p>

				<label class="a-profile-edit__label" for="a-invite-days">{{ t('ainv.expiresDays') }} <span class="a-admin-muted">{{ t('ausers.optional') }}</span></label>
				<input id="a-invite-days" v-model="expiresInDays" type="number" min="1" max="3650" step="1" :placeholder="t('ainv.never')">
				<p v-if="createFields.expires_in_days" class="a-form-error">{{ createFields.expires_in_days }}</p>

				<label class="a-profile-edit__label" for="a-invite-note">{{ t('ainv.note') }} <span class="a-admin-muted">{{ t('ausers.optional') }}</span></label>
				<input id="a-invite-note" v-model="note" type="text" maxlength="255" :placeholder="t('ainv.notePlaceholder')" autocomplete="off">
				<p v-if="createFields.note" class="a-form-error">{{ createFields.note }}</p>

				<p v-if="createError !== ''" class="a-form-error" role="alert">{{ createError }}</p>

				<div class="a-profile-edit__actions">
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="creating">
						<AppIcon v-if="creating" name="refresh" :size="15" class="a-spin" />
						{{ creating ? t('ainv.creating') : t('ainv.create') }}
					</button>
				</div>
			</form>

			<div v-if="createdCode !== ''" class="a-admin-invite-code" role="status">
				<p class="a-admin-eyebrow">{{ t('ainv.createdEyebrow') }}</p>
				<div class="a-admin-invite-code__row">
					<code>{{ createdCode }}</code>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="copyCode">
						<AppIcon :name="copied ? 'check' : 'copy'" :size="15" />
						{{ t(copied ? 'ainv.copied' : 'ainv.copy') }}
					</button>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="createdCode = ''">{{ t('ainv.dismiss') }}</button>
				</div>
			</div>
		</section>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && invitations.length === 0" class="a-admin-skeleton-grid a-admin-skeleton-grid--rows">
			<span v-for="index in 3" :key="index" class="a-admin-skeleton" />
		</div>

		<div v-else-if="invitations.length > 0" class="a-admin-table-wrap">
			<table class="a-admin-table">
				<thead>
					<tr>
						<th scope="col">{{ t('ainv.colCode') }}</th>
						<th scope="col">{{ t('ainv.colUses') }}</th>
						<th scope="col">{{ t('ainv.colExpires') }}</th>
						<th scope="col">{{ t('ainv.colNote') }}</th>
						<th scope="col">{{ t('ainv.colCreatedBy') }}</th>
						<th scope="col">{{ t('ainv.colState') }}</th>
						<th scope="col"><span class="a-admin-sr">{{ t('ainv.colActions') }}</span></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="invitation in invitations" :key="invitation.id">
						<td><code>{{ invitation.code_prefix }}…</code></td>
						<td class="a-admin-num">{{ invitation.uses }} / {{ invitation.max_uses }}</td>
						<td class="a-admin-nowrap" :title="invitation.expires_at ? absoluteTime(invitation.expires_at) : t('ainv.never')">
							{{ invitation.expires_at ? relativeTime(invitation.expires_at) : t('ainv.never') }}
						</td>
						<td>{{ invitation.note ?? '—' }}</td>
						<td>
							<span v-if="invitation.created_by_handle">@{{ invitation.created_by_handle }}</span>
							<span v-else class="a-admin-muted">{{ t('ainv.accountRemoved') }}</span>
						</td>
						<td>
							<span class="a-admin-badge" :class="invitation.state === 'active' ? 'a-admin-badge--ok' : 'a-admin-badge--danger'">
								{{ stateLabel(invitation.state) }}
							</span>
						</td>
						<td>
							<button
								v-if="invitation.state === 'active'"
								type="button"
								class="a-icon-btn a-icon-btn--danger"
								:title="t('ainv.revokeAria')"
								:aria-label="t('ainv.revokeAria')"
								:disabled="busy !== ''"
								@click="askRevoke(invitation)"
							>
								<AppIcon name="ban" :size="16" />
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else-if="!loading" class="a-admin-empty">{{ t('ainv.empty') }}</p>

		<ConfirmDialog
			:open="confirmOpen"
			:title="t('ainv.confirmTitle')"
			:message="t('ainv.confirmMessage', { code: confirmTarget?.code_prefix ?? '' })"
			:confirm-label="t('ainv.confirmLabel')"
			tone="danger"
			:busy="busy !== ''"
			@confirm="confirmRevoke"
			@cancel="confirmOpen = false"
		/>
	</section>
</template>
