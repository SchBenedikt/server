<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi, type AccountMoveStatus, type AtprotoIdentityData, type AtprotoSessionEntry, type EmailChangeStatus, type HostSession, type ImportPreview, type ImportResult, type MfaEnrollment, type MfaStatus, loadErrorMessage } from '../lib/api';
import { absoluteTime, relativeTime } from '../lib/format';
import { applyDensity } from '../lib/density';
import { setLocale, t } from '../lib/i18n';
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
const atprotoSessions = ref<AtprotoSessionEntry[]>([]);
const atprotoSessionBusy = ref('');

async function copyHandleVerification(field: 'dns_name' | 'dns_value'): Promise<void> {
	const verification = atproto.value?.identity?.verification ?? null;
	if (verification === null) {
		return;
	}

	try {
		await navigator.clipboard.writeText(verification[field]);
		showToast(t('common.copiedToClipboard'), 'success');
	} catch {
		showToast(verification[field], 'info');
	}
}
const interactionAccounts = computed(() => {
	const options: { did: string; label: string }[] = [];
	if (atproto.value?.publish && atproto.value.identity !== null && atproto.value.identity.did !== '') {
		options.push({ did: atproto.value.identity.did, label: `@${atproto.value.identity.handle} ${t('settings.hosted')}` });
	}
	if (atproto.value?.publish && atproto.value.external.connected && atproto.value.external.did) {
		options.push({ did: atproto.value.external.did, label: `@${atproto.value.external.handle} ${t('settings.connected')}` });
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
	{ value: 'public', labelKey: 'settings.vis.public', hintKey: 'settings.vis.publicHint' },
	{ value: 'unlisted', labelKey: 'settings.vis.unlisted', hintKey: 'settings.vis.unlistedHint' },
	{ value: 'followers', labelKey: 'settings.vis.followers', hintKey: 'settings.vis.followersHint' },
];

const quoteOptions = [
	{ value: 'public', labelKey: 'settings.policy.public' },
	{ value: 'followers', labelKey: 'settings.policy.followersOnly' },
	{ value: 'nobody', labelKey: 'settings.policy.nobody' },
];

const dmOptions = [
	{ value: 'everyone', labelKey: 'settings.policy.everyone' },
	{ value: 'followers', labelKey: 'settings.policy.followersOnly' },
	{ value: 'nobody', labelKey: 'settings.policy.nobody' },
];

const notificationOptions: { key: keyof AccountPreferences; labelKey: string; hintKey: string }[] = [
	{ key: 'notify_follows', labelKey: 'settings.notify.follows', hintKey: 'settings.notify.followsHint' },
	{ key: 'notify_favourites', labelKey: 'settings.notify.favourites', hintKey: 'settings.notify.favouritesHint' },
	{ key: 'notify_boosts', labelKey: 'settings.notify.boosts', hintKey: 'settings.notify.boostsHint' },
	{ key: 'notify_mentions', labelKey: 'settings.notify.mentions', hintKey: 'settings.notify.mentionsHint' },
];

const senderCategories: { key: NotificationSenderCategory; labelKey: string }[] = [
	{ key: 'mutual', labelKey: 'settings.sender.mutual' },
	{ key: 'following', labelKey: 'settings.sender.following' },
	{ key: 'follower', labelKey: 'settings.sender.follower' },
	{ key: 'new', labelKey: 'settings.sender.new' },
	{ key: 'stranger', labelKey: 'settings.sender.stranger' },
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
			atprotoSessions.value = atproto.value.identity === null
				? []
				: await getApi().atprotoSessions();
		} catch {
			atproto.value = null;
			atprotoSessions.value = [];
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
			notificationPolicyError.value = loadErrorMessage(caught, t('settings.senderPolicyFailed'));
		}
	} catch (caught) {
		error.value = loadErrorMessage(caught, t('settings.loadFailed'));
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
		showToast(t('settings.senderPolicySaved'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.senderPolicySaveFailed')), 'error');
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
		showToast(t('settings.newAccountDaysSaved'), 'success');
	} catch (caught) {
		newAccountDays.value = notificationPolicy.value.new_account_days;
		showToast(loadErrorMessage(caught, t('settings.newAccountDaysFailed')), 'error');
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
		showToast(t('settings.identityCreated'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.identityCreateFailed')), 'error');
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
		showToast(t('settings.keysRotated'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.keysRotateFailed')), 'error');
	} finally {
		atprotoBusy.value = false;
	}
}

async function revokeAtprotoSession(id: string): Promise<void> {
	if (atprotoSessionBusy.value !== '') {
		return;
	}

	atprotoSessionBusy.value = id;
	try {
		const removed = await getApi().revokeAtprotoSession(id);
		if (removed) {
			atprotoSessions.value = atprotoSessions.value.filter((session) => session.id !== id);
			showToast(t('settings.appSignedOut'), 'success');
		} else {
			showToast(t('settings.appAlreadyGone'), 'error');
		}
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.appRevokeFailed')), 'error');
	} finally {
		atprotoSessionBusy.value = '';
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
		showToast(loadErrorMessage(caught, t('settings.bskyConnectFailed')), 'error');
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
		showToast(t('settings.bskyDisconnected'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.bskyDisconnectFailed')), 'error');
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
			recommendations_opt_out: current.recommendations_opt_out,
			ui_locale: current.ui_locale,
			ui_density: current.ui_density,
		});
		setLocale(preferences.value.ui_locale);
		applyDensity(preferences.value.ui_density);
		showToast(t('settings.saved'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.saveFailed')), 'error');
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

function setUiLocale(event: Event): void {
	if (preferences.value !== null) {
		const value = (event.target as HTMLSelectElement).value;
		preferences.value.ui_locale = value === '' ? null : value;
	}
}

function setUiDensity(event: Event): void {
	if (preferences.value !== null) {
		const value = (event.target as HTMLSelectElement).value;
		preferences.value.ui_density = value === 'compact' ? 'compact' : value === 'comfortable' ? 'comfortable' : null;
		applyDensity(preferences.value.ui_density);
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
		showToast(t('settings.exportDownloaded'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.exportFailed')), 'error');
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
		importError.value = loadErrorMessage(caught, t('settings.importFileFailed'));
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
			showToast(t('settings.importFinishedFailed', { count: failed.length }), 'error');
		} else {
			showToast(t('settings.importApplied', { count: applied.length }), 'success');
		}
	} catch (caught) {
		importError.value = loadErrorMessage(caught, t('settings.importApplyFailed'));
	} finally {
		importBusy.value = false;
	}
}

function importSourceLabel(preview: ImportPreview): string {
	const parts = [preview.source.instance_name ?? preview.source.instance_url, preview.source.handle]
		.filter((part): part is string => typeof part === 'string' && part !== '');
	return parts.length > 0 ? parts.join(' · ') : t('settings.import.unknownSource');
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
		showToast(loadErrorMessage(caught, t('settings.emailDeleteFailed')), 'error');
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
		showToast(t('settings.moveAnnouncedToast'), 'success');
	} catch (caught) {
		confirmMove.value = false;
		showToast(loadErrorMessage(caught, t('settings.moveFailed')), 'error');
	} finally {
		moveBusy.value = false;
	}
}

function sessionLabel(session: HostSession): string {
	const agent = session.user_agent ?? '';
	if (/Mobile|Android|iPhone/i.test(agent)) {
		return t('settings.device.mobile');
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
		return t('settings.device.script');
	}
	return agent === '' ? t('settings.device.unknown') : agent.slice(0, 60);
}

function sessionDate(value: string): string {
	const date = new Date(value.replace(' ', 'T') + 'Z');
	return Number.isNaN(date.getTime()) ? value : absoluteTime(date.toISOString());
}

async function revokeSession(session: HostSession): Promise<void> {
	if (sessionsBusy.value) {
		return;
	}
	sessionsBusy.value = true;
	try {
		await getApi().revokeSession(session.id);
		sessions.value = sessions.value.filter((entry) => entry.id !== session.id);
		showToast(t('settings.sessionSignedOut'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.sessionRevokeFailed')), 'error');
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
		showToast(loadErrorMessage(caught, t('settings.mfaStartFailed')), 'error');
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
		showToast(t('settings.mfaEnabledToast'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.mfaCodeWrong')), 'error');
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
		showToast(t('settings.mfaDisabledToast'), 'success');
	} catch (caught) {
		showToast(loadErrorMessage(caught, t('settings.mfaDisableFailed')), 'error');
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
		showToast(t('settings.emailChangeSent', { email: result.pending_email }), 'success');
	} catch (caught) {
		emailError.value = loadErrorMessage(caught, t('settings.emailChangeFailed'));
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
		showToast(t('settings.pendingCancelled'), 'success');
	} catch (caught) {
		emailError.value = loadErrorMessage(caught, t('settings.cancelFailed'));
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
			showToast(t('settings.pushOffToast'), 'success');
		} else {
			await enablePush(getApi());
			pushState.value = 'on';
			showToast(t('settings.pushOnToast'), 'success');
		}
	} catch (caught) {
		pushState.value = await resolvePushState();
		showToast(loadErrorMessage(caught, t('settings.pushFailed')), 'error');
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
		showToast(t('settings.othersSignedOut'), 'success');
	} catch (caught) {
		confirmRevokeOthers.value = false;
		showToast(loadErrorMessage(caught, t('settings.othersRevokeFailed')), 'error');
	} finally {
		sessionsBusy.value = false;
	}
}

onMounted(() => {
	const callback = new URLSearchParams(window.location.search).get('atproto');
	if (callback === 'connected' || callback === 'error') {
		showToast(callback === 'connected' ? t('settings.bskyConnected') : t('settings.bskyAuthFailed'), callback === 'connected' ? 'success' : 'error');
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
			:title="t('settings.title')"
			:subtitle="t('settings.subtitle')"
			back-href="#/"
			:back-label="t('settings.backHome')"
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
					{{ saving ? t('common.saving') : t('common.save') }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>{{ t('settings.signInRequired') }}</strong>
				<p>{{ t('settings.signInRequiredHint') }}</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<p v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load">{{ t('common.tryAgain') }}</button>
		</p>

		<div v-else-if="loading && preferences === null" class="a-management-skeleton" aria-hidden="true">
			<div v-for="index in 4" :key="index" class="a-skeleton a-management-skeleton__row" />
		</div>

		<div v-else-if="preferences !== null" class="a-settings">
			<section class="a-settings__section">
				<h2>{{ t('settings.language.label') }}</h2>
				<p class="a-settings__hint">{{ t('settings.language.hint') }}</p>
				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.language.label') }}</strong>
					</span>
					<select :value="preferences.ui_locale ?? ''" @change="setUiLocale">
						<option value="">{{ t('settings.language.browser') }}</option>
						<option value="en">{{ t('settings.language.english') }}</option>
						<option value="de">{{ t('settings.language.german') }}</option>
					</select>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.display.label') }}</h2>
				<p class="a-settings__hint">{{ t('settings.display.hint') }}</p>
				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.display.label') }}</strong>
					</span>
					<select :value="preferences.ui_density ?? 'comfortable'" @change="setUiDensity">
						<option value="comfortable">{{ t('settings.display.comfortable') }}</option>
						<option value="compact">{{ t('settings.display.compact') }}</option>
					</select>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.newPosts') }}</h2>
				<p class="a-settings__hint">{{ t('settings.newPostsHint') }}</p>

				<fieldset class="a-settings__fieldset">
					<legend>{{ t('settings.visibility') }}</legend>
					<label v-for="option in visibilityOptions" :key="option.value" class="a-settings__choice">
						<input
							type="radio"
							name="visibility"
							:value="option.value"
							:checked="preferences.default_visibility === option.value"
							@change="setVisibility(option.value)"
						>
						<span>
							<strong>{{ t(option.labelKey) }}</strong>
							<small>{{ t(option.hintKey) }}</small>
						</span>
					</label>
				</fieldset>

				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.showBoosts') }}</strong>
						<small>{{ t('settings.showBoostsHint') }}</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.show_boosts"
						@change="toggleFlag('show_boosts')"
					>
				</label>

				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.recommendations') }}</strong>
						<small>{{ t('settings.recommendationsHint') }}</small>
					</span>
					<input
						type="checkbox"
						:checked="!preferences.recommendations_opt_out"
						@change="toggleFlag('recommendations_opt_out')"
					>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.privacy') }}</h2>
				<p class="a-settings__hint">{{ t('settings.privacyHint') }}</p>

				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.protectedProfile') }}</strong>
						<small>{{ t('settings.protectedProfileHint') }}</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.locked"
						@change="toggleFlag('locked')"
					>
				</label>

				<div v-if="preferences.locked" class="a-settings__follow-requests">
					<span>{{ t('settings.followRequestsPending') }}</span>
					<a class="a-btn a-btn--ghost a-btn--small" href="#/follow-requests">
						<AppIcon name="lock" :size="15" />
						{{ t('settings.viewFollowRequests') }}
					</a>
				</div>

				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.discoverable') }}</strong>
						<small>{{ t('settings.discoverableHint') }}</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences.discoverable"
						@change="toggleFlag('discoverable')"
					>
				</label>

				<fieldset class="a-settings__fieldset">
					<legend>{{ t('settings.allowDm') }}</legend>
					<label v-for="option in dmOptions" :key="option.value" class="a-settings__choice a-settings__choice--inline">
						<input
							type="radio"
							name="dm"
							:value="option.value"
							:checked="preferences.dm_policy === option.value"
							@change="setDmPolicy(option.value)"
						>
						<span>{{ t(option.labelKey) }}</span>
					</label>
				</fieldset>

				<fieldset class="a-settings__fieldset">
					<legend>{{ t('settings.allowQuotes') }}</legend>
					<label v-for="option in quoteOptions" :key="option.value" class="a-settings__choice a-settings__choice--inline">
						<input
							type="radio"
							name="quote"
							:value="option.value"
							:checked="preferences.default_quote_policy === option.value"
							@change="setQuotePolicy(option.value)"
						>
						<span>{{ t(option.labelKey) }}</span>
					</label>
				</fieldset>
			</section>

			<section v-if="blueskyPublishEnabled" class="a-settings__section">
				<h2>{{ t('settings.bskyMirror') }}</h2>
				<p class="a-settings__hint">{{ t('settings.bskyMirrorHint') }}</p>
				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.mirrorLikes') }}</strong>
						<small>{{ t('settings.mirrorLikesHint') }}</small>
					</span>
					<input type="checkbox" :checked="preferences.mirror_likes_to_atproto" @change="toggleFlag('mirror_likes_to_atproto')">
				</label>
				<label class="a-settings__row">
					<span>
						<strong>{{ t('settings.mirrorBoosts') }}</strong>
						<small>{{ t('settings.mirrorBoostsHint') }}</small>
					</span>
					<input type="checkbox" :checked="preferences.mirror_boosts_to_atproto" @change="toggleFlag('mirror_boosts_to_atproto')">
				</label>
				<label v-if="interactionAccounts.length > 0" class="a-settings__row">
					<span>
						<strong>{{ t('settings.publishFrom') }}</strong>
						<small>{{ t('settings.publishFromHint') }}</small>
					</span>
					<select :value="preferences.mirror_atproto_account_did ?? ''" @change="setInteractionAccount">
						<option value="">{{ t('settings.defaultIdentity') }}</option>
						<option v-for="account in interactionAccounts" :key="account.did" :value="account.did">{{ account.label }}</option>
					</select>
				</label>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.notifications') }}</h2>
				<p class="a-settings__hint">{{ t('settings.notificationsHint') }}</p>
				<label v-for="option in notificationOptions" :key="option.key" class="a-settings__row">
					<span>
						<strong>{{ t(option.labelKey) }}</strong>
						<small>{{ t(option.hintKey) }}</small>
					</span>
					<input
						type="checkbox"
						:checked="preferences[option.key] === true"
						@change="toggleFlag(option.key)"
					>
				</label>
				<div class="a-settings__sender-policy">
					<h3>{{ t('settings.senderPolicy') }}</h3>
					<p class="a-settings__hint">{{ t('settings.senderPolicyHint') }}</p>
					<p v-if="notificationPolicyError !== ''" class="a-form-error" role="alert">{{ notificationPolicyError }}</p>
					<label v-for="category in senderCategories" :key="category.key" class="a-settings__row">
						<span><strong>{{ t(category.labelKey) }}</strong></span>
						<select
							:value="notificationPolicy?.actions[category.key] ?? 'allow'"
							:disabled="notificationPolicy === null || notificationPolicyBusy"
							@change="setNotificationAction(category.key, $event)"
						>
							<option value="allow">{{ t('settings.action.allow') }}</option>
							<option value="hold">{{ t('settings.action.hold') }}</option>
							<option value="drop">{{ t('settings.action.drop') }}</option>
						</select>
					</label>
					<label class="a-settings__row">
						<span>
							<strong>{{ t('settings.newAccountDays') }}</strong>
							<small>{{ t('settings.newAccountDaysHint') }}</small>
						</span>
						<span class="a-settings__inline-field">
							<input v-model.number="newAccountDays" type="number" min="0" max="3650" :disabled="notificationPolicy === null || notificationPolicyBusy">
							<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="notificationPolicy === null || notificationPolicyBusy" @click="saveNewAccountDays">{{ t('common.save') }}</button>
						</span>
					</label>
				</div>
			</section>

			<section v-if="atproto" class="a-settings__section">
				<h2>{{ t('settings.atprotoTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('settings.atprotoHint') }}
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
						{{ t('settings.repository') }} <a :href="atproto.identity.pds_url" target="_blank" rel="noopener noreferrer">{{ atproto.identity.pds_url }}</a>
					</p>

					<div v-if="atproto.identity.verification" class="a-settings__verify">
						<strong>{{ t('settings.verifyHandle') }}</strong>
						<p class="a-settings__hint">
							{{ t('settings.verifyHandleHint', { handle: atproto.identity.handle }) }}
						</p>
						<p class="a-settings__verify-record">
							<span>{{ atproto.identity.verification.dns_name }}</span>
							<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="copyHandleVerification('dns_name')">{{ t('settings.copyName') }}</button>
						</p>
						<p class="a-settings__verify-record">
							<span>{{ atproto.identity.verification.dns_value }}</span>
							<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="copyHandleVerification('dns_value')">{{ t('settings.copyValue') }}</button>
						</p>
						<p class="a-settings__hint">
							{{ t('settings.alternative') }} <a :href="atproto.identity.verification.well_known_url" target="_blank" rel="noopener noreferrer">{{ atproto.identity.verification.well_known_url }}</a>
						</p>
					</div>

					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="atprotoBusy" @click="rotateIdentityKeys">
						<AppIcon name="refresh" :size="15" :class="{ 'a-spin': atprotoBusy }" />
						{{ t('settings.rotateKeys') }}
					</button>

					<div v-if="atprotoSessions.length > 0" class="a-settings__sessions">
						<strong>{{ t('settings.connectedApps') }}</strong>
						<p class="a-settings__hint">
							{{ t('settings.connectedAppsHint') }}
						</p>
						<ul class="a-settings__session-list">
							<li v-for="session in atprotoSessions" :key="session.id" class="a-settings__session">
								<span class="a-settings__session-id"><code>{{ session.id }}…</code></span>
								<span class="a-settings__hint">
									{{ t('settings.sessionSignedIn', { created: relativeTime(session.created_at), until: relativeTime(session.refresh_expires_at) }) }}
								</span>
								<button
									type="button"
									class="a-btn a-btn--ghost a-btn--small"
									:disabled="atprotoSessionBusy !== ''"
									@click="revokeAtprotoSession(session.id)"
								>
									{{ atprotoSessionBusy === session.id ? t('settings.revoking') : t('settings.revoke') }}
								</button>
							</li>
						</ul>
					</div>
				</div>

				<form v-else-if="atproto.enabled" class="a-settings__bluesky" @submit.prevent="createIdentity">
					<label class="a-settings__field">
						<span>{{ t('settings.handle') }} <small>{{ t('settings.handleOptional') }}</small></span>
						<input v-model="atprotoHandle" type="text" :placeholder="`yourname.${atproto.handle_domain}`">
						<small>{{ t('settings.handleHint', { domain: atproto.handle_domain }) }}</small>
					</label>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="atprotoBusy">
						<AppIcon name="plus" :size="15" />
						{{ t('settings.createDid') }}
					</button>
				</form>

				<p v-else class="a-settings__hint">
					{{ t('settings.pdsDisabled') }}
				</p>
			</section>

			<section class="a-settings__section">
				<h2>Bluesky</h2>
				<p class="a-settings__hint">
					{{ t('settings.bskyHint') }}
				</p>

				<div v-if="bluesky.connected" class="a-settings__bluesky">
					<div class="a-settings__bluesky-status">
						<AppIcon name="check" :size="18" />
						<span>
							<strong>@{{ bluesky.handle }}</strong>
							<small>{{ t('settings.connectedTo', { url: bluesky.pds_url }) }}</small>
						</span>
					</div>
					<p v-if="blueskyPublishEnabled" class="a-settings__hint">
						{{ t('settings.bskyMirrorNote') }}
					</p>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="blueskyBusy" @click="disconnectBluesky">
						<AppIcon :name="blueskyBusy ? 'refresh' : 'x'" :size="15" :class="{ 'a-spin': blueskyBusy }" />
						{{ t('settings.disconnect') }}
					</button>
				</div>

				<form v-else class="a-settings__bluesky" @submit.prevent="connectBluesky">
					<label class="a-settings__field">
						<span>{{ t('settings.handle') }}</span>
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
						{{ blueskyBusy ? t('settings.openingBsky') : t('settings.continueBsky') }}
					</button>
				</form>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.moveTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('settings.moveHint') }}
				</p>
				<div v-if="accountMove" class="a-settings__bluesky">
					<strong>{{ t('settings.moveAnnounced') }}</strong>
					<a :href="accountMove.target_actor_id" target="_blank" rel="noopener noreferrer">{{ accountMove.target_actor_id }}</a>
					<small>{{ t('settings.moveProcessed', { count: accountMove.processed_followers }) }}</small>
				</div>
				<form v-else class="a-settings__bluesky" @submit.prevent="startAccountMove">
					<label class="a-settings__field">
						<span>{{ t('settings.moveTarget') }}</span>
						<input v-model="moveTarget" type="url" placeholder="https://social.example/@you" required :disabled="moveBusy">
						<small>{{ t('settings.moveTargetHint') }}</small>
					</label>
					<p v-if="confirmMove" class="a-settings__hint" role="alert">
						{{ t('settings.moveConfirmWarning') }}
					</p>
					<button type="submit" class="a-btn a-btn--danger a-btn--small" :disabled="moveBusy">
						<AppIcon :name="moveBusy ? 'refresh' : 'arrowRight'" :size="15" :class="{ 'a-spin': moveBusy }" />
						{{ moveBusy ? t('settings.announcing') : confirmMove ? t('settings.confirmMove') : t('settings.moveAccount') }}
					</button>
				</form>
			</section>

			<section v-if="emailChange !== null" class="a-settings__section">
				<h2>{{ t('settings.emailTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('settings.emailHint') }}
				</p>
				<p v-if="emailChange.email !== ''" class="a-settings__hint">
					{{ t('settings.currentAddress') }} <strong>{{ emailChange.email }}</strong>
				</p>

				<div v-if="emailChange.pending !== null" class="a-settings__sender-policy">
					<p>
						{{ t('settings.emailPendingPrefix') }} <strong>{{ emailChange.pending.email }}</strong>.
						{{ t('settings.emailPendingSuffix') }}
						<template v-if="emailChange.pending.expires_at !== ''">{{ t('settings.expiresOn', { date: emailChange.pending.expires_at }) }}</template>
						<template v-else>{{ t('settings.expiresIn24') }}</template>.
					</p>
					<button
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="emailBusy"
						@click="cancelPendingEmail"
					>
						{{ emailBusy ? t('common.working') : confirmCancelEmail ? t('settings.confirmCancel') : t('settings.cancelChange') }}
					</button>
				</div>

				<form class="a-settings__sender-policy" @submit.prevent="submitEmailChange">
					<label>
						<span>{{ t('settings.newEmail') }}</span>
						<input v-model="newEmail" class="a-input" type="email" maxlength="254" autocomplete="email" />
					</label>
					<label>
						<span>{{ t('settings.confirmPassword') }}</span>
						<input v-model="emailPassword" class="a-input" type="password" autocomplete="current-password" />
					</label>
					<p v-if="emailError !== ''" class="a-form-error" role="alert">{{ emailError }}</p>
					<button type="submit" class="a-btn a-btn--ghost a-btn--small" :disabled="emailBusy">
						{{ emailBusy ? t('settings.sending') : t('settings.requestEmailChange') }}
					</button>
				</form>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.mfaTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('settings.mfaHint') }}
				</p>

				<div v-if="mfaRecoveryCodes.length > 0" class="a-settings__sender-policy">
					<h3>{{ t('settings.recoveryCodes') }}</h3>
					<p class="a-settings__hint">
						{{ t('settings.recoveryCodesHint') }}
					</p>
					<ul>
						<li v-for="code in mfaRecoveryCodes" :key="code"><code>{{ code }}</code></li>
					</ul>
				</div>

				<template v-else-if="mfa.enabled">
					<p class="a-settings__hint"><strong>{{ t('settings.mfaEnabledNote') }}</strong> {{ t('settings.mfaEnabledHint') }}</p>
					<label class="a-settings__row">
						<span>
							<strong>{{ t('settings.disableMfa') }}</strong>
							<small>{{ t('settings.disableMfaHint') }}</small>
						</span>
						<span class="a-settings__inline-field">
							<input v-model="mfaCode" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" :disabled="mfaBusy">
							<button type="button" class="a-btn a-btn--danger a-btn--small" :disabled="mfaBusy || mfaCode.trim() === ''" @click="disableMfa">{{ t('settings.disable') }}</button>
						</span>
					</label>
				</template>

				<template v-else-if="mfaEnrollment !== null">
					<div class="a-settings__sender-policy">
						<h3>{{ t('settings.setupAuthenticator') }}</h3>
						<p class="a-settings__hint">{{ t('settings.setupAuthenticatorHint') }}</p>
						<p><code class="a-settings__secret">{{ mfaEnrollment.secret }}</code></p>
						<p class="a-settings__hint">{{ t('settings.setupLink') }} <code>{{ mfaEnrollment.otpauth_uri }}</code></p>
						<label class="a-settings__row">
							<span>
								<strong>{{ t('settings.verificationCode') }}</strong>
								<small>{{ t('settings.verificationCodeHint') }}</small>
							</span>
							<span class="a-settings__inline-field">
								<input v-model="mfaCode" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" :disabled="mfaBusy">
								<button type="button" class="a-btn a-btn--primary a-btn--small" :disabled="mfaBusy || mfaCode.trim() === ''" @click="confirmMfa">{{ t('settings.verifyEnable') }}</button>
							</span>
						</label>
					</div>
				</template>

				<template v-else>
					<p v-if="mfa.pending" class="a-settings__hint">{{ t('settings.mfaPendingHint') }}</p>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="mfaBusy" @click="beginMfa">
						<AppIcon :name="mfaBusy ? 'refresh' : 'lock'" :size="15" :class="{ 'a-spin': mfaBusy }" />
						{{ mfaBusy ? t('settings.starting') : mfa.pending ? t('settings.resumeSetup') : t('settings.enableMfa') }}
					</button>
				</template>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.pushTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('settings.pushHint') }}
				</p>
				<template v-if="pushState === 'unsupported'">
					<p class="a-settings__hint">{{ t('settings.pushUnsupported') }}</p>
				</template>
				<template v-else-if="pushState === 'denied'">
					<p class="a-settings__hint">
						{{ t('settings.pushDenied') }}
					</p>
				</template>
				<template v-else>
					<label class="a-settings__row">
						<span>
							<strong>{{ pushState === 'on' ? t('settings.pushOn') : t('settings.pushOff') }}</strong>
							<small v-if="pushState === 'on'">{{ t('settings.pushOnHint') }}</small>
							<small v-else>{{ t('settings.pushOffHint') }}</small>
						</span>
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="pushBusy || pushState === 'loading'"
							@click="togglePush"
						>
							<AppIcon :name="pushBusy ? 'refresh' : 'bell'" :size="15" :class="{ 'a-spin': pushBusy }" />
							{{ pushBusy ? t('common.working') : pushState === 'on' ? t('settings.turnOff') : t('settings.turnOn') }}
						</button>
					</label>
				</template>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.sessionsTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('settings.sessionsHint') }}
				</p>
				<div v-if="sessions.length === 0" class="a-settings__hint">{{ t('settings.noSessions') }}</div>
				<label v-for="session in sessions" :key="session.id" class="a-settings__row">
					<span>
						<strong>{{ sessionLabel(session) }}<template v-if="session.current"> {{ t('settings.thisDevice') }}</template></strong>
						<small>
							{{ t('settings.signedInAt', { date: sessionDate(session.created_at) }) }}
							<template v-if="session.ip !== null"> · {{ t('settings.network', { ip: session.ip }) }}</template>
						</small>
					</span>
					<button
						v-if="!session.current"
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="sessionsBusy"
						@click="revokeSession(session)"
					>
						{{ t('common.signOut') }}
					</button>
				</label>
				<button
					v-if="sessions.length > 1"
					type="button"
					class="a-btn a-btn--ghost a-btn--small"
					:disabled="sessionsBusy"
					@click="revokeOtherSessions"
				>
					{{ sessionsBusy ? t('settings.signingOut') : confirmRevokeOthers ? t('settings.confirmRevokeOthers') : t('settings.signOutOthers') }}
				</button>
			</section>

			<section class="a-settings__section">
				<h2>{{ t('settings.dataTitle') }}</h2>
				<p class="a-settings__hint">
					{{ t('settings.exportHint') }}
				</p>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="exporting" @click="exportAccount">
					<AppIcon :name="exporting ? 'refresh' : 'share'" :size="15" :class="{ 'a-spin': exporting }" />
					{{ exporting ? t('settings.creating') : t('settings.dataExport') }}
				</button>
				<p class="a-settings__hint">
					{{ t('settings.importHint') }}
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
					{{ importBusy ? t('common.working') : t('settings.importData') }}
				</button>
				<p v-if="importError !== ''" class="a-form-error" role="alert">{{ importError }}</p>
				<div v-if="importPreview !== null" class="a-import">
					<p class="a-settings__hint">
						{{ t('settings.importPreviewLine', { source: importSourceLabel(importPreview), applied: importPreview.totals.local_rows, total: importPreview.totals.rows }) }}
					</p>
					<ul class="a-import__sections">
						<li v-for="section in importPreview.sections" :key="section.name" class="a-import__section">
							<span class="a-import__name">{{ section.name }}</span>
							<span class="a-import__rows">{{ section.rows }} {{ section.rows === 1 ? t('settings.import.row') : t('settings.import.rows') }}</span>
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
						{{ importBusy ? t('settings.importing') : t('settings.applyImport') }}
					</button>
				</div>
				<div v-if="importResult !== null" class="a-import">
					<ul class="a-import__sections">
						<li v-for="result in importResult.results" :key="result.name" class="a-import__section">
							<span class="a-import__name">{{ result.name }}</span>
							<span class="a-import__badge" :class="`a-import__badge--${result.status}`">{{ result.status }}</span>
							<span v-if="result.status === 'applied'" class="a-import__rows">
								{{ t('settings.import.appliedCount', { count: result.rows ?? 0 }) }}<template v-if="(result.skipped_rows ?? 0) > 0">, {{ t('settings.import.skippedCount', { count: result.skipped_rows ?? 0 }) }}</template>
							</span>
							<span v-else-if="result.reason !== undefined" class="a-import__reason">{{ result.reason }}</span>
						</li>
					</ul>
				</div>
				<p class="a-settings__hint">
					{{ t('settings.deleteAccountHint') }}
				</p>
				<button
					type="button"
					class="a-btn a-btn--danger a-btn--small"
					:disabled="deleting"
					@click="deleteAccount"
				>
					<AppIcon :name="deleting ? 'refresh' : 'trash'" :size="15" :class="{ 'a-spin': deleting }" />
					{{ deleting ? t('settings.deleting') : confirmDeletion ? t('settings.confirmDelete') : t('settings.deleteAccount') }}
				</button>
			</section>
		</div>
	</div>
</template>
