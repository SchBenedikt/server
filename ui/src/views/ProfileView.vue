<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onUnmounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import FollowButton from '../components/FollowButton.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import ReportDialog from '../components/ReportDialog.vue';
import TimelineSkeleton from '../components/TimelineSkeleton.vue';
import { hostDisplayName, refreshAccount, setAccount, setHostDisplayName } from '../lib/account';
import { getApi, hydrateTimelineBoosts } from '../lib/api';
import { onPostCreated, onPostDeleted, onPostUpdated } from '../lib/events';
import { accountName, absoluteTime, formatCount, handleLabel, isRemoteId, truncate } from '../lib/format';
import { route } from '../lib/router';
import { t } from '../lib/i18n';
import { appState, openComposer, showToast } from '../lib/state';
import type { Account, AtprotoFollowEntry, FeaturedTag, RemoteFollowingEntry, RemoteProfileData, TimelineItem } from '../lib/types';

const PAGE_SIZE = 40;

/**
 * A route handle is remote when it is an absolute URL, an `@user@host`
 * address, a Bluesky handle or an AT Protocol DID. Local handles are plain
 * account names, so this cannot misroute a local profile.
 */
function looksRemote(id: string): boolean {
	const value = id.trim();
	if (value === '') {
		return false;
	}
	if (isRemoteId(value) || /^did:/i.test(value)) {
		return true;
	}

	const address = value.replace(/^@/, '');
	if (/^[^@\s]+@[^@\s]+$/.test(address)) {
		return true;
	}
	if (/^(?:www\.)?bsky\.app\/profile\//i.test(value)) {
		return true;
	}

	return /^[^@\s/]+\.[A-Za-z]{2,}$/.test(address);
}

const handle = computed(() => route.value.name === 'profile' ? route.value.handle : '');
const isRemoteProfile = computed(() => looksRemote(handle.value));
const isSelf = computed(() => !isRemoteProfile.value && appState.session.user?.handle === handle.value);
const signedIn = computed(() => appState.session.user !== null);

const account = ref<Account | null>(null);
const remoteKind = ref<'activitypub' | 'bluesky' | null>(null);
const remoteFollowState = ref<'idle' | 'sending' | 'sent'>('idle');
const remoteFollowingList = ref<RemoteFollowingEntry[]>([]);
const atprotoFollow = ref<AtprotoFollowEntry | null>(null);
const atprotoFollowAvailable = ref(false);
const loading = ref(false);
const error = ref('');
const tab = ref<'posts' | 'media'>(
	route.value.name === 'profile' && route.value.tab === 'media' ? 'media' : 'posts',
);
watch(() => route.value.name === 'profile' ? route.value.tab : null, (value) => {
	if (value !== null) {
		tab.value = value;
	}
});

const posts = ref<TimelineItem[]>([]);
const postsLoading = ref(false);
const postsError = ref('');
const featuredTags = ref<FeaturedTag[]>([]);
const archivedIds = ref<Set<string>>(new Set());
const pinnedIds = ref<string[]>([]);
const editTags = ref('');
const editing = ref(false);
const editName = ref('');
const editSaving = ref(false);
const editError = ref('');
const cursor = ref<string | null>(null);
const hasMore = ref(true);
const loadedOnce = ref(false);

const connectionType = ref<'followers' | 'following' | null>(null);
const connectionProfiles = ref<Account[]>([]);
const connectionCursor = ref<string | null>(null);
const connectionLoading = ref(false);
const connectionError = ref('');
const connectionNetwork = ref<'activitypub' | 'bluesky'>('activitypub');
const connectionLocal = ref(false);
const connectionGeneration = ref(0);
const connectionTitle = computed(() => t(connectionType.value === 'followers' ? 'profile.followersTitle' : 'profile.followingTitle'));
const connectionEmptyText = computed(() => {
	if (connectionLocal.value) {
		return t(connectionType.value === 'followers'
			? 'profile.emptyFollowersLocal'
			: 'profile.emptyFollowingLocal');
	}

	return t(connectionType.value === 'followers'
		? 'profile.emptyFollowersRemote'
		: 'profile.emptyFollowingRemote');
});
const joinedLabel = computed(() => {
	const value = account.value?.published_at ?? '';
	return value !== '' ? absoluteTime(value) : '';
});

