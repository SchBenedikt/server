<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi, type AccountMoveStatus, type AtprotoIdentityData, type EmailChangeStatus, type HostSession, type ImportPreview, type ImportResult, type MfaEnrollment, type MfaStatus } from '../lib/api';
import { disablePush, enablePush, pushSupport, subscribed as pushSubscribed } from '../lib/push';
import { appState, setSession, showToast } from '../lib/state';
import type {
	AccountPreferences,
	BlueskyAccountStatus,
	NotificationPolicy,
	NotificationPolicyAction,
	NotificationSenderCategory,
} from '../lib/types';

const signedIn = computed(() => appState.session.user !== null);
const loading = ref(false);
const saving = ref(false);
const exporting = ref(false);
const deleting = ref(false);
const importFile = ref<HTMLInputElement | null>(null);
const importBusy = ref(false);
const importPreview = ref<ImportPreview | null>(null);
const importDocument = ref<unknown>(null);
const importResult = ref<ImportResult | null>(null);
const importError = ref('');
const confirmDeletion = ref(false);
const error = ref('');
const preferences = ref<AccountPreferences | null>(null);
const notificationPolicy = ref<NotificationPolicy | null>(null);
const notificationPolicyBusy = ref(false);
const notificationPolicyError = ref('');
const newAccountDays = ref(7);

const bluesky = ref<BlueskyAccountStatus>({ connected: false });
const blueskyPublishEnabled = ref(true);
const blueskyHandle = ref('');
const blueskyBusy = ref(false);
const atproto = ref<AtprotoIdentityData | null>(null);
const atprotoHandle = ref('');
const atprotoBusy = ref(false);

async function copyHandleVerification(field: 'dns_name' | 'dns_value'): Promise<void> {
	const verification = atproto.value?.identity?.verification ?? null;
	if (verification === null) {
		return;
	}

	try {
		await navigator.clipboard.writeText(verification[field]);
		showToast('Copied to clipboard.', 'success');
	} catch {
		showToast(verification[field], 'info');
	}
}
const interactionAccounts = computed(() => {
	const options: { did: string; label: string }[] = [];
	if (atproto.value?.publish && atproto.value.identity !== null && atproto.value.identity.did !== '') {
		options.push({ did: atproto.value.identity.did, label: `@${atproto.value.identity.handle} (hosted)` });
	}
	if (atproto.value?.publish && atproto.value.external.connected && atproto.value.external.did) {
		options.push({ did: atproto.value.external.did, label: `@${atproto.value.external.handle} (connected)` });
	}
	return options;
});
const accountMove = ref<AccountMoveStatus | null>(null);
const moveTarget = ref('');
const moveBusy = ref(false);
const confirmMove = ref(false);
const sessions = ref<HostSession[]>([]);
const sessionsBusy = ref(false);
const confirmRevokeOthers = ref(false);
const mfa = ref<MfaStatus>({ enabled: false, pending: false });
const pushState = ref<'loading' | 'unsupported' | 'denied' | 'off' | 'on'>('loading');
const pushBusy = ref(false);
const mfaEnrollment = ref<MfaEnrollment | null>(null);
const mfaRecoveryCodes = ref<string[]>([]);
const mfaCode = ref('');
const mfaBusy = ref(false);
const emailChange = ref<EmailChangeStatus | null>(null);
const emailBusy = ref(false);
const emailError = ref('');
const newEmail = ref('');
const emailPassword = ref('');
const confirmCancelEmail = ref(false);

const visibilityOptions = [
	{ value: 'public', label: 'Public', hint: 'Everyone can see the post.' },
	{ value: 'unlisted', label: 'Unlisted', hint: 'Readable via link, but not shown in timelines.' },
	{ value: 'followers', label: 'followers', hint: 'Only people who follow you.' },
];

const quoteOptions = [
	{ value: 'public', label: 'Public' },
	{ value: 'followers', label: 'Followers only' },
	{ value: 'nobody', label: 'Nobody' },
];

const dmOptions = [
	{ value: 'everyone', label: 'Everyone' },
	{ value: 'followers', label: 'Followers only' },
	{ value: 'nobody', label: 'Nobody' },
];

const notificationOptions: { key: keyof AccountPreferences; label: string; hint: string }[] = [
	{ key: 'notify_follows', label: 'New followers', hint: 'When someone follows you.' },
	{ key: 'notify_favourites', label: 'Favorites', hint: 'When someone likes your post.' },
	{ key: 'notify_boosts', label: 'Boosts', hint: 'When someone shares your post.' },
	{ key: 'notify_mentions', label: 'Mentions', hint: 'When someone mentions you in a post.' },
];

