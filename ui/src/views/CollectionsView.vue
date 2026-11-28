<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { navigate } from '../lib/router';
import { appState, showToast } from '../lib/state';
import type { Collection } from '../lib/types';

const collections = ref<Collection[]>([]);
const loading = ref(false);
const error = ref('');
const creating = ref(false);
const title = ref('');
const description = ref('');
const isPublic = ref(true);
const saving = ref(false);
const deleting = ref('');

const signedIn = computed(() => appState.session.user !== null);

async function load(): Promise<void> {
	if (!signedIn.value) {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		collections.value = await getApi().collections();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The collections could not be loaded.';
	} finally {
		loading.value = false;
	}
}

async function create(): Promise<void> {
	if (saving.value || title.value.trim() === '') {
		return;
	}

	saving.value = true;
	try {
		const collection = await getApi().createCollection(title.value.trim(), description.value.trim(), isPublic.value);
		collections.value = [collection, ...collections.value];
		title.value = '';
		description.value = '';
		isPublic.value = true;
		creating.value = false;
		showToast('Collection created.', 'success');
		navigate(`/c/${encodeURIComponent(collection.id)}`);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The collection could not be created.', 'error');
	} finally {
		saving.value = false;
	}
}

async function remove(collection: Collection): Promise<void> {
	if (deleting.value !== '' || !window.confirm(`Delete the collection “${collection.title}”?`)) {
		return;
	}

	deleting.value = collection.id;
	try {
		await getApi().deleteCollection(collection.id);
		collections.value = collections.value.filter((entry) => entry.id !== collection.id);
		showToast('Collection deleted.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The collection could not be deleted.', 'error');
	} finally {
		deleting.value = '';
	}
}

onMounted(load);
</script>

<template>
	<div class="a-page">
		<PageHeader
			title="Collections"
			subtitle="Organize posts into named, ordered lists — public or just for you."
			back-href="#/"
			back-label="Back to home"
		>
			<template #actions>
				<button v-if="signedIn" type="button" class="a-btn a-btn--primary a-btn--small" @click="creating = !creating">
					<AppIcon name="plus" :size="15" />
					New collection
				</button>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>Sign-in required</strong>
				<p>Collections belong to an account.</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">Sign in</a>
		</div>

		<template v-else>
			<form v-if="creating" class="a-panel a-collection-form" @submit.prevent="create">
				<label>
					<span>Name</span>
					<input v-model="title" maxlength="100" required placeholder="For example: Good thoughts" />
				</label>
				<label>
					<span>Description</span>
					<textarea v-model="description" maxlength="500" rows="2" placeholder="What is this collection about?" />
				</label>
				<label class="a-check">
					<input v-model="isPublic" type="checkbox" />
					<span>Publicly visible</span>
				</label>
				<div class="a-collection-form__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="creating = false">Cancel</button>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || title.trim() === ''">
						{{ saving ? 'Saving…' : 'Create collection' }}
					</button>
				</div>
			</form>

			<p v-if="error !== ''" class="a-feed__inline-error" role="alert">{{ error }}</p>
			<p v-if="loading && collections.length === 0" class="a-muted">Loading collections…</p>

			<EmptyState
				v-else-if="collections.length === 0"
				icon="list"
				title="No collections yet"
				text="Create a collection and add posts to it later from the post menu."
			>
				<button type="button" class="a-btn a-btn--primary a-btn--small" @click="creating = true">Create your first collection</button>
			</EmptyState>

			<ul v-else class="a-collection-grid">
				<li v-for="collection in collections" :key="collection.id" class="a-collection-card">
					<a :href="`#/c/${encodeURIComponent(collection.id)}`" class="a-collection-card__link">
						<span class="a-collection-card__badge">
							<AppIcon :name="collection.is_public ? 'globe' : 'lock'" :size="13" />
							{{ collection.is_public ? 'Public' : 'Private' }}
						</span>
						<strong>{{ collection.title }}</strong>
						<p v-if="collection.description !== ''">{{ collection.description }}</p>
						<small>{{ collection.item_count }} {{ collection.item_count === 1 ? 'Post' : 'Posts' }}</small>
					</a>
					<button
						type="button"
						class="a-icon-btn a-icon-btn--danger"
						aria-label="Delete collection"
						title="Delete"
						:disabled="deleting === collection.id"
						@click="remove(collection)"
					>
						<AppIcon name="trash" :size="16" />
					</button>
				</li>
			</ul>
		</template>
	</div>
</template>