const displayName = computed(() => {
	if (isSelf.value) {
		return appState.session.user?.displayName
			|| appState.session.profile?.display_name
			|| handleLabel(handle.value);
	}

	if (isRemoteProfile.value) {
		return accountName(account.value, handle.value);
	}

	return hostDisplayName(handle.value) || accountName(account.value, handle.value);
});
const handleText = computed(() => (
	isRemoteProfile.value && account.value?.handle !== undefined && account.value.handle !== ''
		? account.value.handle
		: handleLabel(handle.value)
));
const showHandle = computed(() => displayName.value !== handleText.value);
const coverStyle = computed(() => {
	const banner = account.value?.banner ?? '';
	if (banner !== '') {
		return {
			backgroundImage: `url(${JSON.stringify(banner)})`,
			backgroundSize: 'cover',
			backgroundPosition: 'center',
		};
	}

	return {};
});
const profileUrl = computed(() => `${window.location.origin}/app#/u/${encodeURIComponent(handle.value)}`);
const relationship = computed(() => account.value?.relationship ?? null);
const isMuted = computed(() => relationship.value?.muting === true);
const isBlocked = computed(() => relationship.value?.blocking === true);
const canModerate = computed(() => (
	signedIn.value
	&& !isSelf.value
	&& account.value !== null
	&& (!isRemoteProfile.value || remoteKind.value === 'activitypub')
));
const reportTargetId = computed(() => {
	const target = account.value;
	if (target === null) {
		return handle.value;
	}
	if (!isRemoteProfile.value) {
		return target.id;
	}

	for (const candidate of [target.id, target.url, target.handle, handle.value]) {
		if (candidate !== undefined && /^https?:\/\//i.test(candidate)) {
			return candidate;
		}
	}

	return target.id !== '' ? target.id : handle.value;
});
const canReport = computed(() => (
	signedIn.value
	&& !isSelf.value
	&& account.value !== null
	&& reportTargetId.value !== ''
));
const reportOpen = ref(false);
const moderationBusy = ref<'mute' | 'block' | ''>('');

function setRelationshipFlag(name: 'muting' | 'blocking', value: boolean): void {
	if (account.value === null) {
		return;
	}

	if (account.value.relationship === null) {
		account.value.relationship = {
			following: false,
			followed_by: false,
			blocking: false,
			muting: false,
			requested: false,
		};
	}

	account.value.relationship[name] = value;
}

async function toggleMute(): Promise<void> {
	const target = account.value;
	if (target === null || !canModerate.value || moderationBusy.value !== '') {
		return;
	}

	moderationBusy.value = 'mute';
	try {
		if (isMuted.value) {
			await getApi().unmuteAccount(target.id);
			setRelationshipFlag('muting', false);
			showToast(t('profile.muteRemoved', { handle: target.handle }), 'success');
		} else {
			await getApi().muteAccount(target.id);
			setRelationshipFlag('muting', true);
			showToast(t('profile.muted', { handle: target.handle }), 'success');
		}
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('profile.muteFailed'), 'error');
	} finally {
		moderationBusy.value = '';
	}
}

function sameActor(left: string, right: string): boolean {
	return left.replace(/\/+$/, '') === right.replace(/\/+$/, '');
}

async function toggleBlock(): Promise<void> {
	const target = account.value;
	if (target === null || !canModerate.value || moderationBusy.value !== '') {
		return;
	}

	moderationBusy.value = 'block';
	try {
		if (isBlocked.value) {
			const entries = await getApi().blocks();
			const entry = entries.find((candidate) => (
				sameActor(candidate.actor, target.id) || sameActor(candidate.actor, target.url)
			));
			if (entry === undefined) {
				throw new Error(t('profile.blockNotFound'));
			}

			await getApi().unblockActor(entry.id);
			setRelationshipFlag('blocking', false);
			showToast(t('profile.blockRemoved', { handle: target.handle }), 'success');
		} else {
			const actor = target.id.startsWith('http') ? target.id : target.url;
			await getApi().blockActor(actor);
			setRelationshipFlag('blocking', true);
			showToast(t('profile.blocked', { handle: target.handle }), 'success');
		}
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('profile.blockFailed'), 'error');
	} finally {
		moderationBusy.value = '';
	}
}

const visiblePosts = computed(() => tab.value === 'media'
	? posts.value.filter((post) => (post.attachments?.length ?? 0) > 0)
	: posts.value);
const remoteRelationship = computed<RemoteFollowingEntry | null>(() => (
	remoteFollowingList.value.find((entry) => entry.actor === (account.value?.id ?? '')) ?? null
));
const canRemoteFollow = computed(() => (
	isRemoteProfile.value
	&& signedIn.value
	&& (remoteKind.value === 'bluesky'
		? atprotoFollowAvailable.value && account.value?.did !== undefined && account.value.did !== null && atprotoFollow.value === null
		: remoteRelationship.value === null && account.value?.can_follow === true
			&& typeof account.value?.inbox === 'string' && account.value.inbox !== '')
));
const remoteFollowLabel = computed(() => {
	if (remoteKind.value === 'bluesky' && atprotoFollow.value !== null) {
		return t(atprotoFollow.value.state === 'pending' ? 'profile.followRetry' : 'profile.following');
	}
	const relationship = remoteRelationship.value;
	if (relationship !== null) {
		return t(relationship.state === 'accepted' ? 'profile.following' : 'profile.requested');
	}

	return t(remoteFollowState.value === 'sending' ? 'profile.sending' : 'profile.follow');
});
const emptyText = computed(() => {
	if (tab.value === 'media') {
		return t(isRemoteProfile.value ? 'profile.emptyMediaRemote' : 'profile.emptyMediaLocal');
	}
	if (isSelf.value) {
		return t('profile.emptySelf');
	}

	return t(isRemoteProfile.value ? 'profile.emptyRemote' : 'profile.emptyLocal');
});
const externalProfileUrl = computed(() => {
	const url = account.value?.url ?? '';
	return isRemoteId(url) ? url : (isRemoteId(handle.value) ? handle.value : '');
});
const remoteHost = computed(() => {
	const url = account.value?.url ?? (isRemoteId(handle.value) ? handle.value : '');
	try {
		return new URL(url).hostname.replace(/^www\./, '');
	} catch {
		const match = handleText.value.match(/@([^@]+)$/);
		return match?.[1] ?? '';
	}
});
const networkLabel = computed(() => {
	if (remoteKind.value === 'bluesky') {
		return 'Bluesky';
	}

	return remoteHost.value !== '' ? remoteHost.value : t('profile.networkFederated');
});
const networkIcon = computed(() => remoteKind.value === 'bluesky' ? 'sparkles' : 'globe');

