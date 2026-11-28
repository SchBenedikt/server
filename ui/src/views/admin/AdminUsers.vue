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
			title: 'End sessions',
			message: `All active sessions for @${target.handle} become invalid. The account must sign in again.`,
			label: 'End sessions',
		};
	}

	return {
		title: 'account deactivate',
		message: `@${target.handle} will no longer be able to sign in. Posts and followers are kept. You can re-enable the account at any time.`,
		label: 'account deactivate',
	};
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		page.value = await getAdminApi().users(query.value.trim(), currentPage.value, 20);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Accounts could not be loaded.';
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
		showToast(`Account @${created.handle} created.`, 'success');
		currentPage.value = 1;
		await load();
	} catch (caught) {
		if (caught instanceof ApiError) {
			createError.value = caught.message;
			createFields.value = caught.fields;
		} else {
			createError.value = caught instanceof Error ? caught.message : 'The account could not be created.';
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
		const label = saved.role === 'admin' ? 'an administrator' : saved.role === 'moderator' ? 'a moderator' : 'a member';
		showToast(`@${user.handle} is now ${label}.`, 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The role could not be changed.', 'error');
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
		showToast(`@${user.handle} is active again.`, 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The account could not be activated.', 'error');
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
			showToast(`@${target.handle} has been deactivated.`, 'success');
		} else {
			const revoked = await getAdminApi().revokeSessions(target.id);
			showToast(`${revoked} session${revoked === 1 ? '' : 's'} ended.`, 'success');
		}
		confirmOpen.value = false;
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The action could not be completed.', 'error');
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
		passwordError.value = 'The password needs at least 8 characters.';
		return;
	}

	passwordSaving.value = true;
	passwordError.value = '';
	try {
		const revoked = await getAdminApi().resetPassword(target.id, passwordValue.value);
		passwordOpen.value = false;
		showToast(`Password for @${target.handle} changed. ${revoked} session${revoked === 1 ? '' : 's'} ended.`, 'success');
	} catch (caught) {
		passwordError.value = caught instanceof Error ? caught.message : 'The password could not be changed.';
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
				<p class="a-admin-eyebrow">Access</p>
				<h2>Accounts and roles</h2>
				<p class="a-admin-lead">
					Create accounts, change display names, grant administrator rights or suspend
					access. Password changes end all existing sessions.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openCreate">
				<AppIcon name="plus" :size="15" />
				Create account
			</button>
		</div>

		<form class="a-admin-toolbar" @submit.prevent="search">
			<label class="a-admin-search">
				<AppIcon name="search" :size="17" />
				<input v-model="query" type="search" placeholder="Handle, email or display name" autocomplete="off">
			</label>
			<button type="submit" class="a-btn a-btn--ghost a-btn--small" :disabled="loading">Search</button>
			<button
				v-if="query !== ''"
				type="button"
				class="a-btn a-btn--ghost a-btn--small"
				@click="query = ''; search()"
			>Reset</button>
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
							<th scope="col">account</th>
							<th scope="col">role</th>
							<th scope="col">Status</th>
							<th scope="col">Posts</th>
							<th scope="col">Relationships</th>
							<th scope="col">Created</th>
							<th scope="col"><span class="a-admin-sr">actions</span></th>
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
									{{ user.role === 'admin' ? 'Admin' : user.role === 'moderator' ? 'Moderator' : 'Member' }}
								</span>
							</td>
							<td>
								<span class="a-admin-badge" :class="user.disabled_at !== null ? 'a-admin-badge--danger' : 'a-admin-badge--ok'">
									{{ user.disabled_at !== null ? 'Deactivated' : 'Active' }}
								</span>
							</td>
							<td class="a-admin-num">
								{{ user.posts }}
								<small>{{ user.followers }} followers · {{ user.following }} following</small>
							</td>
							<td class="a-admin-nowrap" :title="absoluteTime(user.created_at)">
								{{ relativeTime(user.created_at) }}
							</td>
							<td>
								<div class="a-admin-row-actions">
									<select
										class="a-admin-select a-admin-select--role"
										:title="`Role for @${user.handle}`"
										:aria-label="`Role for @${user.handle}`"
										:value="user.role"
										:disabled="busy !== ''"
										@change="changeRole(user, ($event.target as HTMLSelectElement).value)"
									>
										<option value="user">Member</option>
										<option value="moderator">Moderator</option>
										<option value="admin">Admin</option>
									</select>
									<button
										type="button"
										class="a-icon-btn"
										title="Reset password"
										aria-label="Reset password"
										:disabled="busy !== ''"
										@click="openPassword(user)"
									>
										<AppIcon name="key" :size="16" />
									</button>
									<button
										type="button"
										class="a-icon-btn"
										title="End sessions"
										aria-label="End sessions"
										:disabled="busy !== ''"
										@click="askRevoke(user)"
									>
										<AppIcon name="logout" :size="16" />
									</button>
									<button
										v-if="user.disabled_at === null"
										type="button"
										class="a-icon-btn a-icon-btn--danger"
										title="account deactivate"
										aria-label="account deactivate"
										:disabled="busy !== '' || user.handle === me"
										@click="askDisable(user)"
									>
										<AppIcon name="ban" :size="16" />
									</button>
									<button
										v-else
										type="button"
										class="a-icon-btn"
										title="account activate"
										aria-label="account activate"
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
								{{ query !== '' ? 'No account matches this search.' : 'No accounts created yet.' }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="a-admin-pagination">
				<span>{{ page.total }} accounts · page {{ page.page }} of {{ page.pages }}</span>
				<div>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="page.page <= 1 || loading" @click="go(-1)">
						<AppIcon name="arrowLeft" :size="15" /> Back
					</button>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="page.page >= page.pages || loading" @click="go(1)">
						Next <AppIcon name="arrowRight" :size="15" />
					</button>
				</div>
			</div>
		</template>

		<Teleport to="body">
			<div v-if="createOpen" class="a-modal-overlay" role="presentation" @click.self="closeCreate">
				<form class="a-management-dialog a-admin-user-dialog" role="dialog" aria-modal="true" aria-labelledby="a-admin-create-title" @submit.prevent="createUser">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">New account</p>
							<h2 id="a-admin-create-title">Create account</h2>
						</div>
						<button type="button" class="a-icon-btn" aria-label="Close dialog" :disabled="createSaving" @click="closeCreate">
							<AppIcon name="x" :size="19" />
						</button>
					</header>

					<div class="a-admin-form-grid">
						<label class="a-profile-edit__label" for="a-admin-handle">Handle</label>
						<input id="a-admin-handle" v-model="draft.handle" type="text" maxlength="30" autocomplete="off" placeholder="e.g. mara" required>
						<p v-if="createFields.handle" class="a-form-error">{{ createFields.handle }}</p>

						<label class="a-profile-edit__label" for="a-admin-email">E-Mail</label>
						<input id="a-admin-email" v-model="draft.email" type="email" autocomplete="off" placeholder="mara@example.com" required>
						<p v-if="createFields.email" class="a-form-error">{{ createFields.email }}</p>

						<label class="a-profile-edit__label" for="a-admin-name">display name <span class="a-admin-muted">(optional)</span></label>
						<input id="a-admin-name" v-model="draft.display_name" type="text" maxlength="80" autocomplete="off" placeholder="Mara Muster">
						<p v-if="createFields.display_name" class="a-form-error">{{ createFields.display_name }}</p>

						<label class="a-profile-edit__label" for="a-admin-password">Initial password</label>
						<input id="a-admin-password" v-model="draft.password" type="password" minlength="8" autocomplete="new-password" required>
						<p v-if="createFields.password" class="a-form-error">{{ createFields.password }}</p>

						<label class="a-profile-edit__label" for="a-admin-role">role</label>
						<select id="a-admin-role" v-model="draft.role" class="a-admin-select">
							<option value="user">Member</option>
							<option value="moderator">Moderator</option>
							<option value="admin">Administrator</option>
						</select>
					</div>

					<p class="a-profile-edit__hint">
						The account gets access immediately. Share the initial password through a secure
						channel and ask the user to change it after first sign-in.
					</p>
					<p v-if="createError !== ''" class="a-form-error" role="alert">{{ createError }}</p>

					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="createSaving" @click="closeCreate">Cancel</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="createSaving">
							<AppIcon v-if="createSaving" name="refresh" :size="15" class="a-spin" />
							{{ createSaving ? 'Creating…' : 'Create account' }}
						</button>
					</footer>
				</form>
			</div>

			<div v-if="passwordOpen" class="a-modal-overlay" role="presentation" @click.self="closePassword">
				<form class="a-management-dialog" role="dialog" aria-modal="true" aria-labelledby="a-admin-password-title" @submit.prevent="savePassword">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">Access</p>
							<h2 id="a-admin-password-title">Reset password</h2>
						</div>
						<button type="button" class="a-icon-btn" aria-label="Close dialog" :disabled="passwordSaving" @click="closePassword">
							<AppIcon name="x" :size="19" />
						</button>
					</header>
					<p class="a-admin-muted">
						New password for <strong>@{{ passwordTarget?.handle }}</strong>. All sessions
						for this account will be ended afterwards.
					</p>
					<label class="a-profile-edit__label" for="a-admin-new-password">New password</label>
					<input id="a-admin-new-password" v-model="passwordValue" type="password" minlength="8" autocomplete="new-password" required>
					<p class="a-profile-edit__hint">At least 8 characters.</p>
					<p v-if="passwordError !== ''" class="a-form-error" role="alert">{{ passwordError }}</p>
					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="passwordSaving" @click="closePassword">Cancel</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="passwordSaving">
							{{ passwordSaving ? 'Saving…' : 'Change password' }}
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
