<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { appState, showToast } from '../lib/state';
import type { AccountPreferences, BlueskyAccountStatus } from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const loading = ref(false);
const saving = ref(false);
const exporting = ref(false);
const error = ref('');
const preferences = ref<AccountPreferences | null>(null);

const bluesky = ref<BlueskyAccountStatus>({ connected: false });
const blueskyPublishEnabled = ref(true);
const blueskyHandle = ref('');
const blueskyPassword = ref('');
const blueskyBusy = ref(false);

const visibilityOptions = [
	{ value: 'public', label: 'Öffentlich', hint: 'Alle können den Beitrag sehen.' },
	{ value: 'unlisted', label: 'Nicht gelistet', hint: 'Lesbar per Link, aber nicht in Zeitleisten.' },
	{ value: 'followers', label: 'Follower', hint: 'Nur Menschen, die dir folgen.' },
];

const quoteOptions = [
	{ value: 'public', label: 'Alle' },
	{ value: 'followers', label: 'Nur Follower' },
	{ value: 'nobody', label: 'Niemand' },
];

const dmOptions = [
	{ value: 'everyone', label: 'Alle' },
	{ value: 'followers', label: 'Nur Follower' },
	{ value: 'nobody', label: 'Niemand' },
];

const notificationOptions: { key: keyof AccountPreferences; label: string; hint: string }[] = [
	{ key: 'notify_follows', label: 'Neue Follower', hint: 'Wenn dir jemand folgt.' },
	{ key: 'notify_favourites', label: 'Favoriten', hint: 'Wenn jemand deinen Beitrag favorisiert.' },
	{ key: 'notify_boosts', label: 'Boosts', hint: 'Wenn jemand deinen Beitrag teilt.' },
	{ key: 'notify_mentions', label: 'Erwähnungen', hint: 'Wenn dich jemand in einem Beitrag nennt.' },
];

async function load(): Promise<void> {
	if (!signedIn.value) {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		preferences.value = await getApi().preferences();
		try {
			const data = await getApi().blueskyAccount();
			bluesky.value = data.account;
			blueskyPublishEnabled.value = data.publish_enabled;
		} catch {
			bluesky.value = { connected: false };
		}
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Die Einstellungen konnten nicht geladen werden.';
	} finally {
		loading.value = false;
	}
}

async function connectBluesky(): Promise<void> {
	if (blueskyBusy.value) {
		return;
	}

	blueskyBusy.value = true;
	try {
		bluesky.value = await getApi().connectBluesky(blueskyHandle.value, blueskyPassword.value);
		blueskyPassword.value = '';
		showToast('Bluesky-Konto verbunden.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Verbindung ist fehlgeschlagen.', 'error');
	} finally {
		blueskyBusy.value = false;
	}
}

async function disconnectBluesky(): Promise<void> {
	if (blueskyBusy.value) {
		return;
	}

	blueskyBusy.value = true;
	try {
		await getApi().disconnectBluesky();
		bluesky.value = { connected: false };
		showToast('Bluesky-Verbindung getrennt.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Verbindung konnte nicht getrennt werden.', 'error');
	} finally {
		blueskyBusy.value = false;
	}
}

async function save(): Promise<void> {
	if (preferences.value === null || saving.value) {
		return;
	}

	saving.value = true;
	try {
		const current = preferences.value;
		preferences.value = await getApi().updatePreferences({
			default_visibility: current.default_visibility,
			default_quote_policy: current.default_quote_policy,
			dm_policy: current.dm_policy,
			show_boosts: current.show_boosts,
			discoverable: current.discoverable,
			locked: current.locked,
			notify_follows: current.notify_follows,
			notify_favourites: current.notify_favourites,
			notify_boosts: current.notify_boosts,
			notify_mentions: current.notify_mentions,
		});
		showToast('Einstellungen gespeichert.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Einstellungen konnten nicht gespeichert werden.', 'error');
	} finally {
		saving.value = false;
	}
}

function setVisibility(value: string): void {
	if (preferences.value !== null) {
		preferences.value.default_visibility = value;
	}
}