let generation = 0;

watch(handle, () => {
	remoteFollowState.value = 'idle';
	remoteFollowingList.value = [];
	atprotoFollow.value = null;
	atprotoFollowAvailable.value = false;
	void loadProfile();
}, { immediate: true });

async function loadProfile(): Promise<void> {
	const current = ++generation;
	closeConnections();
	account.value = null;
	remoteKind.value = null;
	remoteFollowState.value = 'idle';
	posts.value = [];
	error.value = '';
	postsError.value = '';
	cursor.value = null;
	hasMore.value = true;
	loadedOnce.value = false;

	if (handle.value === '') {
		return;
	}

	if (isRemoteProfile.value) {
		await loadRemote(true);
		void loadRemoteFollowing();
		return;
	}

	loading.value = true;
	try {
		const loaded = await getApi().account(handle.value);
		if (current !== generation) {
			return;
		}
		account.value = loaded;
		setAccount(loaded);
		if (loaded.handle === appState.session.user?.handle) {
			void loadArchivedIds();
		}
	} catch (caught) {
		if (current === generation) {
			error.value = caught instanceof Error ? caught.message : t('profile.loadFailed');
		}
	} finally {
		if (current === generation) {
			loading.value = false;
		}
	}

	if (current === generation && error.value === '') {
		await loadPinnedIds();
		await loadPosts(true);
		void loadFeaturedTags();
	}
}

async function loadArchivedIds(): Promise<void> {
	try {
		archivedIds.value = new Set((await getApi().archivedPosts()).map((row) => row.post_id));
	} catch {
		archivedIds.value = new Set();
	}
}

async function loadPinnedIds(): Promise<void> {
	try {
		pinnedIds.value = await getApi().pinnedPosts(handle.value);
	} catch {
		pinnedIds.value = [];
	}
}

function onPinned(post: TimelineItem, pinned: boolean): void {
	if (pinned) {
		if (!pinnedIds.value.includes(post.id)) {
			pinnedIds.value = [...pinnedIds.value, post.id];
		}
	} else {
		pinnedIds.value = pinnedIds.value.filter((id) => id !== post.id);
	}
}

const displayPosts = computed(() => {
	const list = visiblePosts.value;
	if (pinnedIds.value.length === 0) {
		return list;
	}

	const order = new Map(pinnedIds.value.map((id, index) => [id, index]));

	return [...list].sort((left, right) => {
		const leftPin = order.get(left.id);
		const rightPin = order.get(right.id);
		if (leftPin !== undefined || rightPin !== undefined) {
			if (leftPin === undefined) {
				return 1;
			}
			if (rightPin === undefined) {
				return -1;
			}
			return leftPin - rightPin;
		}

		return new Date(right.published_at).getTime() - new Date(left.published_at).getTime();
	});
});

async function loadFeaturedTags(): Promise<void> {
	if (handle.value === '' || isRemoteProfile.value) {
		featuredTags.value = [];
		return;
	}

	try {
		featuredTags.value = await getApi().featuredTags(handle.value);
	} catch {
		featuredTags.value = [];
	}
}

async function loadRemote(reset: boolean): Promise<void> {
	if (handle.value === '' || !isRemoteProfile.value) {
		return;
	}
	if (!reset && (postsLoading.value || !hasMore.value)) {
		return;
	}

	const current = generation;
	postsLoading.value = true;
	postsError.value = '';
	error.value = '';
	if (reset) {
		loading.value = true;
		posts.value = [];
		cursor.value = null;
		hasMore.value = true;
	}

	try {
		const data: RemoteProfileData = await getApi().remoteProfile(handle.value, reset ? null : cursor.value);
		if (current !== generation) {
			return;
		}

		account.value = data.profile;
		remoteKind.value = data.kind;

		const collected = reset ? [] : [...posts.value];
		const seen = new Set(collected.map((post) => post.id));
		for (const post of data.posts) {
			if (!seen.has(post.id)) {
				seen.add(post.id);
				collected.push(post);
			}
		}

		posts.value = collected;
		cursor.value = data.cursor;
		hasMore.value = data.cursor !== null && data.posts.length > 0;
		loadedOnce.value = true;
	} catch (caught) {
		const message = caught instanceof Error ? caught.message : t('profile.remoteLoadFailed');
		if (current === generation) {
			if (reset) {
				error.value = message;
			} else {
				postsError.value = message;
			}
		}
	} finally {
		if (current === generation) {
			loading.value = false;
			postsLoading.value = false;
		}
	}
}

