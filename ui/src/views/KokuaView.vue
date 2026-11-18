<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import Avatar from '../components/Avatar.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { displayNameFor, handleFor, resolveAccount } from '../lib/account';
import { getApi } from '../lib/api';
import { relativeTime } from '../lib/format';
import { appState, showToast } from '../lib/state';
import type {
	KokuaCategory,
	KokuaCreatePayload,
	KokuaEntry,
	KokuaKind,
	KokuaPage,
	KokuaStatus,
} from '../lib/types';

type KindFilter = 'all' | KokuaKind;
type CategoryFilter = 'all' | KokuaCategory;
type StateFilter = KokuaStatus;

const categoryLabels: Record<KokuaCategory, string> = {
	everyday: 'Everyday',
	skills: 'Skills',
	company: 'Company',
	goods: 'Goods',
	other: 'Other',
};

const kind = ref<KindFilter>('all');
const category = ref<CategoryFilter>('all');
const state = ref<StateFilter>('open');
const data = ref<KokuaPage | null>(null);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const formOpen = ref(false);
const saving = ref(false);
const busyId = ref('');
const helpingId = ref('');
const helpMessage = ref('');

const form = reactive<KokuaCreatePayload>({
	kind: 'request',
	title: '',
	details: '',
	category: 'everyday',
	place: '',
	needed_by: '',
});

const signedIn = computed(() => appState.session.user !== null);
const entries = computed(() => data.value?.items ?? []);
const counts = computed(() => data.value?.counts ?? { open_requests: 0, open_offers: 0, done: 0 });
const hasMore = computed(() => data.value !== null && data.value.items.length < data.value.total);

function kindLabel(value: KokuaKind): string {
	return value === 'request' ? 'Needs help' : 'Offers help';
}

function categoryLabel(value: KokuaCategory): string {
	return categoryLabels[value] ?? 'Other';
}

function authorName(entry: KokuaEntry): string {
	return displayNameFor(entry.account_id);
}

function authorHandle(entry: KokuaEntry): string {
	return handleFor(entry.account_id);
}

function formatDate(value: string | null): string {
	if (value === null || value === '') {
		return '';
	}

	const date = new Date(`${value}T12:00:00`);
	if (Number.isNaN(date.getTime())) {
		return value;
	}

	return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' }).format(date);
}

function helperSummary(entry: KokuaEntry): string {
	if (entry.help_count === 0) {
		return 'No offers yet';
	}
	if (entry.help_count === 1) {
		return '1 person offered a hand';
	}

	return `${entry.help_count} people offered a hand`;
}

async function resolvePeople(page: KokuaPage): Promise<void> {
	const ids = new Set<string>();
	for (const entry of page.items) {
		ids.add(entry.account_id);
		for (const helper of entry.helpers) {
			ids.add(helper.account_id);
		}
	}

	await Promise.all([...ids].map((id) => resolveAccount(id)));
}

async function load(reset = true): Promise<void> {
	if (reset) {
		loading.value = true;
		error.value = '';
	} else {
		loadingMore.value = true;
	}

	try {
		const page = await getApi().kokua({
			kind: kind.value,
			category: category.value,
			state: state.value,
			limit: 30,
			offset: reset ? 0 : (data.value?.items.length ?? 0),
		});

		if (reset || data.value === null) {
			data.value = page;
		} else {
			data.value = {
				...page,
				items: [...data.value.items, ...page.items],
			};
		}
		await resolvePeople(page);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The Kokua board could not be loaded.';
	} finally {
		loading.value = false;
		loadingMore.value = false;
	}
}

function replaceEntry(entry: KokuaEntry): void {
	if (data.value === null) {
		return;
	}

	data.value = {
		...data.value,
		items: data.value.items.map((item) => item.id === entry.id ? entry : item),
	};
	void resolvePeople({ ...data.value, items: [entry] });
}