const senderCategories: { key: NotificationSenderCategory; label: string }[] = [
	{ key: 'mutual', label: 'Mutual follows' },
	{ key: 'following', label: 'People you follow' },
	{ key: 'follower', label: 'Your followers' },
	{ key: 'new', label: 'New accounts' },
	{ key: 'stranger', label: 'Other senders' },
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
		try {
			atproto.value = await getApi().atprotoIdentity();
		} catch {
			atproto.value = null;
		}
		try {
			accountMove.value = await getApi().accountMove();
			moveTarget.value = accountMove.value?.target_actor_id ?? '';
		} catch {
			accountMove.value = null;
		}
		try {
			sessions.value = await getApi().sessions();
		} catch {
			sessions.value = [];
		}
		try {
			mfa.value = await getApi().mfaStatus();
		} catch {
			mfa.value = { enabled: false, pending: false };
		}
		try {
			emailChange.value = await getApi().emailChangeStatus();
		} catch {
			emailChange.value = null;
		}
		pushState.value = await resolvePushState();
		try {
			notificationPolicy.value = await getApi().notificationPolicy();
			newAccountDays.value = notificationPolicy.value.new_account_days;
			notificationPolicyError.value = '';
		} catch (caught) {
			notificationPolicy.value = null;
			notificationPolicyError.value = caught instanceof Error ? caught.message : 'Notification policy could not be loaded.';
		}
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Settings could not be loaded.';
	} finally {
		loading.value = false;
	}
}

async function setNotificationAction(category: NotificationSenderCategory, event: Event): Promise<void> {
	const value = (event.target as HTMLSelectElement).value as NotificationPolicyAction;
	if (notificationPolicyBusy.value || notificationPolicy.value === null) {
		return;
	}

	notificationPolicyBusy.value = true;
	try {
		notificationPolicy.value = await getApi().updateNotificationPolicy({ actions: { [category]: value } });
		showToast('Notification policy updated.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Notification policy could not be saved.', 'error');
	} finally {
		notificationPolicyBusy.value = false;
	}
}

async function saveNewAccountDays(): Promise<void> {
	if (notificationPolicyBusy.value || notificationPolicy.value === null) {
		return;
	}

	notificationPolicyBusy.value = true;
	try {
		notificationPolicy.value = await getApi().updateNotificationPolicy({ new_account_days: newAccountDays.value });
		showToast('New-account notification threshold updated.', 'success');
	} catch (caught) {
		newAccountDays.value = notificationPolicy.value.new_account_days;
		showToast(caught instanceof Error ? caught.message : 'The threshold could not be saved.', 'error');
	} finally {
		notificationPolicyBusy.value = false;
	}
}

async function createIdentity(): Promise<void> {
	if (atprotoBusy.value) {
		return;
	}

	atprotoBusy.value = true;
	try {
		await getApi().createAtprotoIdentity(atprotoHandle.value.trim());
		atproto.value = await getApi().atprotoIdentity();
		showToast('Your own AT Protocol identity was created.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The identity could not be created.', 'error');
	} finally {
		atprotoBusy.value = false;
	}
}

async function rotateIdentityKeys(): Promise<void> {
	if (atprotoBusy.value) {
		return;
	}

	atprotoBusy.value = true;
	try {
		await getApi().rotateAtprotoKeys();
		showToast('Signing keys rotated.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The keys could not be rotated.', 'error');
	} finally {
		atprotoBusy.value = false;
	}
}

async function connectBluesky(): Promise<void> {
	if (blueskyBusy.value) {
		return;
	}

	blueskyBusy.value = true;
	try {
		const url = await getApi().startBlueskyOAuth(blueskyHandle.value);
		window.location.assign(url);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The connection failed.', 'error');
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
		showToast('Bluesky disconnected.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The connection could not be disconnected.', 'error');
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
			mirror_likes_to_atproto: current.mirror_likes_to_atproto,
			mirror_boosts_to_atproto: current.mirror_boosts_to_atproto,
			mirror_atproto_account_did: current.mirror_atproto_account_did,
		});
		showToast('Settings saved.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Settings could not be saved.', 'error');
	} finally {
		saving.value = false;
	}
}

function setInteractionAccount(event: Event): void {
	if (preferences.value !== null) {
		const did = (event.target as HTMLSelectElement).value;
		preferences.value.mirror_atproto_account_did = did === '' ? null : did;
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
		showToast('Export downloaded.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The export could not be created.', 'error');
	} finally {
		exporting.value = false;
	}
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
	importPreview.value = null;
	importResult.value = null;
	importDocument.value = null;
	try {
		const document = JSON.parse(await file.text()) as unknown;
		importDocument.value = document;
		importPreview.value = await getApi().importPreview(document);
	} catch (caught) {
		importError.value = caught instanceof Error ? caught.message : 'The export file could not be read.';
	} finally {
		importBusy.value = false;
	}
}

