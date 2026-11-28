<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { t } from '../lib/i18n';
import { navigate, route } from '../lib/router';
import { appState, showToast } from '../lib/state';
import type { StarterPack, StarterPackMember } from '../lib/types';

const mine = ref<StarterPack[]>([]);
const discover = ref<StarterPack[]>([]);
const selected = ref<StarterPack | null>(null);
const loading = ref(false);
const loadingPack = ref(false);
const error = ref('');
const creating = ref(false);
const title = ref('');
const description = ref('');
const saving = ref(false);
const deleting = ref(false);
const memberQuery = ref('');
const adding = ref(false);
const removing = ref('');
const followAllRunning = ref(false);
const followed = ref<Record<string, boolean>>({});

const signedIn = computed(() => appState.session.user !== null);
const selectedId = computed(() => (route.value.name === 'starter-pack' ? route.value.id : ''));
const isOwner = computed(() => (
	selected.value !== null
	&& selected.value.owner_id === (appState.session.user?.id ?? '')
));
const members = computed(() => selected.value?.members ?? []);

function mergePack(pack: StarterPack): void {
	const replace = (list: StarterPack[]): StarterPack[] => (
		list.some((entry) => entry.id === pack.id)
			? list.map((entry) => (entry.id === pack.id ? pack : entry))
			: list
	);
	mine.value = replace(mine.value);
	discover.value = replace(discover.value);
	if (selected.value?.id === pack.id) {
		selected.value = pack;
	}
}

async function load(): Promise<void> {
	if (!signedIn.value) {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		const data = await getApi().starterPacks();
		mine.value = data.mine;
		discover.value = data.discover;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('pack.loadFailed');
	} finally {
		loading.value = false;
	}
}

async function loadSelected(): Promise<void> {
	if (!signedIn.value || selectedId.value === '') {
		selected.value = null;
		return;
	}

	loadingPack.value = true;
	error.value = '';
	try {
		selected.value = await getApi().starterPack(selectedId.value);
	} catch (caught) {
		selected.value = null;
		error.value = caught instanceof Error ? caught.message : t('pack.loadOneFailed');
	} finally {
		loadingPack.value = false;
	}
}

async function create(): Promise<void> {
	if (saving.value || title.value.trim() === '') {
		return;
	}

	saving.value = true;
	try {
		const pack = await getApi().createStarterPack(title.value.trim(), description.value.trim());
		mine.value = [pack, ...mine.value];
		title.value = '';
		description.value = '';
		creating.value = false;
		showToast(t('pack.created'), 'success');
		navigate(`/starter-packs/${encodeURIComponent(pack.id)}`);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('pack.createFailed'), 'error');
	} finally {
		saving.value = false;
	}
}

async function removePack(): Promise<void> {
	const pack = selected.value;
	if (pack === null || deleting.value || !window.confirm(t('pack.deleteConfirm', { title: pack.title }))) {
		return;
	}

	deleting.value = true;
	try {
		await getApi().deleteStarterPack(pack.id);
		mine.value = mine.value.filter((entry) => entry.id !== pack.id);
		discover.value = discover.value.filter((entry) => entry.id !== pack.id);
		selected.value = null;
		showToast(t('pack.deleted'), 'success');
		navigate('/starter-packs');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('pack.deleteFailed'), 'error');
	} finally {
		deleting.value = false;
	}
}