function setQuotePolicy(value: string): void {
	if (preferences.value !== null) {
		preferences.value.default_quote_policy = value;
	}
}

function setDmPolicy(value: string): void {
	if (preferences.value !== null) {
		preferences.value.dm_policy = value;
	}
}

function toggleFlag(key: keyof AccountPreferences): void {
	if (preferences.value === null) {
		return;
	}

	const value = preferences.value[key];
	if (typeof value === 'boolean') {
		(preferences.value as unknown as Record<string, boolean>)[key] = !value;
	}
}

async function exportAccount(): Promise<void> {
	if (exporting.value) {
		return;
	}

	exporting.value = true;
	try {
		const blob = await getApi().exportAccount();
		const url = URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = `aloha-export-${new Date().toISOString().slice(0, 10)}.json`;
		document.body.appendChild(link);
		link.click();
		link.remove();
		URL.revokeObjectURL(url);
		showToast('Export wurde heruntergeladen.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Der Export konnte nicht erstellt werden.', 'error');
	} finally {
		exporting.value = false;
	}
}

onMounted(load);
watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		preferences.value = null;
	}
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Einstellungen"
			subtitle="Voreinstellungen für neue Beiträge, Privatsphäre und Benachrichtigungen."
			back-href="#/"
			back-label="Zur Startseite"
		>
			<template #actions>
				<button
					v-if="signedIn && preferences !== null"
					type="button"
					class="a-btn a-btn--primary a-btn--small"
					:disabled="saving"
					@click="save"
				>
					<AppIcon :name="saving ? 'refresh' : 'check'" :size="15" :class="{ 'a-spin': saving }" />
					{{ saving ? 'Speichert…' : 'Speichern' }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>Anmeldung erforderlich</strong>
				<p>Einstellungen gehören zu deinem Konto.</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">Anmelden</a>
		</div>

		<p v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Erneut versuchen</button>
		</p>

		<div v-else-if="loading && preferences === null" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 4" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="preferences !== null" class="a-settings">
			<section class="a-settings__section">
				<h2>Neue Beiträge</h2>
				<p class="a-settings__hint">Womit der Composer startet, wenn du nichts anderes wählst.</p>

				<fieldset class="a-settings__fieldset">
					<legend>Sichtbarkeit</legend>
					<label v-for="option in visibilityOptions" :key="option.value" class="a-settings__choice">
						<input
							type="radio"
							name="visibility"
							:value="option.value"
							:checked="preferences.default_visibility === option.value"
							@change="setVisibility(option.value)"
						>
						<span>
							<strong>{{ option.label }}</strong>
							<small>{{ option.hint }}</small>
						</span>
					</label>
				</fieldset>

				<label class="a-settings__row">
					<span>
						<strong>Boosts im Feed zeigen</strong>
						<small>Geteilte Beiträge anderer erscheinen in deinen Zeitleisten.</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.show_boosts"
						@change="toggleFlag('show_boosts')"
					>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>Privatsphäre</h2>
				<p class="a-settings__hint">Wer dich finden und dir schreiben darf.</p>

				<label class="a-settings__row">
					<span>
						<strong>Geschütztes Profil</strong>
						<small>Neue Follower müssen von dir bestätigt werden. Deine Beiträge bleiben für bestehende Follower sichtbar.</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.locked"
						@change="toggleFlag('locked')"
					>
				</label>

				<div v-if="preferences.locked" class="a-settings__follow-requests">
					<span>Offene Anfragen werden gesammelt, bis du sie bestätigst oder ablehnst.</span>
					<a class="a-btn a-btn--ghost a-btn--small" href="#/follow-requests">
						<AppIcon name="lock" :size="15" />
						Folgenanfragen ansehen
					</a>
				</div>

				<label class="a-settings__row">
					<span>
						<strong>Profil auffindbar</strong>
						<small>Dein Profil erscheint in der Suche und in Empfehlungen.</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.discoverable"
						@change="toggleFlag('discoverable')"
					>
				</label>

				<fieldset class="a-settings__fieldset">
					<legend>Direktnachrichten erlauben</legend>
					<label v-for="option in dmOptions" :key="option.value" class="a-settings__choice a-settings__choice--inline">
						<input
							type="radio"
							name="dm"
							:value="option.value"
							:checked="preferences.dm_policy === option.value"
							@change="setDmPolicy(option.value)"
						>
						<span>{{ option.label }}</span>
					</label>
				</fieldset>

				<fieldset class="a-settings__fieldset">
					<legend>Zitieren erlauben</legend>
					<label v-for="option in quoteOptions" :key="option.value" class="a-settings__choice a-settings__choice--inline">
						<input
							type="radio"
							name="quote"
							:value="option.value"
							:checked="preferences.default_quote_policy === option.value"
							@change="setQuotePolicy(option.value)"
						>
						<span>{{ option.label }}</span>
					</label>
				</fieldset>
			</section>

			<section class="a-settings__section">
				<h2>Benachrichtigungen</h2>
				<p class="a-settings__hint">Welche Ereignisse einen Eintrag in deinen Benachrichtigungen erzeugen.</p>
				<label v-for="option in notificationOptions" :key="option.key" class="a-settings__row">
					<span>
						<strong>{{ option.label }}</strong>
						<small>{{ option.hint }}</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences[option.key] === true"
						@change="toggleFlag(option.key)"
					>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>Bluesky</h2>
				<p class="a-settings__hint">
					Verbinde dein Bluesky-Konto, um öffentliche Beiträge zusätzlich dort zu veröffentlichen.
					Dafür brauchst du ein App-Passwort aus den Bluesky-Einstellungen – nicht dein normales Passwort.
				</p>

				<div v-if="bluesky.connected" class="a-settings__bluesky">
					<div class="a-settings__bluesky-status">
						<AppIcon name="check" :size="18" />
						<span>
							<strong>@{{ bluesky.handle }}</strong>
							<small>Verbunden mit {{ bluesky.pds_url }}</small>
						</span>
					</div>
					<p v-if="blueskyPublishEnabled" class="a-settings__hint">
						Neue öffentliche Beiträge werden automatisch gespiegelt. Du kannst einzelne Beiträge
						zusätzlich über das Beitragsmenü veröffentlichen.
					</p>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="blueskyBusy" @click="disconnectBluesky">
						<AppIcon :name="blueskyBusy ? 'refresh' : 'x'" :size="15" :class="{ 'a-spin': blueskyBusy }" />
						Verbindung trennen
					</button>
				</div>

				<form v-else class="a-settings__bluesky" @submit.prevent="connectBluesky">
					<label class="a-settings__field">
						<span>Handle</span>
						<input
							v-model="blueskyHandle"
							type="text"
							inputmode="url"
							autocomplete="username"
							placeholder="name.bsky.social"
							required
						>
					</label>
					<label class="a-settings__field">
						<span>App-Passwort</span>
						<input
							v-model="blueskyPassword"
							type="password"
							autocomplete="current-password"
							placeholder="xxxx-xxxx-xxxx-xxxx"
							required
						>
						<small>Du findest es in Bluesky unter Einstellungen → Datenschutz und Sicherheit → App-Passwörter.</small>
					</label>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="blueskyBusy">
						<AppIcon :name="blueskyBusy ? 'refresh' : 'plus'" :size="15" :class="{ 'a-spin': blueskyBusy }" />
						{{ blueskyBusy ? 'Verbindet…' : 'Bluesky verbinden' }}
					</button>
				</form>
			</section>

			<section class="a-settings__section">
				<h2>Deine Daten</h2>
				<p class="a-settings__hint">
					Lade eine JSON-Datei mit deinem Profil, deinen Beiträgen, deinen Followern und deinen
					privaten Markierungen herunter. Der Export enthält keine Passwörter und keine Daten anderer Konten.
				</p>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="exporting" @click="exportAccount">
					<AppIcon :name="exporting ? 'refresh' : 'share'" :size="15" :class="{ 'a-spin': exporting }" />
					{{ exporting ? 'Wird erstellt…' : 'Daten exportieren' }}
				</button>
			</section>
		</div>
	</div>
</template>