async function loadPosts(reset: boolean): Promise<void> {
	if (handle.value === '') {
		return;
	}
	if (isRemoteProfile.value) {
		await loadRemote(reset);
		return;
	}
	if (!reset && (postsLoading.value || !hasMore.value)) {
		return;
	}

	const current = generation;
	postsLoading.value = true;
	postsError.value = '';

	if (reset) {
		posts.value = [];
		cursor.value = null;
		hasMore.value = true;
	}

	try {
		const pages = reset ? 3 : 1;
		let collected = reset ? [] : [...posts.value];
		const seen = new Set(collected.map((post) => post.id));
		let nextCursor = reset ? null : cursor.value;

		for (let page = 0; page < pages; page += 1) {
			const batch = await getApi().accountStatuses(handle.value, PAGE_SIZE, nextCursor);
			if (current !== generation) {
				return;
			}

			for (const post of batch.posts) {
				if (!seen.has(post.id)) {
					seen.add(post.id);
					collected.push(post);
				}
			}

			nextCursor = batch.next_before;
			if (nextCursor === null) {
				hasMore.value = false;
				break;
			}
		}

		cursor.value = nextCursor;
		hasMore.value = nextCursor !== null;

		if (reset) {
			const missingPins = pinnedIds.value.filter((id) => !seen.has(id));
			if (missingPins.length > 0) {
				try {
					const extra = await getApi().posts(missingPins);
					for (const id of missingPins) {
						const post = extra[id];
						if (post !== undefined && !seen.has(post.id)) {
							seen.add(post.id);
							collected.push(post);
						}
					}
				} catch {
					// A pinned post that no longer resolves simply stays hidden.
				}
			}
		}

		const visible = isSelf.value
			? collected.filter((post) => !archivedIds.value.has(post.id))
			: collected;
		posts.value = await hydrateTimelineBoosts(visible.sort((left, right) => (
			new Date(right.published_at).getTime() - new Date(left.published_at).getTime()
		)));
		loadedOnce.value = true;
	} catch (caught) {
		postsError.value = caught instanceof Error ? caught.message : t('profile.postsFailed');
	} finally {
		postsLoading.value = false;
	}
}

async function openConnections(type: 'followers' | 'following'): Promise<void> {
	if (account.value === null || handle.value === '') {
		return;
	}

	const current = ++connectionGeneration.value;
	connectionType.value = type;
	connectionLocal.value = !isRemoteProfile.value;
	connectionProfiles.value = [];
	connectionCursor.value = null;
	connectionError.value = '';
	connectionLoading.value = false;
	await loadConnections(true, current);
}

async function loadConnections(reset: boolean, expected = connectionGeneration.value): Promise<void> {
	if (connectionType.value === null || handle.value === '') {
		return;
	}
	if (!reset && (connectionLoading.value || connectionCursor.value === null)) {
		return;
	}

	connectionLoading.value = true;
	connectionError.value = '';
	if (reset) {
		connectionProfiles.value = [];
		connectionCursor.value = null;
	}

	try {
		const data = connectionLocal.value
			? await getApi().accountConnections(
				handle.value,
				connectionType.value,
				reset ? null : connectionCursor.value,
			)
			: await getApi().remoteConnections(
				handle.value,
				connectionType.value,
				reset ? null : connectionCursor.value,
			);
		if (expected !== connectionGeneration.value) {
			return;
		}

		const collected = reset ? [] : [...connectionProfiles.value];
		const seen = new Set(collected.map((profile) => profile.id));
		for (const profile of data.profiles) {
			if (!seen.has(profile.id)) {
				seen.add(profile.id);
				collected.push(profile);
			}
		}

		connectionProfiles.value = collected;
		connectionCursor.value = data.cursor;
		if ('network' in data) {
			connectionNetwork.value = data.network;
		}
	} catch (caught) {
		if (expected === connectionGeneration.value) {
			connectionError.value = caught instanceof Error
				? caught.message
				: t('profile.connectionsFailed');
		}
	} finally {
		if (expected === connectionGeneration.value) {
			connectionLoading.value = false;
		}
	}
}

function closeConnections(): void {
	connectionGeneration.value += 1;
	connectionType.value = null;
	connectionProfiles.value = [];
	connectionCursor.value = null;
	connectionError.value = '';
	connectionLoading.value = false;
}

function connectionProfileHref(profile: Account): string {
	return `#/u/${encodeURIComponent(profile.handle)}`;
}

function toggleRemoteFollow(): void {
	const followed = remoteKind.value === 'bluesky'
		? atprotoFollow.value?.state === 'active'
		: remoteRelationship.value !== null;
	if (followed) {
		void unfollowRemote();
	} else {
		void followRemote();
	}
}

async function loadRemoteFollowing(): Promise<void> {
	if (!signedIn.value || !isRemoteProfile.value) {
		return;
	}

	try {
		if (remoteKind.value === 'bluesky' && account.value?.did) {
			const status = await getApi().atprotoFollowStatus(account.value.did);
			atprotoFollow.value = status.follow;
			atprotoFollowAvailable.value = status.available;
			return;
		}
		remoteFollowingList.value = await getApi().remoteFollowing();
	} catch {
		// The public profile stays readable even when the follow list is unavailable.
	}
}