function resetForm(): void {
	form.kind = 'request';
	form.title = '';
	form.details = '';
	form.category = 'everyday';
	form.place = '';
	form.needed_by = '';
}

async function create(): Promise<void> {
	if (saving.value || form.title.trim() === '') {
		return;
	}

	saving.value = true;
	try {
		await getApi().createKokua({
			...form,
			title: form.title.trim(),
			details: form.details.trim(),
			place: form.place.trim(),
			needed_by: form.needed_by === '' ? null : form.needed_by,
		});
		resetForm();
		formOpen.value = false;
		showToast('Your Kokua is on the board.', 'success');
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The Kokua could not be posted.', 'error');
	} finally {
		saving.value = false;
	}
}

function startHelp(entry: KokuaEntry): void {
	helpingId.value = entry.id;
	helpMessage.value = '';
}

async function submitHelp(entry: KokuaEntry): Promise<void> {
	if (busyId.value !== '') {
		return;
	}

	busyId.value = entry.id;
	try {
		const updated = await getApi().helpKokua(entry.id, helpMessage.value.trim());
		replaceEntry(updated);
		helpingId.value = '';
		helpMessage.value = '';
		showToast(updated.viewer_helping ? 'Your private note is visible to the author.' : 'Your offer was saved.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Your offer could not be sent.', 'error');
	} finally {
		busyId.value = '';
	}
}

async function withdraw(entry: KokuaEntry): Promise<void> {
	if (busyId.value !== '') {
		return;
	}

	busyId.value = entry.id;
	try {
		const updated = await getApi().withdrawKokuaHelp(entry.id);
		replaceEntry(updated);
		showToast('Your offer was withdrawn.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Your offer could not be withdrawn.', 'error');
	} finally {
		busyId.value = '';
	}
}

async function setStatus(entry: KokuaEntry, status: KokuaStatus): Promise<void> {
	if (busyId.value !== '') {
		return;
	}

	busyId.value = entry.id;
	try {
		await getApi().setKokuaStatus(entry.id, status);
		showToast(status === 'done' ? 'Kokua marked as done.' : 'Kokua reopened.', 'success');
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The Kokua could not be changed.', 'error');
	} finally {
		busyId.value = '';
	}
}

async function remove(entry: KokuaEntry): Promise<void> {
	if (busyId.value !== '' || !window.confirm(`Remove “${entry.title}” from the Kokua board?`)) {
		return;
	}

	busyId.value = entry.id;
	try {
		await getApi().deleteKokua(entry.id);
		showToast('Kokua removed.', 'success');
		await load();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The Kokua could not be removed.', 'error');
	} finally {
		busyId.value = '';
	}
}

watch([kind, category, state], () => {
	void load();
});