async function applyImport(): Promise<void> {
	if (importDocument.value === null || importBusy.value) {
		return;
	}

	importBusy.value = true;
	importError.value = '';
	try {
		importResult.value = await getApi().importApply(importDocument.value);
		const applied = importResult.value.results.filter((result) => result.status === 'applied');
		const failed = importResult.value.results.filter((result) => result.status === 'failed');
		if (failed.length > 0) {
			showToast(`Import finished with ${failed.length} failed section${failed.length === 1 ? '' : 's'}.`, 'error');
		} else {
			showToast(`Import applied ${applied.length} section${applied.length === 1 ? '' : 's'}.`, 'success');
		}
	} catch (caught) {
		importError.value = caught instanceof Error ? caught.message : 'The import could not be applied.';
	} finally {
		importBusy.value = false;
	}
}

function importSourceLabel(preview: ImportPreview): string {
	const parts = [preview.source.instance_name ?? preview.source.instance_url, preview.source.handle]
		.filter((part): part is string => typeof part === 'string' && part !== '');
	return parts.length > 0 ? parts.join(' · ') : 'Unknown source';
}

async function deleteAccount(): Promise<void> {
	if (deleting.value) {
		return;
	}
	if (!confirmDeletion.value) {
		confirmDeletion.value = true;
		return;
	}

	deleting.value = true;
	try {
		await getApi().deleteAccount();
		setSession(null);
		window.location.assign('/login');
	} catch (caught) {
		confirmDeletion.value = false;
		showToast(caught instanceof Error ? caught.message : 'The account could not be deleted.', 'error');
	} finally {
		deleting.value = false;
	}
}

async function startAccountMove(): Promise<void> {
	if (moveBusy.value || accountMove.value !== null) {
		return;
	}
	if (!confirmMove.value) {
		confirmMove.value = true;
		return;
	}

	moveBusy.value = true;
	try {
		accountMove.value = await getApi().startAccountMove(moveTarget.value.trim());
		confirmMove.value = false;
		showToast('The account move was announced. Follower migration will continue in the background.', 'success');
	} catch (caught) {
		confirmMove.value = false;
		showToast(caught instanceof Error ? caught.message : 'The account move could not be started.', 'error');
	} finally {
		moveBusy.value = false;
	}
}

function sessionLabel(session: HostSession): string {
	const agent = session.user_agent ?? '';
	if (/Mobile|Android|iPhone/i.test(agent)) {
		return 'Mobile device';
	}
	if (/Firefox/i.test(agent)) {
		return 'Firefox';
	}
	if (/Edg\//i.test(agent)) {
		return 'Edge';
	}
	if (/Chrome|Chromium/i.test(agent)) {
		return 'Chrome';
	}
	if (/Safari/i.test(agent)) {
		return 'Safari';
	}
	if (/curl|Wget|Python|Go-http/i.test(agent)) {
		return 'Script or API client';
	}
	return agent === '' ? 'Unknown device' : agent.slice(0, 60);
}