async function addMember(): Promise<void> {
	const pack = selected.value;
	if (pack === null || adding.value || memberQuery.value.trim() === '') {
		return;
	}

	adding.value = true;
	try {
		const updated = await getApi().addStarterPackMember(pack.id, memberQuery.value.trim());
		mergePack(updated);
		memberQuery.value = '';
		showToast(t('pack.added'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('pack.addFailed'), 'error');
	} finally {
		adding.value = false;
	}
}

async function removeMember(member: StarterPackMember): Promise<void> {
	const pack = selected.value;
	if (pack === null || removing.value !== '') {
		return;
	}

	removing.value = member.id;
	try {
		mergePack(await getApi().removeStarterPackMember(pack.id, member.id));
		showToast(t('pack.removed'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('pack.removeFailed'), 'error');
	} finally {
		removing.value = '';
	}
}

async function followOne(member: StarterPackMember): Promise<void> {
	if (member.network === 'local') {
		await getApi().followAccount(member.member_id);
		return;
	}
	if (member.network === 'bluesky') {
		await getApi().followAtproto(member.member_id);
		return;
	}
	if (member.inbox_url === null || member.inbox_url === '') {
		throw new Error(t('pack.inboxUnknown', { handle: member.handle }));
	}
	await getApi().followRemote(member.member_id, member.inbox_url);
}

async function follow(member: StarterPackMember): Promise<void> {
	if (followed.value[member.id] === true) {
		return;
	}

	try {
		await followOne(member);
		followed.value = { ...followed.value, [member.id]: true };
		showToast(t('pack.followedYou', { name: memberName(member) }), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('pack.followFailed'), 'error');
	}
}

async function followAll(): Promise<void> {
	const pack = selected.value;
	if (pack === null || followAllRunning.value) {
		return;
	}

	followAllRunning.value = true;
	let succeeded = 0;
	let failed = 0;
	const next = { ...followed.value };
	for (const member of pack.members) {
		if (next[member.id] === true) {
			continue;
		}
		try {
			await followOne(member);
			next[member.id] = true;
			succeeded += 1;
		} catch {
			failed += 1;
		}
	}
	followed.value = next;
	followAllRunning.value = false;
	showToast(
		t(
			failed === 0
				? (succeeded === 1 ? 'pack.followedAllOne' : 'pack.followedAll')
				: (succeeded === 1 ? 'pack.followedAllPartialOne' : 'pack.followedAllPartial'),
			{ count: succeeded, failed },
		),
		failed === 0 ? 'success' : 'error',
	);
}

function open(pack: StarterPack): void {
	navigate(`/starter-packs/${encodeURIComponent(pack.id)}`);
}

function memberName(member: StarterPackMember): string {
	const name = member.display_name?.trim() ?? '';
	return name !== '' ? name : (member.handle !== '' ? member.handle : member.member_id);
}

function memberHref(member: StarterPackMember): string {
	const target = member.network === 'local'
		? member.member_id
		: member.handle.replace(/^@/, '');
	return `#/u/${encodeURIComponent(target !== '' ? target : member.member_id)}`;
}

function networkLabel(member: StarterPackMember): string {
	switch (member.network) {
		case 'local':
			return t('pack.thisInstance');
		case 'bluesky':
			return 'Bluesky';
		default:
			return 'Fediverse';
	}
}

watch(selectedId, () => {
	void loadSelected();
}, { immediate: true });

onMounted(load);
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="t('pack.title')"
			:subtitle="t('pack.subtitle')"
			back-href="#/"
			:back-label="t('common.backHome')"
		>
			<template #actions>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="creating = !creating">
					<AppIcon name="plus" :size="15" />
					{{ t('pack.new') }}
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>{{ t('common.signInRequired') }}</strong>
				<p>{{ t('pack.signInText') }}</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('common.signIn') }}</a>
		</div>

		<template v-else>
			<form v-if="creating" class="a-panel a-collection-form" @submit.prevent="create">
				<label>
					<span>{{ t('pack.nameLabel') }}</span>
					<input v-model="title" maxlength="80" required :placeholder="t('pack.namePlaceholder')" />
				</label>
				<label>
					<span>{{ t('pack.descLabel') }}</span>
					<textarea v-model="description" maxlength="500" rows="2" :placeholder="t('pack.descPlaceholder')" />
				</label>
				<div class="a-collection-form__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="creating = false">{{ t('common.cancel') }}</button>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || title.trim() === ''">
						{{ saving ? t('common.saving') : t('pack.create') }}
					</button>
				</div>
			</form>

			<p v-if="error !== ''" class="a-feed__inline-error" role="alert">{{ error }}</p>

			<section v-if="selected !== null" class="a-panel a-starter-pack">
				<header class="a-starter-pack__header">
					<div>
						<span class="a-collection-card__badge">
							<AppIcon name="users" :size="13" />
							{{ t(selected.member_count === 1 ? 'pack.accountOne' : 'pack.accounts', { count: selected.member_count }) }}
						</span>
						<h2>{{ selected.title }}</h2>
						<p v-if="selected.description !== ''">{{ selected.description }}</p>
						<small class="a-muted">{{ t('pack.by', { handle: selected.owner_handle }) }}</small>
					</div>
					<div class="a-starter-pack__actions">
						<button
							type="button"
							class="a-btn a-btn--primary a-btn--small"
							:disabled="followAllRunning || selected.members.length === 0"
							@click="followAll"
						>
							<AppIcon name="users" :size="15" />
							{{ followAllRunning ? t('pack.following') : t('pack.followAll') }}
						</button>
						<button
							v-if="isOwner"
							type="button"
							class="a-icon-btn a-icon-btn--danger"
							:aria-label="t('pack.deleteAria')"
							:title="t('pack.deleteTitle')"
							:disabled="deleting"
							@click="removePack"
						>
							<AppIcon name="trash" :size="16" />
						</button>
					</div>
				</header>

				<form v-if="isOwner" class="a-starter-pack__add" @submit.prevent="addMember">
					<input
						v-model="memberQuery"
						maxlength="255"
						:placeholder="t('pack.memberPlaceholder')"
					/>
					<button type="submit" class="a-btn a-btn--ghost a-btn--small" :disabled="adding || memberQuery.trim() === ''">
						{{ adding ? t('pack.adding') : t('pack.addAccount') }}
					</button>
				</form>

				<ul v-if="selected.members.length > 0" class="a-starter-members">
					<li v-for="member in selected.members" :key="member.id" class="a-starter-members__item">
						<Avatar :id="member.member_id" :name="memberName(member)" :size="40" />
						<a :href="memberHref(member)" class="a-starter-members__identity">
							<strong>{{ memberName(member) }}</strong>
							<small>{{ member.handle }} · {{ networkLabel(member) }}</small>
						</a>
						<span v-if="followed[member.id] === true" class="a-follow a-follow--following a-follow--compact">
							<AppIcon name="check" :size="14" />
							<span>{{ t('pack.followingState') }}</span>
						</span>
						<button
							v-else
							type="button"
							class="a-follow a-follow--compact"
							:disabled="followAllRunning"
							@click="follow(member)"
						>
							<AppIcon name="plus" :size="14" />
							<span>{{ t('pack.follow') }}</span>
						</button>
						<button
							v-if="isOwner"
							type="button"
							class="a-icon-btn a-icon-btn--danger"
							:aria-label="t('pack.removeAria')"
							:title="t('pack.removeTitle')"
							:disabled="removing === member.id"
							@click="removeMember(member)"
						>
							<AppIcon name="x" :size="15" />
						</button>
					</li>
				</ul>
				<p v-else class="a-muted">
					{{ t(isOwner ? 'pack.addFirst' : 'pack.emptyMembers') }}
				</p>
			</section>

			<p v-else-if="loadingPack" class="a-muted">{{ t('pack.loading') }}</p>

			<template v-else-if="selectedId === ''">
				<div class="a-section-heading">
					<h2>{{ t('pack.mineTitle') }}</h2>
				</div>
				<p v-if="loading && mine.length === 0" class="a-muted">{{ t('pack.loadingList') }}</p>
				<EmptyState
					v-else-if="mine.length === 0"
					icon="users"
					:title="t('pack.emptyTitle')"
					:text="t('pack.emptyText')"
				>
					<button type="button" class="a-btn a-btn--primary a-btn--small" @click="creating = true">{{ t('pack.createFirst') }}</button>
				</EmptyState>
				<ul v-else class="a-collection-grid">
					<li v-for="pack in mine" :key="pack.id" class="a-collection-card">
						<a :href="`#/starter-packs/${encodeURIComponent(pack.id)}`" class="a-collection-card__link" @click.prevent="open(pack)">
							<span class="a-collection-card__badge">
								<AppIcon name="users" :size="13" />
								{{ t(pack.member_count === 1 ? 'pack.accountOne' : 'pack.accounts', { count: pack.member_count }) }}
							</span>
							<strong>{{ pack.title }}</strong>
							<p v-if="pack.description !== ''">{{ pack.description }}</p>
							<small>{{ t('pack.by', { handle: pack.owner_handle }) }}</small>
						</a>
					</li>
				</ul>

				<div class="a-section-heading">
					<h2>{{ t('pack.discoverTitle') }}</h2>
				</div>
				<p v-if="discover.length === 0" class="a-muted">{{ t('pack.discoverEmpty') }}</p>
				<ul v-else class="a-collection-grid">
					<li v-for="pack in discover" :key="pack.id" class="a-collection-card">
						<a :href="`#/starter-packs/${encodeURIComponent(pack.id)}`" class="a-collection-card__link" @click.prevent="open(pack)">
							<span class="a-collection-card__badge">
								<AppIcon name="users" :size="13" />
								{{ pack.member_count }} {{ pack.member_count === 1 ? 'account' : 'accounts' }}
							</span>
							<strong>{{ pack.title }}</strong>
							<p v-if="pack.description !== ''">{{ pack.description }}</p>
							<small>by @{{ pack.owner_handle }}</small>
						</a>
					</li>
				</ul>
			</template>
		</template>
	</div>
</template>

<style scoped>
.a-starter-pack__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 1rem;
	flex-wrap: wrap;
}

.a-starter-pack__header h2 {
	margin: 0.5rem 0 0.25rem;
}

.a-starter-pack__header p {
	margin: 0 0 0.35rem;
	color: var(--a-muted);
}

.a-starter-pack__actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.a-starter-pack__add {
	display: flex;
	gap: 0.5rem;
	margin: 1rem 0;
	flex-wrap: wrap;
}

.a-starter-pack__add input {
	flex: 1 1 18rem;
	min-width: 12rem;
}

.a-starter-members {
	list-style: none;
	margin: 1rem 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
}

.a-starter-members__item {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.55rem 0;
	border-top: 1px solid var(--a-border);
}

.a-starter-members__identity {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
	text-decoration: none;
	color: inherit;
}

.a-starter-members__identity small {
	color: var(--a-muted);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