onMounted(() => {
	void load();
});
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Kokua"
			subtitle="Help, offered and asked. A board for the practical things neighbours can do for each other."
			back-href="#/"
			back-label="Back to home"
		>
			<template #actions>
				<button
					v-if="signedIn"
					type="button"
					class="a-btn a-btn--primary a-btn--small"
					@click="formOpen = !formOpen"
				>
					<AppIcon name="plus" :size="15" />
					Post a Kokua
				</button>
				<a v-else class="a-btn a-btn--primary a-btn--small" href="/login">Sign in to take part</a>
			</template>
		</PageHeader>

		<section class="a-kokua-intro">
			<div class="a-kokua-intro__copy">
				<span class="a-kokua-intro__mark" aria-hidden="true"><AppIcon name="handHeart" :size="27" /></span>
				<div>
					<p class="a-welcome__eyebrow">Kokua means help</p>
					<h2>Ask for a hand. Offer one. Mark it done.</h2>
					<p>
						This is not a marketplace and it is not a feed. It is a small, public noticeboard for
						everyday help — a lift, a tool, a skill, company, or an extra pair of hands.
					</p>
				</div>
			</div>
			<dl class="a-kokua-counts">
				<div>
					<dt>Open requests</dt>
					<dd>{{ counts.open_requests }}</dd>
				</div>
				<div>
					<dt>Open offers</dt>
					<dd>{{ counts.open_offers }}</dd>
				</div>
				<div>
					<dt>Completed</dt>
					<dd>{{ counts.done }}</dd>
				</div>
			</dl>
		</section>

		<form v-if="formOpen && signedIn" class="a-panel a-kokua-form" @submit.prevent="create">
			<div class="a-kokua-form__head">
				<div>
					<h2>Put something on the board</h2>
					<p>Be specific enough that someone can say yes.</p>
				</div>
				<button type="button" class="a-icon-btn" aria-label="Close form" @click="formOpen = false">
					<AppIcon name="x" :size="19" />
				</button>
			</div>

			<div class="a-kokua-form__grid">
				<label>
					<span>I am…</span>
					<select v-model="form.kind">
						<option value="request">asking for help</option>
						<option value="offer">offering help</option>
					</select>
				</label>
				<label>
					<span>Category</span>
					<select v-model="form.category">
						<option v-for="(label, value) in categoryLabels" :key="value" :value="value">{{ label }}</option>
					</select>
				</label>
			</div>

			<label>
				<span>Short title</span>
				<input v-model="form.title" maxlength="100" required placeholder="For example: A lift to the station on Friday" />
			</label>

			<label>
				<span>Details</span>
				<textarea
					v-model="form.details"
					maxlength="1000"
					rows="3"
					placeholder="What do you need or offer, and what would make it easy to say yes?"
				/>
			</label>

			<div class="a-kokua-form__grid">
				<label>
					<span>Place <small>(optional)</small></span>
					<input v-model="form.place" maxlength="80" placeholder="Neighbourhood or meeting point" />
				</label>
				<label>
					<span>Needed by <small>(optional)</small></span>
					<input v-model="form.needed_by" type="date" />
				</label>
			</div>

			<div class="a-kokua-form__actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="formOpen = false">Cancel</button>
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || form.title.trim() === ''">
					{{ saving ? 'Posting…' : 'Post to the board' }}
				</button>
			</div>
		</form>

		<div class="a-kokua-filters">
			<div class="a-chip-row" role="group" aria-label="Kind of Kokua">
				<button
					v-for="option in ([['all', 'Everything'], ['request', 'Needs help'], ['offer', 'Offers help']] as const)"
					:key="option[0]"
					type="button"
					class="a-tag-chip"
					:class="{ 'a-kokua-filter--active': kind === option[0] }"
					@click="kind = option[0]"
				>
					{{ option[1] }}
				</button>
			</div>

			<div class="a-chip-row" role="group" aria-label="Category">
				<button
					v-for="option in ([['all', 'All categories'], ['everyday', 'Everyday'], ['skills', 'Skills'], ['company', 'Company'], ['goods', 'Goods'], ['other', 'Other']] as const)"
					:key="option[0]"
					type="button"
					class="a-tag-chip"
					:class="{ 'a-kokua-filter--active': category === option[0] }"
					@click="category = option[0]"
				>
					{{ option[1] }}
				</button>
			</div>

			<div class="a-chip-row" role="group" aria-label="State">
				<button
					type="button"
					class="a-tag-chip"
					:class="{ 'a-kokua-filter--active': state === 'open' }"
					@click="state = 'open'"
				>
					Open
				</button>
				<button
					type="button"
					class="a-tag-chip"
					:class="{ 'a-kokua-filter--active': state === 'done' }"
					@click="state = 'done'"
				>
					Completed
				</button>
			</div>
		</div>

		<p v-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
			<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="load()">Try again</button>
		</p>

		<p v-else-if="loading && entries.length === 0" class="a-muted">Loading the Kokua board…</p>

		<EmptyState
			v-else-if="entries.length === 0"
			icon="handHeart"
			:title="state === 'done' ? 'No completed Kokua yet' : 'The board is quiet'"
			:text="state === 'done'
				? 'When a request or offer has been resolved, it appears here.'
				: 'Be the first to ask for a hand or offer something you are good at.'"
		>
			<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="formOpen = true">
				Post the first Kokua
			</button>
		</EmptyState>

		<div v-else class="a-kokua-list">
			<article
				v-for="entry in entries"
				:key="entry.id"
				class="a-kokua-card"
				:class="{ 'a-kokua-card--done': entry.status === 'done' }"
			>
				<header class="a-kokua-card__top">
					<span class="a-kokua-card__kind" :class="`a-kokua-card__kind--${entry.kind}`">
						<AppIcon :name="entry.kind === 'request' ? 'handHeart' : 'sparkles'" :size="14" />
						{{ kindLabel(entry.kind) }}
					</span>
					<span class="a-kokua-card__category">{{ categoryLabel(entry.category) }}</span>
					<span v-if="entry.status === 'done'" class="a-kokua-card__done">
						<AppIcon name="check" :size="13" /> Done
					</span>
				</header>

				<h2>{{ entry.title }}</h2>
				<p v-if="entry.details !== ''" class="a-kokua-card__details">{{ entry.details }}</p>

				<dl v-if="entry.place !== '' || entry.needed_by !== null" class="a-kokua-card__meta">
					<div v-if="entry.place !== ''">
						<dt>Place</dt>
						<dd>{{ entry.place }}</dd>
					</div>
					<div v-if="entry.needed_by !== null">
						<dt>Needed by</dt>
						<dd>{{ formatDate(entry.needed_by) }}</dd>
					</div>
				</dl>

				<a :href="`#/u/${encodeURIComponent(entry.account_id)}`" class="a-kokua-card__author">
					<Avatar :id="entry.account_id" :name="authorName(entry)" :size="32" />
					<span>
						<strong>{{ authorName(entry) }}</strong>
						<small>{{ authorHandle(entry) }} · {{ relativeTime(entry.created_at) }}</small>
					</span>
				</a>

				<section class="a-kokua-card__help">
					<p class="a-kokua-card__help-summary">
						<AppIcon name="users" :size="15" />
						{{ helperSummary(entry) }}
					</p>
					<ul v-if="entry.helpers.length > 0">
						<li v-for="helper in entry.helpers.slice(0, 3)" :key="helper.account_id">
							<strong>{{ displayNameFor(helper.account_id) }}</strong>
							<span v-if="helper.message !== ''">{{ helper.message }}</span>
							<span v-else class="a-muted">Can help.</span>
						</li>
					</ul>
				</section>

				<footer class="a-kokua-card__actions">
					<template v-if="!signedIn">
						<a class="a-btn a-btn--ghost a-btn--small" href="/login">Sign in to help</a>
					</template>

					<template v-else-if="entry.is_owner">
						<button
							v-if="entry.status === 'open'"
							type="button"
							class="a-btn a-btn--primary a-btn--small"
							:disabled="busyId === entry.id"
							@click="setStatus(entry, 'done')"
						>
							<AppIcon name="check" :size="15" /> Mark as done
						</button>
						<button
							v-else
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busyId === entry.id"
							@click="setStatus(entry, 'open')"
						>
							<AppIcon name="refresh" :size="15" /> Reopen
						</button>
						<button
							type="button"
							class="a-btn a-btn--ghost a-btn--small a-kokua-card__delete"
							:disabled="busyId === entry.id"
							@click="remove(entry)"
						>
							<AppIcon name="trash" :size="15" /> Remove
						</button>
					</template>

					<template v-else-if="entry.status === 'open'">
						<button
							v-if="entry.viewer_helping"
							type="button"
							class="a-btn a-btn--ghost a-btn--small"
							:disabled="busyId === entry.id"
							@click="withdraw(entry)"
						>
							<AppIcon name="x" :size="15" /> Withdraw offer
						</button>
						<button
							v-else
							type="button"
							class="a-btn a-btn--primary a-btn--small"
							:disabled="busyId === entry.id || helpingId === entry.id"
							@click="startHelp(entry)"
						>
							<AppIcon name="heart" :size="15" /> I can help
						</button>
					</template>

					<span v-else class="a-kokua-card__closed">This Kokua is complete.</span>
				</footer>

				<form
					v-if="helpingId === entry.id"
					class="a-kokua-card__reply"
					@submit.prevent="submitHelp(entry)"
				>
					<label>
						<span>Add a private note for {{ authorName(entry) }} <small>(optional — only the author can read it)</small></span>
						<textarea
							v-model="helpMessage"
							maxlength="300"
							rows="2"
							placeholder="How can you help, and when?"
						/>
					</label>
					<div>
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="helpingId = ''">Cancel</button>
						<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="busyId === entry.id">
							{{ busyId === entry.id ? 'Sending…' : 'Send offer' }}
						</button>
					</div>
				</form>
			</article>
		</div>

		<div v-if="hasMore" class="a-feed__more">
			<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="loadingMore" @click="load(false)">
				{{ loadingMore ? 'Loading…' : 'Load more Kokua' }}
			</button>
		</div>
	</div>
