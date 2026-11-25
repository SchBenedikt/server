<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi, type AccountMoveStatus, type AtprotoIdentityData } from '../lib/api';
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