async function followRemote(): Promise<void> {
	const profile = account.value;
	const canRetryAtproto = remoteKind.value === 'bluesky' && atprotoFollow.value?.state === 'pending';
	if (profile === null || (!canRemoteFollow.value && !canRetryAtproto) || remoteFollowState.value === 'sending') {
		return;
	}

	remoteFollowState.value = 'sending';
	try {
		if (remoteKind.value === 'bluesky' && profile.did) {
			atprotoFollow.value = await getApi().followAtproto(profile.did);
			remoteFollowState.value = 'sent';
			showToast(t('profile.followBsky', { handle: profile.handle }), 'success');
			return;
		}
		const entry = await getApi().followRemote(profile.id, profile.inbox as string);
		remoteFollowingList.value = [
			...remoteFollowingList.value.filter((item) => item.actor !== entry.actor),
			entry,
		];
		remoteFollowState.value = 'sent';
		showToast(t('profile.followSent', { handle: profile.handle }), 'success');
	} catch (caught) {
		remoteFollowState.value = 'idle';
		showToast(caught instanceof Error ? caught.message : t('profile.followFailed'), 'error');
	}
}

async function unfollowRemote(): Promise<void> {
	if (remoteKind.value === 'bluesky' && atprotoFollow.value !== null && account.value?.did) {
		remoteFollowState.value = 'sending';
		try {
			await getApi().unfollowAtproto(account.value.did);
			atprotoFollow.value = null;
			remoteFollowState.value = 'idle';
			showToast(t('profile.unfollowBsky', { handle: account.value.handle }), 'success');
		} catch (caught) {
			remoteFollowState.value = 'idle';
			showToast(caught instanceof Error ? caught.message : t('profile.unfollowFailed'), 'error');
		}
		return;
	}
	const relationship = remoteRelationship.value;
	if (relationship === null || remoteFollowState.value === 'sending') {
		return;
	}

	remoteFollowState.value = 'sending';
	try {
		await getApi().unfollowRemote(relationship.id);
		remoteFollowingList.value = remoteFollowingList.value.filter((item) => item.id !== relationship.id);
		remoteFollowState.value = 'idle';
		showToast(t('profile.unfollowed', { handle: account.value?.handle ?? t('common.account') }), 'success');
	} catch (caught) {
		remoteFollowState.value = 'idle';
		showToast(caught instanceof Error ? caught.message : t('profile.unfollowRequestFailed'), 'error');
	}
}

async function share(): Promise<void> {
	try {
		await navigator.clipboard.writeText(profileUrl.value);
		showToast(t('profile.linkCopied'), 'success');
	} catch {
		window.prompt(t('profile.linkPrompt'), profileUrl.value);
	}
}

function openReport(): void {
	if (!signedIn.value) {
		window.location.assign('/login');
		return;
	}

	reportOpen.value = true;
}

function compose(): void {
	openComposer();
}

function openEdit(): void {
	editName.value = appState.session.user?.displayName
		|| appState.session.profile?.display_name
		|| account.value?.display_name
		|| handle.value;
	editError.value = '';
	editTags.value = featuredTags.value.map((tag) => tag.tag).join(', ');
	editing.value = true;
}

function closeEdit(): void {
	if (!editSaving.value) {
		editing.value = false;
	}
}

async function saveProfile(): Promise<void> {
	const name = editName.value.trim();
	if (name === '') {
		editError.value = t('profile.nameRequired');
		return;
	}
	if (Array.from(name).length > 80) {
		editError.value = t('profile.nameTooLong');
		return;
	}

	editSaving.value = true;
	editError.value = '';
	try {
		const saved = await getApi().updateProfile(name);
		if (isSelf.value) {
			const tags = editTags.value.split(',').map((tag) => tag.trim()).filter((tag) => tag !== '');
			featuredTags.value = await getApi().setFeaturedTags(tags);
		}
		if (appState.session.user !== null) {
			appState.session.user.displayName = saved;
		}
		if (appState.session.profile !== null) {
			appState.session.profile.display_name = saved;
		}
		setHostDisplayName(handle.value, saved);
		if (account.value !== null) {
			account.value.display_name = saved;
		}
		void refreshAccount(handle.value);
		editing.value = false;
		showToast(t('profile.updated'), 'success');
	} catch (caught) {
		editError.value = caught instanceof Error ? caught.message : t('profile.saveFailed');
	} finally {
		editSaving.value = false;
	}
}

function onFollowChanged(following: boolean): void {
	if (account.value !== null) {
		account.value.followers_count = Math.max(0, (account.value.followers_count ?? 0) + (following ? 1 : -1));
	}
}

const stopCreated = onPostCreated((post) => {
	if (isRemoteProfile.value) {
		return;
	}
	const author = post.object_author_id ?? post.author_id;
	if (author !== handle.value || posts.value.some((existing) => existing.id === post.id)) {
		return;
	}

	posts.value = [post, ...posts.value].sort((left, right) => (
		new Date(right.published_at).getTime() - new Date(left.published_at).getTime()
	));
});