</template>

<style scoped>
.a-kokua-intro {
	display: grid;
	gap: 20px;
	margin-bottom: 18px;
	padding: 22px;
	border: 1px solid var(--a-border);
	border-radius: 20px;
	background:
		radial-gradient(circle at 92% 8%, var(--a-mint-soft), transparent 34%),
		var(--a-surface);
}

.a-kokua-intro__copy {
	display: flex;
	gap: 15px;
	align-items: flex-start;
}

.a-kokua-intro__mark {
	display: grid;
	place-items: center;
	flex: 0 0 auto;
	width: 50px;
	height: 50px;
	border-radius: 16px;
	color: var(--a-mint);
	background: var(--a-mint-soft);
}

.a-kokua-intro h2 {
	margin: 2px 0 6px;
	font-size: clamp(20px, 3vw, 27px);
	line-height: 1.2;
}

.a-kokua-intro p {
	max-width: 62ch;
	margin: 0;
	color: var(--a-muted);
}

.a-kokua-counts {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 8px;
	margin: 0;
}

.a-kokua-counts div {
	padding: 11px 13px;
	border: 1px solid var(--a-border);
	border-radius: 13px;
	background: var(--a-bg-soft);
}

.a-kokua-counts dt {
	color: var(--a-faint);
	font-size: 11px;
	font-weight: 650;
}

