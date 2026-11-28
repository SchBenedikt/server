<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ConfirmDialog from '../../components/ConfirmDialog.vue';
import { ApiError } from '../../lib/api';
import { getAdminApi, type AdminUser, type AdminUserPage, type CreateUserPayload } from '../../lib/admin';
import { absoluteTime, relativeTime } from '../../lib/format';
import { t } from '../../lib/i18n';
import { appState, showToast } from '../../lib/state';

const page = ref<AdminUserPage | null>(null);
const loading = ref(false);
const error = ref('');
const busy = ref('');
const query = ref('');
const currentPage = ref(1);

const me = computed(() => appState.session.user?.handle ?? '');

const createOpen = ref(false);
const createSaving = ref(false);
const createError = ref('');
const createFields = ref<Record<string, string>>({});
const draft = ref<CreateUserPayload>({
	handle: '',
	email: '',
	password: '',
	role: 'user',
	display_name: '',
});

const passwordOpen = ref(false);
const passwordTarget = ref<AdminUser | null>(null);
const passwordValue = ref('');
const passwordSaving = ref(false);
const passwordError = ref('');

const confirmOpen = ref(false);
const confirmKind = ref<'disable' | 'revoke'>('disable');
const confirmTarget = ref<AdminUser | null>(null);

const confirmCopy = computed(() => {
	const target = confirmTarget.value;
	if (target === null) {
		return { title: '', message: '', label: '' };
	}

	if (confirmKind.value === 'revoke') {
		return {
			title: t('ausers.confirmRevokeTitle'),
			message: t('ausers.confirmRevokeMessage', { handle: target.handle }),
			label: t('ausers.endSessions'),
		};
	}

	return {
		title: t('ausers.confirmDisableTitle'),
		message: t('ausers.confirmDisableMessage', { handle: target.handle }),
		label: t('ausers.deactivate'),
	};
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		page.value = await getAdminApi().users(query.value.trim(), currentPage.value, 20);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('ausers.loadFailed');
	} finally {
		loading.value = false;
	}
}

function search(): void {
	currentPage.value = 1;
	void load();
}

function go(direction: -1 | 1): void {
	const data = page.value;
	if (data === null) {
		return;
	}

	const next = data.page + direction;
	if (next < 1 || next > data.pages) {
		return;
	}

	currentPage.value = next;
	void load();
}

function openCreate(): void {
	draft.value = { handle: '', email: '', password: '', role: 'user', display_name: '' };
	createError.value = '';
	createFields.value = {};
	createOpen.value = true;
}

function closeCreate(): void {
	if (!createSaving.value) {
		createOpen.value = false;
	}
}

async function createUser(): Promise<void> {
	if (createSaving.value) {
		return;
	}

	createSaving.value = true;
	createError.value = '';
	createFields.value = {};
	try {
		const created = await getAdminApi().createUser({
			...draft.value,
			display_name: draft.value.display_name?.trim() === '' ? undefined : draft.value.display_name?.trim(),
		});
		createOpen.value = false;
		showToast(t('ausers.created', { handle: created.handle }), 'success');
		currentPage.value = 1;
		await load();
	} catch (caught) {
		if (caught instanceof ApiError) {
			createError.value = caught.message;
			createFields.value = caught.fields;
		} else {
			createError.value = caught instanceof Error ? caught.message : t('ausers.createFailed');
		}
	} finally {
		createSaving.value = false;
	}
}

