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
			title: 'Sitzungen beenden',
			message: `Alle aktiven Sitzungen von @${target.handle} werden ungültig. Das Konto muss sich neu anmelden.`,
			label: 'Sitzungen beenden',
		};
	}

	return {
		title: 'Konto deaktivieren',
		message: `@${target.handle} kann sich danach nicht mehr anmelden. Beiträge und Follower bleiben erhalten. Du kannst das Konto jederzeit wieder aktivieren.`,
		label: 'Konto deaktivieren',
	};
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		page.value = await getAdminApi().users(query.value.trim(), currentPage.value, 20);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Die Konten konnten nicht geladen werden.';
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
		showToast(`Konto @${created.handle} angelegt.`, 'success');
		currentPage.value = 1;
		await load();
	} catch (caught) {
		if (caught instanceof ApiError) {
			createError.value = caught.message;
			createFields.value = caught.fields;
		} else {
			createError.value = caught instanceof Error ? caught.message : 'Das Konto konnte nicht angelegt werden.';
		}
	} finally {
		createSaving.value = false;
	}
}

async function toggleRole(user: AdminUser): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	const next = user.role === 'admin' ? 'user' : 'admin';
	busy.value = `role:${user.id}`;
	try {
		const saved = await getAdminApi().updateUser(user.id, { role: next });
		replace(saved);
		showToast(`@${user.handle} ist jetzt ${saved.role === 'admin' ? 'Administrator' : 'Mitglied'}.`, 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Rolle konnte nicht geändert werden.', 'error');
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
		showToast(`@${user.handle} ist wieder aktiv.`, 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Das Konto konnte nicht aktiviert werden.', 'error');
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
			showToast(`@${target.handle} wurde deaktiviert.`, 'success');
		} else {
			const revoked = await getAdminApi().revokeSessions(target.id);
			showToast(`${revoked} Sitzung${revoked === 1 ? '' : 'en'} beendet.`, 'success');
		}
		confirmOpen.value = false;
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Aktion konnte nicht ausgeführt werden.', 'error');
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
		passwordError.value = 'Das Passwort braucht mindestens 8 Zeichen.';
		return;
	}

	passwordSaving.value = true;
	passwordError.value = '';
	try {
		const revoked = await getAdminApi().resetPassword(target.id, passwordValue.value);
		passwordOpen.value = false;
		showToast(`Passwort von @${target.handle} geändert. ${revoked} Sitzung${revoked === 1 ? '' : 'en'} beendet.`, 'success');
	} catch (caught) {
		passwordError.value = caught instanceof Error ? caught.message : 'Das Passwort konnte nicht geändert werden.';
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
				<p class="a-admin-eyebrow">Zugänge</p>
				<h2>Konten und Rollen</h2>
				<p class="a-admin-lead">
					Lege Konten an, ändere Anzeigenamen, vergib Administratorrechte oder sperre
					Zugänge. Passwortänderungen beenden alle bestehenden Sitzungen.
				</p>
			</div>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openCreate">
				<AppIcon name="plus" :size="15" />
				Konto anlegen
			</button>
		</div>

		<form class="a-admin-toolbar" @submit.prevent="search">
			<label class="a-admin-search">
				<AppIcon name="search" :size="17" />
				<input v-model="query" type="search" placeholder="Handle, E-Mail oder Anzeigename" autocomplete="off">
			</label>
			<button type="submit" class="a-btn a-btn--ghost a-btn--small" :disabled="loading">Suchen</button>
			<button
				v-if="query !== ''"
				type="button"
				class="a-btn a-btn--ghost a-btn--small"
				@click="query = ''; search()"
			>Zurücksetzen</button>
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
							<th scope="col">Konto</th>
							<th scope="col">Rolle</th>
							<th scope="col">Status</th>
							<th scope="col">Beiträge</th>
							<th scope="col">Beziehungen</th>
							<th scope="col">Angelegt</th>
							<th scope="col"><span class="a-admin-sr">Aktionen</span></th>
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
									{{ user.role === 'admin' ? 'Admin' : 'Mitglied' }}
								</span>
							</td>
							<td>
								<span class="a-admin-badge" :class="user.disabled_at !== null ? 'a-admin-badge--danger' : 'a-admin-badge--ok'">
									{{ user.disabled_at !== null ? 'Deaktiviert' : 'Aktiv' }}
								</span>
							</td>
							<td class="a-admin-num">
								{{ user.posts }}
								<small>{{ user.followers }} Follower · {{ user.following }} folgt</small>
							</td>
							<td class="a-admin-nowrap" :title="absoluteTime(user.created_at)">
								{{ relativeTime(user.created_at) }}
							</td>
							<td>
								<div class="a-admin-row-actions">
									<button
										type="button"
										class="a-icon-btn"
										:title="user.role === 'admin' ? 'Zum Mitglied machen' : 'Zum Admin machen'"
										:aria-label="user.role === 'admin' ? 'Zum Mitglied machen' : 'Zum Administrator machen'"
										:disabled="busy !== ''"
										@click="toggleRole(user)"
									>
										<AppIcon :name="user.role === 'admin' ? 'user' : 'shield'" :size="16" />
									</button>
									<button
										type="button"
										class="a-icon-btn"
										title="Passwort zurücksetzen"
										aria-label="Passwort zurücksetzen"
										:disabled="busy !== ''"
										@click="openPassword(user)"
									>
										<AppIcon name="key" :size="16" />
									</button>
									<button
										type="button"
										class="a-icon-btn"
										title="Sitzungen beenden"
										aria-label="Sitzungen beenden"
										:disabled="busy !== ''"
										@click="askRevoke(user)"
									>
										<AppIcon name="logout" :size="16" />
									</button>
									<button
										v-if="user.disabled_at === null"
										type="button"
										class="a-icon-btn a-icon-btn--danger"
										title="Konto deaktivieren"
										aria-label="Konto deaktivieren"
										:disabled="busy !== '' || user.handle === me"
										@click="askDisable(user)"
									>
										<AppIcon name="ban" :size="16" />
									</button>
									<button
										v-else
										type="button"
										class="a-icon-btn"
										title="Konto aktivieren"
										aria-label="Konto aktivieren"
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
								{{ query !== '' ? 'Kein Konto passt zu dieser Suche.' : 'Noch keine Konten angelegt.' }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="a-admin-pagination">
				<span>{{ page.total }} Konten · Seite {{ page.page }} von {{ page.pages }}</span>
				<div>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="page.page <= 1 || loading" @click="go(-1)">
						<AppIcon name="arrowLeft" :size="15" /> Zurück
					</button>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="page.page >= page.pages || loading" @click="go(1)">
						Weiter <AppIcon name="arrowRight" :size="15" />
					</button>
				</div>
			</div>
		</template>

		<Teleport to="body">
			<div v-if="createOpen" class="a-modal-overlay" role="presentation" @click.self="closeCreate">
				<form class="a-management-dialog a-admin-user-dialog" role="dialog" aria-modal="true" aria-labelledby="a-admin-create-title" @submit.prevent="createUser">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">Neues Konto</p>
							<h2 id="a-admin-create-title">Konto anlegen</h2>
						</div>
						<button type="button" class="a-icon-btn" aria-label="Dialog schließen" :disabled="createSaving" @click="closeCreate">
							<AppIcon name="x" :size="19" />
						</button>
					</header>

					<div class="a-admin-form-grid">
						<label class="a-profile-edit__label" for="a-admin-handle">Handle</label>
						<input id="a-admin-handle" v-model="draft.handle" type="text" maxlength="30" autocomplete="off" placeholder="z. B. mara" required>
						<p v-if="createFields.handle" class="a-form-error">{{ createFields.handle }}</p>

						<label class="a-profile-edit__label" for="a-admin-email">E-Mail</label>
						<input id="a-admin-email" v-model="draft.email" type="email" autocomplete="off" placeholder="mara@example.com" required>
						<p v-if="createFields.email" class="a-form-error">{{ createFields.email }}</p>

						<label class="a-profile-edit__label" for="a-admin-name">Anzeigename <span class="a-admin-muted">(optional)</span></label>
						<input id="a-admin-name" v-model="draft.display_name" type="text" maxlength="80" autocomplete="off" placeholder="Mara Muster">
						<p v-if="createFields.display_name" class="a-form-error">{{ createFields.display_name }}</p>

						<label class="a-profile-edit__label" for="a-admin-password">Startpasswort</label>
						<input id="a-admin-password" v-model="draft.password" type="password" minlength="8" autocomplete="new-password" required>
						<p v-if="createFields.password" class="a-form-error">{{ createFields.password }}</p>

						<label class="a-profile-edit__label" for="a-admin-role">Rolle</label>
						<select id="a-admin-role" v-model="draft.role" class="a-admin-select">
							<option value="user">Mitglied</option>
							<option value="admin">Administrator</option>
						</select>
					</div>

					<p class="a-profile-edit__hint">
						Das Konto erhält sofort Zugang. Teile das Startpasswort über einen sicheren
						Kanal und bitte um eine Änderung nach der ersten Anmeldung.
					</p>
					<p v-if="createError !== ''" class="a-form-error" role="alert">{{ createError }}</p>

					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="createSaving" @click="closeCreate">Abbrechen</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="createSaving">
							<AppIcon v-if="createSaving" name="refresh" :size="15" class="a-spin" />
							{{ createSaving ? 'Wird angelegt…' : 'Konto anlegen' }}
						</button>
					</footer>
				</form>
			</div>

			<div v-if="passwordOpen" class="a-modal-overlay" role="presentation" @click.self="closePassword">
				<form class="a-management-dialog" role="dialog" aria-modal="true" aria-labelledby="a-admin-password-title" @submit.prevent="savePassword">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">Zugang</p>
							<h2 id="a-admin-password-title">Passwort zurücksetzen</h2>
						</div>
						<button type="button" class="a-icon-btn" aria-label="Dialog schließen" :disabled="passwordSaving" @click="closePassword">
							<AppIcon name="x" :size="19" />
						</button>
					</header>
					<p class="a-admin-muted">
						Neues Passwort für <strong>@{{ passwordTarget?.handle }}</strong>. Alle Sitzungen
						dieses Kontos werden danach beendet.
					</p>
					<label class="a-profile-edit__label" for="a-admin-new-password">Neues Passwort</label>
					<input id="a-admin-new-password" v-model="passwordValue" type="password" minlength="8" autocomplete="new-password" required>
					<p class="a-profile-edit__hint">Mindestens 8 Zeichen.</p>
					<p v-if="passwordError !== ''" class="a-form-error" role="alert">{{ passwordError }}</p>
					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="passwordSaving" @click="closePassword">Abbrechen</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="passwordSaving">
							{{ passwordSaving ? 'Wird gespeichert…' : 'Passwort ändern' }}
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