.a-kokua-counts dd {
	margin: 1px 0 0;
	font-size: 22px;
	font-weight: 800;
	line-height: 1.1;
}

.a-kokua-form {
	display: grid;
	gap: 12px;
	margin-bottom: 16px;
	padding: 18px;
}

.a-kokua-form__head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}

.a-kokua-form__head h2 {
	margin: 0;
	font-size: 17px;
}

.a-kokua-form__head p {
	margin: 2px 0 0;
	color: var(--a-muted);
	font-size: 13px;
}

.a-kokua-form label,
.a-kokua-card__reply label {
	display: grid;
	gap: 6px;
	color: var(--a-muted);
	font-size: 12.5px;
}

.a-kokua-form label small,
.a-kokua-card__reply label small {
	color: var(--a-faint);
}

.a-kokua-form input,
.a-kokua-form select,
.a-kokua-form textarea,
.a-kokua-card__reply textarea {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid var(--a-border-strong);
	border-radius: 12px;
	background: var(--a-bg-soft);
	color: var(--a-text);
	font: inherit;
	resize: vertical;
}

.a-kokua-form__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 10px;
}

.a-kokua-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.a-kokua-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 14px;
	align-items: center;
	margin-bottom: 15px;
}

.a-kokua-filter--active {
	border-color: var(--a-primary);
	background: var(--a-primary-soft);
	color: var(--a-text);
}

