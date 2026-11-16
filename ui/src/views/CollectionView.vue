<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import PageHeader from '../components/PageHeader.vue';
import PostCard from '../components/PostCard.vue';
import { getApi } from '../lib/api';
import { relativeTime } from '../lib/format';
import { route } from '../lib/router';
import { appState, showToast } from '../lib/state';
import type { Collection, TimelineItem } from '../lib/types';

const collection = ref<Collection | null>(null);
const posts = ref<TimelineItem[]>([]);
const loading = ref(false);
const error = ref('');
const editing = ref(false);
const title = ref('');
const description = ref('');
const isPublic = ref(true);
const saving = ref(false);

const id = computed(() => route.value.name === 'collection' ? route.value.id : '');
const signedIn = computed(() => appState.session.user !== null);
const isOwner = computed(() => (
	signedIn.value
	&& collection.value !== null
	&& collection.value.account_id === appState.session.user?.handle
));
const orderedPosts = computed(() => {
	const collectionValue = collection.value;
	if (collectionValue === null || collectionValue.post_ids === undefined) {
		return posts.value;
	}

	const byId = new Map(posts.value.map((post) => [post.id, post]));
	return collectionValue.post_ids
		.map((postId) => byId.get(postId))
		.filter((post): post is TimelineItem => post !== undefined);
});

async function load(): Promise<void> {
	if (id.value === '') {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		const value = await getApi().collection(id.value);
		collection.value = value;
		title.value = value.title;
		description.value = value.description;
		isPublic.value = value.is_public;
		const ids = value.post_ids ?? [];
		posts.value = ids.length === 0 ? [] : Object.values(await getApi().posts(ids));
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The collection could not be loaded.';
	} finally {
		loading.value = false;
	}
}

async function save(): Promise<void> {
	if (saving.value || collection.value === null) {
		return;
	}

	saving.value = true;
	try {
		const updated = await getApi().updateCollection(collection.value.id, {
			title: title.value.trim(),
			description: description.value.trim(),
			is_public: isPublic.value,
		});
		collection.value = updated;
		editing.value = false;
		showToast('Collection updated.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The collection could not be saved.', 'error');
	} finally {
		saving.value = false;
	}
}

async function removePost(postId: string): Promise<void> {
	if (collection.value === null) {
		return;
	}

	try {
		const updated = await getApi().removeCollectionItem(collection.value.id, postId);
		collection.value = updated;
		posts.value = posts.value.filter((post) => post.id !== postId);
		showToast('Post removed.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The post could not be removed.', 'error');
	}
}

onMounted(load);
watch(id, load);
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="collection?.title ?? 'collection'"
			:subtitle="collection?.description || 'A curated list of posts.'"
			back-href="#/collections"
			back-label="All collections"
		>
			<template #actions>
				<button v-if="isOwner" type="button" class="a-btn a-btn--ghost a-btn--small" @click="editing = !editing">
					<AppIcon name="pen" :size="15" />
							Edit

				</button>
			</template>
		</PageHeader>

		<p v-if="collection !== null" class="a-collection-meta">
			<span>
				<AppIcon :name="collection.is_public ? 'globe' : 'lock'" :size="14" />
				{{ collection.is_public ? 'Public' : 'Private' }}
			</span>
			<span>
				<AppIcon name="clock" :size="14" />
				Updated {{ relativeTime(collection.updated_at) }}
			</span>
			<span>{{ collection.item_count }} {{ collection.item_count === 1 ? 'Post' : 'Posts' }}</span>
		</p>

		<form v-if="editing && isOwner" class="a-panel a-collection-form" @submit.prevent="save">
			<label>
				<span>Name</span>
				<input v-model="title" maxlength="100" required />
			</label>
			<label>
				<span>Description</span>
				<textarea v-model="description" maxlength="500" rows="2" />
			</label>
			<label class="a-check">
				<input v-model="isPublic" type="checkbox" />
				<span>Publicly visible</span>
			</label>
			<div class="a-collection-form__actions">
				<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="editing = false">Cancel</button>
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving">
					{{ saving ? 'Saving…' : 'Save' }}
				</button>
			</div>
		</form>

		<p v-if="error !== ''" class="a-error-panel">
			<AppIcon name="alert" :size="20" />
			<span>{{ error }}</span>
		</p>

		<p v-else-if="loading && collection === null" class="a-muted">Loading collection…</p>

		<EmptyState
			v-else-if="collection !== null && orderedPosts.length === 0"
			icon="list"
			title="This collection is still empty"
			:text="isOwner ? 'Open a post and choose “Add to collection” from its menu.' : 'The owner has not added any posts yet.'"
		/>

		<ol v-else-if="orderedPosts.length > 0" class="a-feed__list">
			<li v-for="post in orderedPosts" :key="post.id" class="a-collection-post">
				<PostCard :post="post" />
				<button
					v-if="isOwner"
					type="button"
					class="a-collection-post__remove"
					:aria-label="'Remove from collection'"
					title="Remove from collection"
					@click="removePost(post.id)"
				>
					<AppIcon name="x" :size="14" />
				</button>
			</li>
		</ol>
	</div>
</template>