async function changeRole(user: AdminUser, role: string): Promise<void> {
	if (busy.value !== '' || role === user.role || !['admin', 'moderator', 'user'].includes(role)) {
		return;
	}

	busy.value = `role:${user.id}`;
	try {
		const saved = await getAdminApi().updateUser(user.id, { role: role as 'admin' | 'moderator' | 'user' });
		replace(saved);
		const label = t(saved.role === 'admin'
			? 'ausers.roleAdminWord'
			: saved.role === 'moderator'
				? 'ausers.roleModeratorWord'
				: 'ausers.roleMemberWord');
		showToast(t('ausers.roleChanged', { handle: user.handle, role: label }), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('ausers.roleFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

async function enable(user: AdminUser): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = `enable:${user.id}`;
	try {
		const saved = await getAdminApi().setUserDisabled(user.id, false);
		replace(saved);
		showToast(t('ausers.activated', { handle: user.handle }), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('ausers.activateFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

function askDisable(user: AdminUser): void {
	confirmKind.value = 'disable';
	confirmTarget.value = user;
	confirmOpen.value = true;
}

function askRevoke(user: AdminUser): void {
	confirmKind.value = 'revoke';
	confirmTarget.value = user;
	confirmOpen.value = true;
}

async function confirmAction(): Promise<void> {
	const target = confirmTarget.value;
	if (target === null || busy.value !== '') {
		return;
	}

	busy.value = 'confirm';
	try {
		if (confirmKind.value === 'disable') {
			const saved = await getAdminApi().setUserDisabled(target.id, true);
			replace(saved);
			showToast(t('ausers.deactivated', { handle: target.handle }), 'success');
		} else {
			const revoked = await getAdminApi().revokeSessions(target.id);
			showToast(t('ausers.sessionsEnded', { count: revoked }), 'success');
		}
		confirmOpen.value = false;
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('ausers.actionFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

function openPassword(user: AdminUser): void {
	passwordTarget.value = user;
	passwordValue.value = '';
	passwordError.value = '';
	passwordOpen.value = true;
}

function closePassword(): void {
	if (!passwordSaving.value) {
		passwordOpen.value = false;
	}
}

async function savePassword(): Promise<void> {
	const target = passwordTarget.value;
	if (target === null || passwordSaving.value) {
		return;
	}
	if (passwordValue.value.length < 8) {
		passwordError.value = t('ausers.passwordTooShort');
		return;
	}

	passwordSaving.value = true;
	passwordError.value = '';
	try {
		const revoked = await getAdminApi().resetPassword(target.id, passwordValue.value);
		passwordOpen.value = false;
		showToast(t('ausers.passwordChanged', { handle: target.handle, count: revoked }), 'success');
	} catch (caught) {
		passwordError.value = caught instanceof Error ? caught.message : t('ausers.passwordFailed');
	} finally {
		passwordSaving.value = false;
	}
}

function replace(user: AdminUser): void {
	if (page.value === null) {
		return;
	}

	page.value.users = page.value.users.map((entry) => entry.id === user.id ? user : entry);
}

onMounted(() => {
	void load();
});
</script>

<template>
	<section class="a-admin-section">
		<div class="a-admin-section__head">
			<div>
				<p class="a-admin-eyebrow">{{ t('ausers.eyebrow') }}</p>
				<h2>{{ t('ausers.title') }}</h2>
				<p class="a-admin-lead">
					{{ t('ausers.lead') }}
				</p>
			</div>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openCreate">
				<AppIcon name="plus" :size="15" />
				{{ t('ausers.create') }}
			</button>
		</div>

		<form class="a-admin-toolbar" @submit.prevent="search">
			<label class="a-admin-search">
				<AppIcon name="search" :size="17" />
				<input v-model="query" type="search" :placeholder="t('ausers.searchPlaceholder')" autocomplete="off">
			</label>
			<button type="submit" class="a-btn a-btn--ghost a-btn--small" :disabled="loading">{{ t('ausers.search') }}</button>
			<button
				v-if="query !== ''"
				type="button"
				class="a-btn a-btn--ghost a-btn--small"
				@click="query = ''; search()"
			>{{ t('ausers.reset') }}</button>
		</form>

		<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

		<div v-if="loading && page === null" class="a-admin-skeleton-grid a-admin-skeleton-grid--rows">
			<span v-for="index in 5" :key="index" class="a-admin-skeleton" />
		</div>

		<template v-else-if="page">
			<div class="a-admin-table-wrap">
				<table class="a-admin-table">
					<thead>
						<tr>
							<th scope="col">{{ t('ausers.colAccount') }}</th>
							<th scope="col">{{ t('ausers.colRole') }}</th>
							<th scope="col">{{ t('ausers.colStatus') }}</th>
							<th scope="col">{{ t('ausers.colPosts') }}</th>
							<th scope="col">{{ t('ausers.colRelations') }}</th>
							<th scope="col">{{ t('ausers.colCreated') }}</th>
							<th scope="col"><span class="a-admin-sr">{{ t('ausers.colActions') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="user in page.users" :key="user.id" :class="{ 'a-admin-row--disabled': user.disabled_at !== null }">
							<td>
								<a class="a-admin-user" :href="`#/u/${encodeURIComponent(user.handle)}`">
									<span class="a-admin-avatar">{{ user.handle.slice(0, 2).toUpperCase() }}</span>
									<span>
										<strong>{{ user.display_name ?? `@${user.handle}` }}</strong>
										<small>@{{ user.handle }} · {{ user.email }}</small>
									</span>
								</a>
							</td>
							<td>
								<span class="a-admin-badge" :class="user.role === 'admin' ? 'a-admin-badge--primary' : ''">
									{{ t(user.role === 'admin' ? 'ausers.roleAdmin' : user.role === 'moderator' ? 'ausers.roleModerator' : 'ausers.roleMember') }}
								</span>
							</td>
							<td>
								<span class="a-admin-badge" :class="user.disabled_at !== null ? 'a-admin-badge--danger' : 'a-admin-badge--ok'">
									{{ t(user.disabled_at !== null ? 'ausers.statusDeactivated' : 'ausers.statusActive') }}
								</span>
								<span
									v-if="user.pending_email !== null"
									class="a-admin-badge a-admin-badge--amber"
									:title="t('ausers.emailPendingTitle', { email: user.pending_email })"
								>
									{{ t('ausers.emailPending') }}
								</span>
								<span
									v-else-if="user.email_verified_at === null"
									class="a-admin-badge a-admin-badge--muted"
									:title="t('ausers.unverifiedTitle')"
								>
									{{ t('ausers.unverified') }}
								</span>
							</td>
							<td class="a-admin-num">
								{{ user.posts }}
								<small>{{ t('ausers.relStats', { followers: user.followers, following: user.following }) }}</small>
							</td>
							<td class="a-admin-nowrap" :title="absoluteTime(user.created_at)">
								{{ relativeTime(user.created_at) }}
							</td>
							<td>
								<div class="a-admin-row-actions">
									<select
										class="a-admin-select a-admin-select--role"
										:title="t('ausers.roleFor', { handle: user.handle })"
										:aria-label="t('ausers.roleFor', { handle: user.handle })"
										:value="user.role"
										:disabled="busy !== ''"
										@change="changeRole(user, ($event.target as HTMLSelectElement).value)"
									>
										<option value="user">{{ t('ausers.roleMember') }}</option>
										<option value="moderator">{{ t('ausers.roleModerator') }}</option>
										<option value="admin">{{ t('ausers.roleAdmin') }}</option>
									</select>
									<button
										type="button"
										class="a-icon-btn"
										:title="t('ausers.resetPassword')"
										:aria-label="t('ausers.resetPassword')"
										:disabled="busy !== ''"
										@click="openPassword(user)"
									>
										<AppIcon name="key" :size="16" />
									</button>
									<button
										type="button"
										class="a-icon-btn"
										:title="t('ausers.endSessions')"
										:aria-label="t('ausers.endSessions')"
										:disabled="busy !== ''"
										@click="askRevoke(user)"
									>
										<AppIcon name="logout" :size="16" />
									</button>
									<button
										v-if="user.disabled_at === null"
										type="button"
										class="a-icon-btn a-icon-btn--danger"
										:title="t('ausers.deactivate')"
										:aria-label="t('ausers.deactivate')"
										:disabled="busy !== '' || user.handle === me"
										@click="askDisable(user)"
									>
										<AppIcon name="ban" :size="16" />
									</button>
									<button
										v-else
										type="button"
										class="a-icon-btn"
										:title="t('ausers.activate')"
										:aria-label="t('ausers.activate')"
										:disabled="busy !== ''"
										@click="enable(user)"
									>
										<AppIcon name="check" :size="16" />
									</button>
								</div>
							</td>
						</tr>
						<tr v-if="page.users.length === 0">
							<td colspan="7" class="a-admin-empty">
								{{ t(query !== '' ? 'ausers.emptySearch' : 'ausers.empty') }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="a-admin-pagination">
				<span>{{ t('ausers.pagination', { total: page.total, page: page.page, pages: page.pages }) }}</span>
				<div>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="page.page <= 1 || loading" @click="go(-1)">
						<AppIcon name="arrowLeft" :size="15" /> {{ t('ausers.back') }}
					</button>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="page.page >= page.pages || loading" @click="go(1)">
						{{ t('ausers.next') }} <AppIcon name="arrowRight" :size="15" />
					</button>
				</div>
			</div>
		</template>

		<Teleport to="body">
			<div v-if="createOpen" class="a-modal-overlay" role="presentation" @click.self="closeCreate">
				<form class="a-management-dialog a-admin-user-dialog" role="dialog" aria-modal="true" aria-labelledby="a-admin-create-title" @submit.prevent="createUser">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">{{ t('ausers.newAccount') }}</p>
							<h2 id="a-admin-create-title">{{ t('ausers.create') }}</h2>
						</div>
						<button type="button" class="a-icon-btn" :aria-label="t('ausers.closeDialog')" :disabled="createSaving" @click="closeCreate">
							<AppIcon name="x" :size="19" />
						</button>
					</header>

					<div class="a-admin-form-grid">
						<label class="a-profile-edit__label" for="a-admin-handle">{{ t('ausers.handle') }}</label>
						<input id="a-admin-handle" v-model="draft.handle" type="text" maxlength="30" autocomplete="off" :placeholder="t('ausers.handlePlaceholder')" required>
						<p v-if="createFields.handle" class="a-form-error">{{ createFields.handle }}</p>

						<label class="a-profile-edit__label" for="a-admin-email">{{ t('ausers.email') }}</label>
						<input id="a-admin-email" v-model="draft.email" type="email" autocomplete="off" :placeholder="'mara@example.com'" required>
						<p v-if="createFields.email" class="a-form-error">{{ createFields.email }}</p>

						<label class="a-profile-edit__label" for="a-admin-name">{{ t('ausers.name') }} <span class="a-admin-muted">{{ t('ausers.optional') }}</span></label>
						<input id="a-admin-name" v-model="draft.display_name" type="text" maxlength="80" autocomplete="off" :placeholder="t('ausers.namePlaceholder')">
						<p v-if="createFields.display_name" class="a-form-error">{{ createFields.display_name }}</p>

						<label class="a-profile-edit__label" for="a-admin-password">{{ t('ausers.initialPassword') }}</label>
						<input id="a-admin-password" v-model="draft.password" type="password" minlength="8" autocomplete="new-password" required>
						<p v-if="createFields.password" class="a-form-error">{{ createFields.password }}</p>

						<label class="a-profile-edit__label" for="a-admin-role">{{ t('ausers.roleLabel') }}</label>
						<select id="a-admin-role" v-model="draft.role" class="a-admin-select">
							<option value="user">Member</option>
							<option value="moderator">Moderator</option>
							<option value="admin">{{ t('ausers.roleAdministrator') }}</option>
						</select>
					</div>

					<p class="a-profile-edit__hint">
						{{ t('ausers.createHint') }}
					</p>
					<p v-if="createError !== ''" class="a-form-error" role="alert">{{ createError }}</p>

					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="createSaving" @click="closeCreate">{{ t('common.cancel') }}</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="createSaving">
							<AppIcon v-if="createSaving" name="refresh" :size="15" class="a-spin" />
							{{ createSaving ? t('ausers.creating') : t('ausers.create') }}
						</button>
					</footer>
				</form>
			</div>

			<div v-if="passwordOpen" class="a-modal-overlay" role="presentation" @click.self="closePassword">
				<form class="a-management-dialog" role="dialog" aria-modal="true" aria-labelledby="a-admin-password-title" @submit.prevent="savePassword">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">{{ t('ausers.eyebrow') }}</p>
							<h2 id="a-admin-password-title">{{ t('ausers.resetTitle') }}</h2>
						</div>
						<button type="button" class="a-icon-btn" :aria-label="t('ausers.closeDialog')" :disabled="passwordSaving" @click="closePassword">
							<AppIcon name="x" :size="19" />
						</button>
					</header>
					<p class="a-admin-muted">
						{{ t('ausers.resetFor', { handle: passwordTarget?.handle ?? '' }) }}
					</p>
					<label class="a-profile-edit__label" for="a-admin-new-password">{{ t('ausers.newPassword') }}</label>
					<input id="a-admin-new-password" v-model="passwordValue" type="password" minlength="8" autocomplete="new-password" required>
					<p class="a-profile-edit__hint">{{ t('ausers.passwordHint') }}</p>
					<p v-if="passwordError !== ''" class="a-form-error" role="alert">{{ passwordError }}</p>
					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="passwordSaving" @click="closePassword">{{ t('common.cancel') }}</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="passwordSaving">
							{{ passwordSaving ? t('common.saving') : t('ausers.changePassword') }}
						</button>
					</footer>
				</form>
			</div>
		</Teleport>

		<ConfirmDialog
			:open="confirmOpen"
			:title="confirmCopy.title"
			:message="confirmCopy.message"
			:confirm-label="confirmCopy.label"
			:busy="busy === 'confirm'"
			tone="danger"
			@confirm="confirmAction"
			@cancel="confirmOpen = false"
		/>
	</section>
</template>