function sessionDate(value: string): string {
	const date = new Date(value.replace(' ', 'T') + 'Z');
	return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

async function revokeSession(session: HostSession): Promise<void> {
	if (sessionsBusy.value) {
		return;
	}
	sessionsBusy.value = true;
	try {
		await getApi().revokeSession(session.id);
		sessions.value = sessions.value.filter((entry) => entry.id !== session.id);
		showToast('Session signed out.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The session could not be revoked.', 'error');
	} finally {
		sessionsBusy.value = false;
	}
}

async function beginMfa(): Promise<void> {
	if (mfaBusy.value) {
		return;
	}
	mfaBusy.value = true;
	try {
		mfaEnrollment.value = await getApi().mfaBegin();
		mfaRecoveryCodes.value = [];
		mfaCode.value = '';
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Two-factor setup could not be started.', 'error');
	} finally {
		mfaBusy.value = false;
	}
}

async function confirmMfa(): Promise<void> {
	if (mfaBusy.value || mfaCode.value.trim() === '') {
		return;
	}
	mfaBusy.value = true;
	try {
		const result = await getApi().mfaConfirm(mfaCode.value.trim());
		mfa.value = { enabled: true, pending: false };
		mfaEnrollment.value = null;
		mfaRecoveryCodes.value = result.recovery_codes;
		mfaCode.value = '';
		showToast('Two-factor sign-in is enabled.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'That code is incorrect.', 'error');
	} finally {
		mfaBusy.value = false;
	}
}

async function disableMfa(): Promise<void> {
	if (mfaBusy.value || mfaCode.value.trim() === '') {
		return;
	}
	mfaBusy.value = true;
	try {
		await getApi().mfaDisable(mfaCode.value.trim());
		mfa.value = { enabled: false, pending: false };
		mfaEnrollment.value = null;
		mfaRecoveryCodes.value = [];
		mfaCode.value = '';
		showToast('Two-factor sign-in is disabled.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'A current code is required to disable.', 'error');
	} finally {
		mfaBusy.value = false;
	}
}

async function submitEmailChange(): Promise<void> {
	if (emailBusy.value || newEmail.value.trim() === '' || emailPassword.value === '') {
		return;
	}

	emailBusy.value = true;
	emailError.value = '';
	try {
		const result = await getApi().requestEmailChange(newEmail.value.trim(), emailPassword.value);
		newEmail.value = '';
		emailPassword.value = '';
		emailChange.value = await getApi().emailChangeStatus();
		confirmCancelEmail.value = false;
		showToast(`Confirmation sent to ${result.pending_email}.`, 'success');
	} catch (caught) {
		emailError.value = caught instanceof Error ? caught.message : 'The email change could not be requested.';
	} finally {
		emailBusy.value = false;
	}
}

async function cancelPendingEmail(): Promise<void> {
	if (emailBusy.value) {
		return;
	}

	if (!confirmCancelEmail.value) {
		confirmCancelEmail.value = true;
		return;
	}

	emailBusy.value = true;
	emailError.value = '';
	try {
		await getApi().cancelEmailChange();
		emailChange.value = await getApi().emailChangeStatus();
		confirmCancelEmail.value = false;
		showToast('The pending email change was cancelled.', 'success');
	} catch (caught) {
		emailError.value = caught instanceof Error ? caught.message : 'The change could not be cancelled.';
	} finally {
		emailBusy.value = false;
	}
}

async function resolvePushState(): Promise<'unsupported' | 'denied' | 'off' | 'on'> {
	const support = pushSupport();
	if (support === 'unsupported' || support === 'denied') {
		return support;
	}

	try {
		return (await pushSubscribed(getApi())) ? 'on' : 'off';
	} catch {
		return 'off';
	}
}

async function togglePush(): Promise<void> {
	if (pushBusy.value || pushState.value === 'unsupported' || pushState.value === 'denied' || pushState.value === 'loading') {
		return;
	}

	pushBusy.value = true;
	try {
		if (pushState.value === 'on') {
			await disablePush(getApi());
			pushState.value = 'off';
			showToast('Browser notifications are off for this device.', 'success');
		} else {
			await enablePush(getApi());
			pushState.value = 'on';
			showToast('Browser notifications are on for this device.', 'success');
		}
	} catch (caught) {
		pushState.value = await resolvePushState();
		showToast(caught instanceof Error ? caught.message : 'Push notifications could not be changed.', 'error');
	} finally {
		pushBusy.value = false;
	}
}

async function revokeOtherSessions(): Promise<void> {
	if (sessionsBusy.value) {
		return;
	}
	if (!confirmRevokeOthers.value) {
		confirmRevokeOthers.value = true;
		return;
	}

	sessionsBusy.value = true;
	try {
		await getApi().revokeOtherSessions();
		confirmRevokeOthers.value = false;
		sessions.value = (await getApi().sessions()).filter((session) => session.current);
		showToast('All other sessions were signed out.', 'success');
	} catch (caught) {
		confirmRevokeOthers.value = false;
		showToast(caught instanceof Error ? caught.message : 'The other sessions could not be revoked.', 'error');
	} finally {
		sessionsBusy.value = false;
	}
}

onMounted(() => {
	const callback = new URLSearchParams(window.location.search).get('atproto');
	if (callback === 'connected' || callback === 'error') {
		showToast(callback === 'connected' ? 'Bluesky account connected.' : 'Bluesky authorization failed or was cancelled.', callback === 'connected' ? 'success' : 'error');
		window.history.replaceState({}, '', window.location.pathname + window.location.hash);
	}
	load();
});
watch(signedIn, (value) => {
	if (value) {
		void load();
	} else {
		preferences.value = null;
		notificationPolicy.value = null;
		accountMove.value = null;
		moveTarget.value = '';
	}
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Settings"
			subtitle="Defaults for new posts, privacy and notifications."
			back-href="#/"
			back-label="Back to home"
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
					{{ saving ? 'Saving…' : 'Save' }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>Sign-in required</strong>
				<p>Settings belong to your account.</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">Sign in</a>
		</div>

		<p v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">Try again</button>
		</p>

		<div v-else-if="loading && preferences === null" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 4" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="preferences !== null" class="a-settings">
			<section class="a-settings__section">
				<h2>New posts</h2>
				<p class="a-settings__hint">What the composer starts with when you don't choose otherwise.</p>

				<fieldset class="a-settings__fieldset">
					<legend>Visibility</legend>
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
						<strong>Show boosts in the feed</strong>
						<small>Shared posts from others appear in your timelines.</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.show_boosts"
						@change="toggleFlag('show_boosts')"
					>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>Privacy</h2>
				<p class="a-settings__hint">Who can find you and message you.</p>

				<label class="a-settings__row">
					<span>
						<strong>Protected profile</strong>
						<small>New followers must be approved by you. Your posts remain visible to existing followers.</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.locked"
						@change="toggleFlag('locked')"
					>
				</label>

				<div v-if="preferences.locked" class="a-settings__follow-requests">
					<span>Pending requests are collected until you approve or decline them.</span>
					<a class="a-btn a-btn--ghost a-btn--small" href="#/follow-requests">
						<AppIcon name="lock" :size="15" />
						View follow requests
					</a>
				</div>

				<label class="a-settings__row">
					<span>
						<strong>Profile discoverable</strong>
						<small>Your profile appears in search and recommendations.</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.discoverable"
						@change="toggleFlag('discoverable')"
					>
				</label>

				<fieldset class="a-settings__fieldset">
					<legend>Allow direct messages</legend>
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
					<legend>Allow quotes</legend>
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

			<section v-if="blueskyPublishEnabled" class="a-settings__section">
				<h2>Share interactions to Bluesky</h2>
				<p class="a-settings__hint">Only public posts already published to AT Protocol can be mirrored. This is off until you opt in.</p>
				<label class="a-settings__row">
					<span>
						<strong>Mirror likes</strong>
						<small>Publish and remove a Bluesky like when you like or unlike an eligible post here.</small>
					</span>
					<input type="checkbox" :checked="preferences.mirror_likes_to_atproto" @change="toggleFlag('mirror_likes_to_atproto')">
				</label>
				<label class="a-settings__row">
					<span>
						<strong>Mirror boosts</strong>
						<small>Publish and remove a Bluesky repost when you boost or unboost an eligible post here.</small>
					</span>
					<input type="checkbox" :checked="preferences.mirror_boosts_to_atproto" @change="toggleFlag('mirror_boosts_to_atproto')">
				</label>
				<label v-if="interactionAccounts.length > 0" class="a-settings__row">
					<span>
						<strong>Publish from</strong>
						<small>Choose which of your connected identities publishes likes and reposts.</small>
					</span>
					<select :value="preferences.mirror_atproto_account_did ?? ''" @change="setInteractionAccount">
						<option value="">Default identity</option>
						<option v-for="account in interactionAccounts" :key="account.did" :value="account.did">{{ account.label }}</option>
					</select>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>Notifications</h2>
				<p class="a-settings__hint">Which events create an entry in your notifications.</p>
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
				<div class="a-settings__sender-policy">
					<h3>Who can notify you</h3>
					<p class="a-settings__hint">Held senders appear in your notification requests. Dropped events stay hidden.</p>
					<p v-if="notificationPolicyError !== ''" class="a-form-error" role="alert">{{ notificationPolicyError }}</p>
					<label v-for="category in senderCategories" :key="category.key" class="a-settings__row">
						<span><strong>{{ category.label }}</strong></span>
						<select
							:value="notificationPolicy?.actions[category.key] ?? 'allow'"
							:disabled="notificationPolicy === null || notificationPolicyBusy"
							@change="setNotificationAction(category.key, $event)"
						>
							<option value="allow">Allow</option>
							<option value="hold">Hold for review</option>
							<option value="drop">Drop</option>
						</select>
					</label>
					<label class="a-settings__row">
						<span>
							<strong>New-account age threshold</strong>
							<small>Accounts younger than this many days use the New accounts rule.</small>
						</span>
						<span class="a-settings__inline-field">
							<input v-model.number="newAccountDays" type="number" min="0" max="3650" :disabled="notificationPolicy === null || notificationPolicyBusy">
							<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="notificationPolicy === null || notificationPolicyBusy" @click="saveNewAccountDays">Save</button>
						</span>
					</label>
				</div>
			</section>

			<section v-if="atproto" class="a-settings__section">
				<h2>Your own AT Protocol identity</h2>
				<p class="a-settings__hint">
					When this instance hosts a native PDS, you can own a DID and handle here
					instead of only linking an existing Bluesky account.
				</p>

				<div v-if="atproto.identity" class="a-settings__bluesky">
					<div class="a-settings__bluesky-status">
						<AppIcon name="check" :size="18" />
						<span>
							<strong>@{{ atproto.identity.handle }}</strong>
							<small>{{ atproto.identity.did }}</small>
						</span>
					</div>
					<p class="a-settings__hint">
						Repository: <a :href="atproto.identity.pds_url" target="_blank" rel="noopener noreferrer">{{ atproto.identity.pds_url }}</a>
					</p>

					<div v-if="atproto.identity.verification" class="a-settings__verify">
						<strong>Verify your handle</strong>
						<p class="a-settings__hint">
							Bluesky resolves @{{ atproto.identity.handle }} through this DNS TXT record or through
							an HTTPS well-known file. A handle more than one level below the domain
							(for example name.social.example.com) is often not covered by a wildcard
							certificate, so the TXT record is the reliable option.
						</p>
						<p class="a-settings__verify-record">
							<span>{{ atproto.identity.verification.dns_name }}</span>
							<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="copyHandleVerification('dns_name')">Copy name</button>
						</p>
						<p class="a-settings__verify-record">
							<span>{{ atproto.identity.verification.dns_value }}</span>
							<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="copyHandleVerification('dns_value')">Copy value</button>
						</p>
						<p class="a-settings__hint">
							Alternative: <a :href="atproto.identity.verification.well_known_url" target="_blank" rel="noopener noreferrer">{{ atproto.identity.verification.well_known_url }}</a>
						</p>
					</div>

					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="atprotoBusy" @click="rotateIdentityKeys">
						<AppIcon name="refresh" :size="15" :class="{ 'a-spin': atprotoBusy }" />
						Rotate signing keys
					</button>
				</div>

				<form v-else-if="atproto.enabled" class="a-settings__bluesky" @submit.prevent="createIdentity">
					<label class="a-settings__field">
						<span>Handle <small>(optional)</small></span>
						<input v-model="atprotoHandle" type="text" :placeholder="`yourname.${atproto.handle_domain}`">
						<small>Leave empty to use your account handle under {{ atproto.handle_domain }}.</small>
					</label>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="atprotoBusy">
						<AppIcon name="plus" :size="15" />
						Create my DID
					</button>
				</form>

				<p v-else class="a-settings__hint">
					The native PDS is not enabled on this instance. You can still link a Bluesky account below.
				</p>
			</section>

			<section class="a-settings__section">
				<h2>Bluesky</h2>
				<p class="a-settings__hint">
					Connect securely with Bluesky OAuth to publish public posts there as well.
				</p>

				<div v-if="bluesky.connected" class="a-settings__bluesky">
					<div class="a-settings__bluesky-status">
						<AppIcon name="check" :size="18" />
						<span>
							<strong>@{{ bluesky.handle }}</strong>
							<small>Connected to {{ bluesky.pds_url }}</small>
						</span>
					</div>
					<p v-if="blueskyPublishEnabled" class="a-settings__hint">
						New public posts are mirrored automatically. You can also publish individual
						posts from the post menu.
					</p>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="blueskyBusy" @click="disconnectBluesky">
						<AppIcon :name="blueskyBusy ? 'refresh' : 'x'" :size="15" :class="{ 'a-spin': blueskyBusy }" />
						Disconnect
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
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="blueskyBusy">
						<AppIcon :name="blueskyBusy ? 'refresh' : 'plus'" :size="15" :class="{ 'a-spin': blueskyBusy }" />
						{{ blueskyBusy ? 'Opening Bluesky…' : 'Continue with Bluesky' }}
					</button>
				</form>
			</section>

			<section class="a-settings__section">
				<h2>Move your account</h2>
				<p class="a-settings__hint">
					Move your followers to another ActivityPub account. The destination must already point back to this account
					as its alias. This cannot be undone, and new posts on this account will be disabled once announced.
				</p>
				<div v-if="accountMove" class="a-settings__bluesky">
					<strong>Move announced</strong>
					<a :href="accountMove.target_actor_id" target="_blank" rel="noopener noreferrer">{{ accountMove.target_actor_id }}</a>
					<small>Follower migration is processed in the background. {{ accountMove.processed_followers }} followers processed so far.</small>
				</div>
				<form v-else class="a-settings__bluesky" @submit.prevent="startAccountMove">
					<label class="a-settings__field">
						<span>Destination account actor URL</span>
						<input v-model="moveTarget" type="url" placeholder="https://social.example/@you" required :disabled="moveBusy">
						<small>First add this account as an alias on the destination profile.</small>
					</label>
					<p v-if="confirmMove" class="a-settings__hint" role="alert">
						Confirm to announce the move. New posts will be blocked immediately; the destination cannot be changed.
					</p>
					<button type="submit" class="a-btn a-btn--danger a-btn--small" :disabled="moveBusy">
						<AppIcon :name="moveBusy ? 'refresh' : 'arrowRight'" :size="15" :class="{ 'a-spin': moveBusy }" />
						{{ moveBusy ? 'Announcing…' : confirmMove ? 'Confirm account move' : 'Move account' }}
					</button>
				</form>
			</section>

			<section v-if="emailChange !== null" class="a-settings__section">
				<h2>Email address</h2>
				<p class="a-settings__hint">
					Used for password recovery and account notices. A new address only
					takes effect after it confirms a link sent to it; your current address
					keeps working until then.
				</p>
				<p v-if="emailChange.email !== ''" class="a-settings__hint">
					Current address: <strong>{{ emailChange.email }}</strong>
				</p>

				<div v-if="emailChange.pending !== null" class="a-settings__sender-policy">
					<p>
						Change pending to <strong>{{ emailChange.pending.email }}</strong>.
						Check that inbox for the confirmation link — it expires
						<template v-if="emailChange.pending.expires_at !== ''">on {{ emailChange.pending.expires_at }}</template>
						<template v-else>in 24 hours</template>.
					</p>
					<button
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="emailBusy"
						@click="cancelPendingEmail"
					>
						{{ emailBusy ? 'Working…' : confirmCancelEmail ? 'Confirm cancellation' : 'Cancel change' }}
					</button>
				</div>

				<form class="a-settings__sender-policy" @submit.prevent="submitEmailChange">
					<label>
						<span>New email address</span>
						<input v-model="newEmail" class="a-input" type="email" maxlength="254" autocomplete="email" />
					</label>
					<label>
						<span>Confirm with your password</span>
						<input v-model="emailPassword" class="a-input" type="password" autocomplete="current-password" />
					</label>
					<p v-if="emailError !== ''" class="a-form-error" role="alert">{{ emailError }}</p>
					<button type="submit" class="a-btn a-btn--ghost a-btn--small" :disabled="emailBusy">
						{{ emailBusy ? 'Sending…' : 'Request email change' }}
					</button>
				</form>
			</section>

			<section class="a-settings__section">
				<h2>Two-factor sign-in</h2>
				<p class="a-settings__hint">
					Require a code from an authenticator app in addition to your password when signing in.
				</p>

				<div v-if="mfaRecoveryCodes.length > 0" class="a-settings__sender-policy">
					<h3>Recovery codes</h3>
					<p class="a-settings__hint">
						Each code works once if you lose your authenticator. Store them somewhere safe — they are not shown again.
					</p>
					<ul>
						<li v-for="code in mfaRecoveryCodes" :key="code"><code>{{ code }}</code></li>
					</ul>
				</div>

				<template v-else-if="mfa.enabled">
					<p class="a-settings__hint"><strong>Enabled.</strong> Sign-in asks for an authenticator code after your password.</p>
					<label class="a-settings__row">
						<span>
							<strong>Disable two-factor sign-in</strong>
							<small>Enter a current code from your authenticator to confirm.</small>
						</span>
						<span class="a-settings__inline-field">
							<input v-model="mfaCode" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" :disabled="mfaBusy">
							<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="mfaBusy || mfaCode.trim() === ''" @click="disableMfa">Disable</button>
						</span>
					</label>
				</template>

				<template v-else-if="mfaEnrollment !== null">
					<div class="a-settings__sender-policy">
						<h3>Set up your authenticator</h3>
						<p class="a-settings__hint">Add this account to an authenticator app (Aegis, Ente Auth, 1Password, …), then enter the code it shows.</p>
						<p><code class="a-settings__secret">{{ mfaEnrollment.secret }}</code></p>
						<p class="a-settings__hint">Setup link: <code>{{ mfaEnrollment.otpauth_uri }}</code></p>
						<label class="a-settings__row">
							<span>
								<strong>Verification code</strong>
								<small>The six-digit code from the app.</small>
							</span>
							<span class="a-settings__inline-field">
								<input v-model="mfaCode" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" :disabled="mfaBusy">
								<button type="button" class="a-btn a-btn--primary a-btn--small" :disabled="mfaBusy || mfaCode.trim() === ''" @click="confirmMfa">Verify &amp; enable</button>
							</span>
						</label>
					</div>
				</template>

				<template v-else>
					<p v-if="mfa.pending" class="a-settings__hint">An earlier setup was not finished — starting again replaces it.</p>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="mfaBusy" @click="beginMfa">
						<AppIcon :name="mfaBusy ? 'refresh' : 'lock'" :size="15" :class="{ 'a-spin': mfaBusy }" />
						{{ mfaBusy ? 'Starting…' : mfa.pending ? 'Resume setup' : 'Enable two-factor sign-in' }}
					</button>
				</template>
			</section>

			<section class="a-settings__section">
				<h2>Browser notifications</h2>
				<p class="a-settings__hint">
					Get a notification on this device when someone interacts with you.
					Notifications carry a generic message only — post text and names stay
					inside the app.
				</p>
				<template v-if="pushState === 'unsupported'">
					<p class="a-settings__hint">This browser does not support push notifications.</p>
				</template>
				<template v-else-if="pushState === 'denied'">
					<p class="a-settings__hint">
						Notifications are blocked for this site. Allow them in the browser's
						site settings, then reload this page.
					</p>
				</template>
				<template v-else>
					<label class="a-settings__row">
						<span>
							<strong>{{ pushState === 'on' ? 'Enabled on this device' : 'Enable on this device' }}</strong>
							<small v-if="pushState === 'on'">This browser receives pushes for your account.</small>
							<small v-else>Asks for notification permission once, then subscribes this browser.</small>
						</span>
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="pushBusy || pushState === 'loading'"
							@click="togglePush"
						>
							<AppIcon :name="pushBusy ? 'refresh' : 'bell'" :size="15" :class="{ 'a-spin': pushBusy }" />
							{{ pushBusy ? 'Working…' : pushState === 'on' ? 'Turn off' : 'Turn on' }}
						</button>
					</label>
				</template>
			</section>

			<section class="a-settings__section">
				<h2>Signed-in sessions</h2>
				<p class="a-settings__hint">
					Devices currently signed in to your account. Sign out anything you do not recognize.
				</p>
				<div v-if="sessions.length === 0" class="a-settings__hint">No active sessions found.</div>
				<label v-for="session in sessions" :key="session.id" class="a-settings__row">
					<span>
						<strong>{{ sessionLabel(session) }}<template v-if="session.current"> — this device</template></strong>
						<small>
							Signed in {{ sessionDate(session.created_at) }}
							<template v-if="session.ip !== null"> · network {{ session.ip }}</template>
						</small>
					</span>
					<button
						v-if="!session.current"
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="sessionsBusy"
						@click="revokeSession(session)"
					>
						Sign out
					</button>
				</label>
				<button
					v-if="sessions.length > 1"
					type="button"
					class="a-btn a-btn--ghost a-btn--small"
					:disabled="sessionsBusy"
					@click="revokeOtherSessions"
				>
					{{ sessionsBusy ? 'Signing out…' : confirmRevokeOthers ? 'Confirm sign-out of other sessions' : 'Sign out other sessions' }}
				</button>
			</section>

			<section class="a-settings__section">
				<h2>Your data</h2>
				<p class="a-settings__hint">
					Download a JSON file with your profile, posts, followers and private
					bookmarks. The export contains no passwords and no data from other accounts.
				</p>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="exporting" @click="exportAccount">
					<AppIcon :name="exporting ? 'refresh' : 'share'" :size="15" :class="{ 'a-spin': exporting }" />
					{{ exporting ? 'Creating…' : 'Data export' }}
				</button>
				<p class="a-settings__hint">
					Import restores settings from an export file — preferences, followed
					hashtags, filters and featured tags. Posts, follows and media are not
					transferred yet and are listed as skipped.
				</p>
				<input
					ref="importFile"
					type="file"
					accept="application/json,.json"
					class="a-visually-hidden"
					@change="pickImportFile"
				/>
				<button
					type="button"
					class="a-btn a-btn--ghost a-btn--small"
					:disabled="importBusy"
					@click="importFile?.click()"
				>
					<AppIcon :name="importBusy ? 'refresh' : 'archive'" :size="15" :class="{ 'a-spin': importBusy }" />
					{{ importBusy ? 'Working…' : 'Import data' }}
				</button>
				<p v-if="importError !== ''" class="a-form-error" role="alert">{{ importError }}</p>
				<div v-if="importPreview !== null" class="a-import">
					<p class="a-settings__hint">
						Export from {{ importSourceLabel(importPreview) }} —
						{{ importPreview.totals.local_rows }} of {{ importPreview.totals.rows }} rows can be applied.
					</p>
					<ul class="a-import__sections">
						<li v-for="section in importPreview.sections" :key="section.name" class="a-import__section">
							<span class="a-import__name">{{ section.name }}</span>
							<span class="a-import__rows">{{ section.rows }} row{{ section.rows === 1 ? '' : 's' }}</span>
							<span class="a-import__badge" :class="`a-import__badge--${section.disposition}`">
								{{ section.disposition }}
							</span>
							<span v-if="section.reason !== null" class="a-import__reason">{{ section.reason }}</span>
						</li>
					</ul>
					<button
						type="button"
						class="a-btn a-btn--small"
						:disabled="importBusy || !importPreview.apply.available"
						@click="applyImport"
					>
						{{ importBusy ? 'Importing…' : 'Apply import' }}
					</button>
				</div>
				<div v-if="importResult !== null" class="a-import">
					<ul class="a-import__sections">
						<li v-for="result in importResult.results" :key="result.name" class="a-import__section">
							<span class="a-import__name">{{ result.name }}</span>
							<span class="a-import__badge" :class="`a-import__badge--${result.status}`">{{ result.status }}</span>
							<span v-if="result.status === 'applied'" class="a-import__rows">
								{{ result.rows ?? 0 }} applied<template v-if="(result.skipped_rows ?? 0) > 0">, {{ result.skipped_rows }} skipped</template>
							</span>
							<span v-else-if="result.reason !== undefined" class="a-import__reason">{{ result.reason }}</span>
						</li>
					</ul>
				</div>
				<p class="a-settings__hint">
					Deleting your account permanently removes its local posts, follows, media and credentials.
					Remote copies may remain on other servers.
				</p>
				<button
					type="button"
					class="a-btn a-btn--danger a-btn--small"
					:disabled="deleting"
					@click="deleteAccount"
				>
					<AppIcon :name="deleting ? 'refresh' : 'trash'" :size="15" :class="{ 'a-spin': deleting }" />
					{{ deleting ? 'Deleting…' : confirmDeletion ? 'Confirm permanent deletion' : 'Delete my account' }}
				</button>
			</section>
		</div>
	</div>
</template>
