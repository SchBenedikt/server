<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { appState, showToast } from '../lib/state';
import type { AccountList } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const lists = ref<AccountList[]>([]);
const loading = ref(false);
const error = ref('');
const busy = ref('');

const editing = ref<AccountList | null>(null);
const creating = ref(false);
const dialogTitle = ref('');
const dialogError = ref('');
const saving = ref(false);
const confirmDelete = ref('');

onMounted(() => {
	if (signedIn.value) {
		void load();
	}
});

watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		lists.value = [];
	}
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		lists.value = await getApi().lists();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Die Listen konnten nicht geladen werden.';
	} finally {
		loading.value = false;
	}
}

function openCreate(): void {
	creating.value = true;
	editing.value = null;
	dialogTitle.value = '';
	dialogError.value = '';
}

function openEdit(list: AccountList): void {
	creating.value = false;
	editing.value = list;
	dialogTitle.value = list.title;
	dialogError.value = '';
}

function closeDialog(): void {
	if (saving.value) {
		return;
	}

	creating.value = false;
	editing.value = null;
	dialogError.value = '';
}

async function save(): Promise<void> {
	const title = dialogTitle.value.trim();
	if (title === '') {
		dialogError.value = 'Bitte gib einen Namen für die Liste ein.';
		return;
	}
	if (Array.from(title).length > 80) {
		dialogError.value = 'Der Name darf höchstens 80 Zeichen lang sein.';
		return;
	}

	saving.value = true;
	dialogError.value = '';
	try {
		if (creating.value) {
			const list = await getApi().createList(title);
			lists.value = [list, ...lists.value];
			creating.value = false;
			showToast('Liste erstellt.', 'success');
		} else if (editing.value !== null) {
			const updated = await getApi().updateList(editing.value.id, title);
			lists.value = lists.value.map((entry) => entry.id === updated.id ? updated : entry);
			editing.value = null;
			showToast('Liste umbenannt.', 'success');
		}
	} catch (caught) {
		dialogError.value = caught instanceof Error ? caught.message : 'Die Liste konnte nicht gespeichert werden.';
	} finally {
		saving.value = false;
	}
}

async function remove(list: AccountList): Promise<void> {
	if (confirmDelete.value !== list.id) {
		confirmDelete.value = list.id;
		window.setTimeout(() => {
			if (confirmDelete.value === list.id) {
				confirmDelete.value = '';
			}
		}, 3500);
		return;
	}

	if (busy.value !== '') {
		return;
	}

	busy.value = list.id;
	try {
		await getApi().deleteList(list.id);
		lists.value = lists.value.filter((entry) => entry.id !== list.id);
		confirmDelete.value = '';
		showToast('Liste gelöscht.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Liste konnte nicht gelöscht werden.', 'error');
	} finally {
		busy.value = '';
	}
}
</script>

<template>
	<div class="a-page">
		<PageHeader title="Listen" subtitle="Private Zusammenstellungen von Konten mit eigener Zeitleiste.">
			<template #actions>
				<button
					v-if="signedIn"
					type="button"
					class="a-btn a-btn--primary a-btn--small"
					@click="openCreate"
				>
					<AppIcon name="plus" :size="15" /> Neue Liste
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-login-panel">
			<span class="a-login-panel__icon"><AppIcon name="list" :size="26" /></span>
			<h2>Listen für deine Kreise</h2>
			<p>Melde dich an, um Konten in private Listen zu sortieren und nur deren Beiträge zu lesen.</p>
			<a class="a-btn a-btn--primary" href="/login">Anmelden</a>
		</div>

		<div v-else-if="loading && lists.length === 0" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 3" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div><strong>Listen nicht erreichbar</strong><p>{{ error }}</p></div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Erneut versuchen</button>
		</div>

		<EmptyState
			v-else-if="lists.length === 0"
			icon="list"
			title="Noch keine Listen"
			text="Eine Liste bündelt ausgewählte Konten. Lege eine Liste an und füge danach Konten hinzu."
		>
			<button type="button" class="a-btn a-btn--primary a-btn--small" @click="openCreate">
				Erste Liste erstellen
			</button>
		</EmptyState>

		<ul v-else class="a-management-list">
			<li v-for="list in lists" :key="list.id" class="a-management-item">
				<a :href="`#/l/${encodeURIComponent(list.id)}`" class="a-management-item__main">
					<span class="a-management-item__icon"><AppIcon name="list" :size="19" /></span>
					<span class="a-management-item__copy">
						<strong>{{ list.title }}</strong>
						<small>{{ list.exclusive ? 'Exklusive Liste' : 'Auch in der Startseite enthalten' }}</small>
					</span>
				</a>
				<div class="a-management-item__actions">
					<button
						type="button"
						class="a-icon-btn"
						aria-label="Liste umbenennen"
						title="Umbenennen"
						@click="openEdit(list)"
					>
						<AppIcon name="pen" :size="16" />
					</button>
					<button
						type="button"
						class="a-icon-btn"
						:class="{ 'a-icon-btn--danger': confirmDelete === list.id }"
						aria-label="Liste löschen"
						:title="confirmDelete === list.id ? 'Wirklich löschen?' : 'Löschen'"
						:disabled="busy === list.id"
						@click="remove(list)"
					>
						<AppIcon name="trash" :size="16" />
					</button>
				</div>
			</li>
		</ul>

		<Teleport to="body">
			<div
				v-if="creating || editing !== null"
				class="a-modal-overlay"
				role="presentation"
				@click.self="closeDialog"
				@keydown.esc="closeDialog"
			>
				<form class="a-management-dialog" role="dialog" aria-modal="true" aria-labelledby="a-list-dialog-title" @submit.prevent="save">
					<header class="a-profile-edit__head">
						<div>
							<p class="a-profile-edit__eyebrow">Private Liste</p>
							<h2 id="a-list-dialog-title">{{ creating ? 'Neue Liste' : 'Liste umbenennen' }}</h2>
						</div>
						<button type="button" class="a-icon-btn" aria-label="Dialog schließen" @click="closeDialog">
							<AppIcon name="x" :size="19" />
						</button>
					</header>

					<label class="a-profile-edit__label" for="a-list-title">Name</label>
					<input id="a-list-title" v-model="dialogTitle" type="text" maxlength="80" required autocomplete="off">
					<p class="a-profile-edit__hint">Nur du siehst diese Liste und ihre Zeitleiste.</p>
					<p v-if="dialogError !== ''" class="a-form-error" role="alert">{{ dialogError }}</p>

					<footer class="a-profile-edit__actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="saving" @click="closeDialog">Abbrechen</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving">
							<AppIcon v-if="saving" name="refresh" :size="15" class="a-spin" />
							{{ saving ? 'Wird gespeichert…' : creating ? 'Erstellen' : 'Speichern' }}
						</button>
					</footer>
				</form>
			</div>
		</Teleport>
	</div>
</template>