.a-kokua-list {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

.a-kokua-card {
	display: flex;
	flex-direction: column;
	gap: 11px;
	min-width: 0;
	padding: 17px;
	border: 1px solid var(--a-border);
	border-radius: 17px;
	background: var(--a-surface);
	box-shadow: var(--a-shadow-soft);
}

.a-kokua-card--done {
	opacity: 0.78;
	background: var(--a-surface-raised);
}

.a-kokua-card__top {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 7px;
}

.a-kokua-card__kind,
.a-kokua-card__category,
.a-kokua-card__done {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 999px;
	font-size: 10.5px;
	font-weight: 750;
	letter-spacing: 0.02em;
}

.a-kokua-card__kind--request {
	color: var(--a-primary);
	background: var(--a-primary-soft);
}

.a-kokua-card__kind--offer {
	color: var(--a-mint);
	background: var(--a-mint-soft);
}

.a-kokua-card__category {
	color: var(--a-muted);
	background: var(--a-surface-hover);
}

.a-kokua-card__done {
	margin-left: auto;
	color: var(--a-success);
	background: var(--a-mint-soft);
}

.a-kokua-card h2 {
	margin: 0;
	font-size: 18px;
	line-height: 1.3;
}

.a-kokua-card__details {
	margin: 0;
	color: var(--a-muted);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.a-kokua-card__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 7px 18px;
	margin: 0;
}

.a-kokua-card__meta div {
	display: grid;
	gap: 1px;
}

.a-kokua-card__meta dt {
	color: var(--a-faint);
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
}

.a-kokua-card__meta dd {
	margin: 0;
	font-size: 12.5px;
	font-weight: 650;
}

.a-kokua-card__author {
	display: flex;
	align-items: center;
	gap: 9px;
	padding-top: 2px;
}

.a-kokua-card__author span {
	display: grid;
	min-width: 0;
}

.a-kokua-card__author strong {
	overflow: hidden;
	font-size: 13px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.a-kokua-card__author small {
	color: var(--a-faint);
	font-size: 11px;
}

.a-kokua-card__help {
	padding: 10px 12px;
	border: 1px solid var(--a-border);
	border-radius: 12px;
	background: var(--a-bg-soft);
}

.a-kokua-card__help-summary {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	color: var(--a-muted);
	font-size: 12px;
	font-weight: 650;
}

.a-kokua-card__help ul {
	display: grid;
	gap: 5px;
	margin: 7px 0 0;
	padding: 0;
	list-style: none;
}

.a-kokua-card__help li {
	display: flex;
	flex-wrap: wrap;
	gap: 5px;
	color: var(--a-muted);
	font-size: 12px;
}

.a-kokua-card__help li strong {
	color: var(--a-text);
}

.a-kokua-card__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 7px;
	margin-top: auto;
	padding-top: 2px;
}

.a-kokua-card__delete {
	color: var(--a-danger);
}

.a-kokua-card__closed {
	color: var(--a-faint);
	font-size: 12px;
}

.a-kokua-card__reply {
	display: grid;
	gap: 8px;
	padding-top: 10px;
	border-top: 1px solid var(--a-border);
}

.a-kokua-card__reply > div {
	display: flex;
	justify-content: flex-end;
	gap: 7px;
}

@media (max-width: 900px) {
	.a-kokua-list {
		grid-template-columns: minmax(0, 1fr);
	}
}

@media (max-width: 620px) {
	.a-kokua-intro {
		padding: 17px;
	}

	.a-kokua-intro__copy {
		display: grid;
	}

	.a-kokua-counts,
	.a-kokua-form__grid {
		grid-template-columns: minmax(0, 1fr);
	}

	.a-kokua-filters {
		align-items: flex-start;
		flex-direction: column;
	}
}
</style>