const stopUpdated = onPostUpdated((post) => {
	posts.value = posts.value.map((item) => {
		if (item.id !== post.id) {
			return item;
		}

		return { ...item, ...post, interaction: item.interaction ?? post.interaction };
	});
});

const stopDeleted = onPostDeleted((postId) => {
	posts.value = posts.value.filter((post) => post.id !== postId && post.object_id !== postId);
});

onUnmounted(() => {
	generation += 1;
	stopCreated();
	stopUpdated();
	stopDeleted();
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="t('profile.title')"
			:subtitle="handleText"
			back-href="#/"
			:back-label="t('nav.home')"
		>
			<template #actions>
				<button type="button" class="a-icon-btn" :aria-label="t('profile.shareAria')" :title="t('profile.shareTitle')" @click="share">
					<AppIcon name="share" :size="19" />
				</button>
			</template>
		</PageHeader>

		<TimelineSkeleton v-if="loading && account === null" :count="2" />

		<div v-else-if="error !== ''" class="a-error-panel">
			<AppIcon name="info" :size="22" />
			<div>
				<strong>{{ t(isRemoteProfile ? 'profile.remoteUnavailable' : 'profile.notFound') }}</strong>
				<p>{{ error }}</p>
			</div>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="loadProfile">{{ t('common.tryAgain') }}</button>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/explore">{{ t('profile.backExplore') }}</a>
		</div>

		<template v-else-if="account">
			<section class="a-profile">
				<div class="a-profile__cover" :style="coverStyle" aria-hidden="true">
					<span class="a-profile__cover-grid" />
				</div>
				<div class="a-profile__body">
					<div class="a-profile__top">
						<Avatar
							:id="handle"
							:name="displayName"
							:src="account.avatar"
							:size="94"
							ring
							class="a-profile__avatar"
						/>
						<div class="a-profile__actions">
							<template v-if="isSelf">
								<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="openEdit">
									<AppIcon name="settings" :size="15" /> {{ t('profile.edit') }}
								</button>
								<button type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
									<AppIcon name="pen" :size="15" /> {{ t('drafts.writePost') }}
								</button>
							</template>

							<template v-else-if="isRemoteProfile">
								<button
									v-if="canRemoteFollow || remoteRelationship !== null || atprotoFollow !== null"
									type="button"
									class="a-btn a-btn--primary a-btn--small"
									:class="{ 'a-btn--ghost': remoteRelationship?.state === 'accepted' || atprotoFollow?.state === 'active' }"
									:disabled="remoteFollowState === 'sending'"
									@click="toggleRemoteFollow"
								>
									<AppIcon
										:name="atprotoFollow?.state === 'active' || remoteRelationship?.state === 'accepted' ? 'check' : atprotoFollow?.state === 'pending' || remoteRelationship !== null ? 'clock' : 'plus'"
										:size="15"
									/>
									{{ remoteFollowLabel }}
								</button>
								<a
									v-if="externalProfileUrl !== ''"
									class="a-btn a-btn--ghost a-btn--small"
									:href="externalProfileUrl"
									target="_blank"
									rel="noopener noreferrer"
								>
									<AppIcon name="external" :size="15" />
									{{ t(remoteKind === 'bluesky' ? 'profile.openBsky' : 'card.openOriginal') }}
								</a>
							</template>

							<FollowButton v-else :account="account" @changed="onFollowChanged" />

							<button
								v-if="canModerate"
								type="button"
								class="a-icon-btn"
								:class="{ 'a-icon-btn--danger': isMuted }"
								:aria-label="t(isMuted ? 'profile.unmute' : 'profile.mute')"
								:title="t(isMuted ? 'profile.unmuteTitle' : 'profile.muteTitle')"
								:disabled="moderationBusy !== ''"
								@click="toggleMute"
							>
								<AppIcon name="bellOff" :size="17" />
							</button>
							<button
								v-if="canModerate"
								type="button"
								class="a-icon-btn"
								:class="{ 'a-icon-btn--danger': isBlocked }"
								:aria-label="t(isBlocked ? 'profile.unblock' : 'profile.block')"
								:title="t(isBlocked ? 'profile.unblockTitle' : 'profile.blockTitle')"
								:disabled="moderationBusy !== ''"
								@click="toggleBlock"
							>
								<AppIcon name="ban" :size="17" />
							</button>
							<button
								v-if="canReport"
								type="button"
								class="a-icon-btn"
								:aria-label="t('profile.report')"
								:title="t('profile.report')"
								@click="openReport"
							>
								<AppIcon name="flag" :size="17" />
							</button>
						</div>
					</div>

					<div class="a-profile__identity">
						<h1>{{ displayName }}</h1>
						<p v-if="showHandle" class="a-profile__handle">{{ handleText }}</p>
						<p v-if="isRemoteProfile" class="a-profile__network">
							<AppIcon :name="networkIcon" :size="13" />
							{{ networkLabel }}
							<span v-if="remoteKind === 'activitypub'">· ActivityPub</span>
							<span v-else-if="remoteKind === 'bluesky'">· AT Protocol</span>
						</p>
						<p v-else-if="relationship?.followed_by" class="a-profile__follows-you">
							<AppIcon name="users" :size="14" /> {{ t('profile.followsYou') }}
						</p>
					</div>

					<p v-if="isRemoteProfile && account.bio" class="a-profile__bio">{{ account.bio }}</p>

					<div v-if="!isRemoteProfile && featuredTags.length > 0" class="a-profile__featured">
						<a
							v-for="tag in featuredTags"
							:key="tag.tag"
							:href="`#/tag/${encodeURIComponent(tag.tag)}`"
							class="a-profile__featured-tag"
						>
							#{{ tag.tag }}
						</a>
					</div>

					<p class="a-profile__note">
						<AppIcon name="globe" :size="15" />
						<template v-if="isRemoteProfile">
							{{ t('profile.onNetwork', { network: networkLabel }) }}
						</template>
						<template v-else>
							{{ t('profile.onInstance', { instance: appState.instance?.name ?? 'Aloha Social' }) }}
						</template>
					</p>

					<dl class="a-profile__stats">
						<div
							class="a-profile__stat--button"
							role="button"
							tabindex="0"
							:aria-label="t('profile.showFollowing', { name: displayName })"
							@click="openConnections('following')"
							@keydown.enter.prevent="openConnections('following')"
							@keydown.space.prevent="openConnections('following')"
						>
							<dt>{{ formatCount(account.following_count) }}</dt>
							<dd>{{ t('profile.statFollowing') }}</dd>
						</div>
						<div
							class="a-profile__stat--button"
							role="button"
							tabindex="0"
							:aria-label="t('profile.showFollowers', { name: displayName })"
							@click="openConnections('followers')"
							@keydown.enter.prevent="openConnections('followers')"
							@keydown.space.prevent="openConnections('followers')"
						>
							<dt>{{ formatCount(account.followers_count) }}</dt>
							<dd>{{ t('profile.statFollowers') }}</dd>
						</div>
						<div v-if="isRemoteProfile && account.posts_count !== null && account.posts_count !== undefined">
							<dt>{{ formatCount(account.posts_count) }}</dt>
							<dd>{{ t('profile.statPosts') }}</dd>
						</div>
						<div v-else>
							<dt>{{ posts.length }}{{ hasMore ? '+' : '' }}</dt>
							<dd>{{ t('profile.statPostsLoaded') }}</dd>
						</div>
					</dl>

					<p v-if="isRemoteProfile && joinedLabel !== ''" class="a-profile__joined">
						<AppIcon name="calendar" :size="14" />
						{{ t('profile.joined', { time: joinedLabel }) }}
					</p>
				</div>
			</section>

			<div class="a-tabs a-profile__tabs" role="tablist" :aria-label="t('profile.tabsAria')">
				<button
					type="button"
					class="a-tabs__item"
					:class="{ 'a-tabs__item--active': tab === 'posts' }"
					role="tab"
					:aria-selected="tab === 'posts'"
					@click="tab = 'posts'"
				>
					{{ t('profile.tabPosts') }}
				</button>
				<button
					type="button"
					class="a-tabs__item"
					:class="{ 'a-tabs__item--active': tab === 'media' }"
					role="tab"
					:aria-selected="tab === 'media'"
					@click="tab = 'media'"
				>
					{{ t('profile.tabMedia') }}
				</button>
			</div>

			<TimelineSkeleton v-if="postsLoading && posts.length === 0" :count="2" />

			<div v-else-if="postsError !== '' && posts.length === 0" class="a-error-panel">
				<AppIcon name="info" :size="22" />
				<div><strong>{{ t('profile.postsUnavailable') }}</strong><p>{{ postsError }}</p></div>
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="loadPosts(true)">{{ t('common.tryAgain') }}</button>
			</div>

			<EmptyState
				v-else-if="loadedOnce && displayPosts.length === 0"
				:icon="tab === 'media' ? 'image' : 'pen'"
				:title="t(tab === 'media' ? 'profile.noImages' : 'profile.noPublicPosts')"
				:text="emptyText"
			>
				<button v-if="isSelf" type="button" class="a-btn a-btn--primary a-btn--small" @click="compose">
					{{ t('drafts.writePost') }}
				</button>
			</EmptyState>

			<ol v-else-if="displayPosts.length > 0" class="a-feed__list">
				<li v-for="post in displayPosts" :key="post.id">
					<PostCard
						:post="post"
						:pinned="pinnedIds.includes(post.id)"
						@pinned="onPinned(post, $event)"
					/>
				</li>
			</ol>

			<button
				v-if="hasMore && displayPosts.length > 0"
				type="button"
				class="a-btn a-btn--ghost a-feed__more"
				:disabled="postsLoading"
				@click="loadPosts(false)"
			>
				{{ postsLoading ? t('common.loading') : t('profile.loadMorePosts') }}
			</button>

			<p v-if="postsError !== '' && posts.length > 0" class="a-feed__inline-error" role="alert">{{ postsError }}</p>
		</template>

		<ReportDialog
			:open="reportOpen"
			target-type="account"
			:target-id="reportTargetId"
			:target-label="handleText"
			@close="reportOpen = false"
		/>
	</div>

	<Teleport to="body">
		<div
			v-if="editing"
			class="a-modal-overlay"
			role="presentation"
			@click.self="closeEdit"
			@keydown.esc="closeEdit"
		>
			<form
				class="a-profile-edit"
				role="dialog"
				aria-modal="true"
				aria-labelledby="a-profile-edit-title"
				@submit.prevent="saveProfile"
			>
				<header class="a-profile-edit__head">
					<div>
						<p class="a-profile-edit__eyebrow">{{ t('profile.editEyebrow') }}</p>
						<h2 id="a-profile-edit-title">{{ t('profile.editTitle') }}</h2>
					</div>
					<button type="button" class="a-icon-btn" :aria-label="t('profile.closeEditor')" @click="closeEdit">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<label class="a-profile-edit__label" for="a-profile-display-name">{{ t('profile.displayNameLabel') }}</label>
				<input
					id="a-profile-display-name"
					v-model="editName"
					type="text"
					maxlength="80"
					autocomplete="name"
					required
				/>
				<p class="a-profile-edit__hint">{{ t('profile.nameHint', { handle: handleText }) }}</p>

				<label class="a-profile-edit__label" for="a-profile-featured-tags">{{ t('profile.tagsLabel') }}</label>
				<input
					id="a-profile-featured-tags"
					v-model="editTags"
					type="text"
					maxlength="600"
					:placeholder="t('profile.tagsPlaceholder')"
				/>
				<p class="a-profile-edit__hint">{{ t('profile.tagsHint') }}</p>

				<p v-if="editError" class="a-form-error" role="alert">{{ editError }}</p>

				<footer class="a-profile-edit__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="editSaving" @click="closeEdit">{{ t('common.cancel') }}</button>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="editSaving">
						<AppIcon v-if="editSaving" name="refresh" :size="15" class="a-spin" />
						{{ editSaving ? t('common.saving') : t('common.save') }}
					</button>
				</footer>
			</form>
		</div>
	</Teleport>

	<Teleport to="body">
		<div
			v-if="connectionType !== null"
			class="a-modal-overlay"
			role="presentation"
			@click.self="closeConnections"
			@keydown.esc="closeConnections"
		>
			<section
				class="a-connections"
				role="dialog"
				aria-modal="true"
				aria-labelledby="a-connections-title"
			>
				<header class="a-connections__head">
					<div>
						<p class="a-profile-edit__eyebrow">{{ account?.handle ?? handleText }}</p>
						<h2 id="a-connections-title">{{ connectionTitle }}</h2>
					</div>
					<button type="button" class="a-icon-btn" :aria-label="t('profile.closeList')" @click="closeConnections">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p class="a-connections__meta">
					<AppIcon :name="connectionNetwork === 'bluesky' && !connectionLocal ? 'sparkles' : 'globe'" :size="14" />
					{{ t(connectionLocal ? 'profile.connLocal' : connectionNetwork === 'bluesky' ? 'profile.connBsky' : 'profile.connFed') }}
					<span>· {{ t('profile.connLoaded', { count: connectionProfiles.length }) }}</span>
				</p>

				<p v-if="connectionError !== ''" class="a-form-error" role="alert">{{ connectionError }}</p>

				<ul v-if="connectionProfiles.length > 0" class="a-connections__list">
					<li v-for="profile in connectionProfiles" :key="profile.id">
						<a :href="connectionProfileHref(profile)" class="a-connections__item" @click="closeConnections">
							<Avatar
								:id="profile.handle"
								:name="profile.display_name"
								:src="profile.avatar"
								:size="42"
							/>
							<span class="a-connections__body">
								<span class="a-connections__name">
									<strong>{{ profile.display_name }}</strong>
									<span v-if="profile.display_name !== profile.handle">{{ profile.handle }}</span>
								</span>
								<span v-if="profile.bio" class="a-connections__bio">{{ truncate(profile.bio, 170) }}</span>
								<span class="a-connections__stats">
									<span>{{ t('profile.connFollowers', { count: formatCount(profile.followers_count) }) }}</span>
									<span>{{ t('profile.connFollowing', { count: formatCount(profile.following_count) }) }}</span>
									<span v-if="profile.posts_count !== null && profile.posts_count !== undefined">
										{{ t('profile.connPosts', { count: formatCount(profile.posts_count) }) }}
									</span>
								</span>
							</span>
							<AppIcon name="external" :size="15" class="a-connections__arrow" />
						</a>
					</li>
				</ul>

				<EmptyState
					v-else-if="!connectionLoading && connectionError === ''"
					icon="users"
					:title="t('profile.noProfiles')"
					:text="connectionEmptyText"
				/>

				<div class="a-connections__footer">
					<button
						v-if="connectionCursor !== null"
						type="button"
						class="a-btn a-btn--ghost a-btn--small"
						:disabled="connectionLoading"
						@click="loadConnections(false)"
					>
						<AppIcon v-if="connectionLoading" name="refresh" :size="15" class="a-spin" />
						{{ connectionLoading ? t('common.loading') : t('common.loadMore') }}
					</button>
					<span v-else-if="connectionProfiles.length > 0" class="a-connections__end">
						End of the publicly visible list
					</span>
					<span v-if="connectionLoading && connectionProfiles.length === 0" class="a-connections__end">
						Loading…
					</span>
				</div>
			</section>
		</div>
	</Teleport>
</template>
