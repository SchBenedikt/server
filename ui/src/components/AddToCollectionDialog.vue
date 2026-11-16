<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import { showToast } from '../lib/state';
import type { Collection } from '../lib/types';

const props = defineProps<{
	open: boolean;
	postId: string;
}>();

const emit = defineEmits<{ close: []; added: [collectionId: string] }>();

const collections = ref<Collection[]>([]);
const loading = ref(false);
const error = ref('');
const busy = ref('');
const creating = ref(false);
const title = ref('');
const saving = ref(false);

watch(() => props.open, async (open) => {
	if (!open) {
		creating.value = false;
		title.value = '';
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		collections.value = await getApi().collections();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Die Sammlungen konnten nicht geladen werden.';
	} finally {
		loading.value = false;
	}
});

async function add(collection: Collection): Promise<void> {
	if (busy.value !== '' || props.postId === '') {
		return;
	}

	busy.value = collection.id;
	try {
		await getApi().addCollectionItem(collection.id, props.postId);
		showToast(`Zu „${collection.title}“ hinzugefügt.`, 'success');
		emit('added', collection.id);
		emit('close');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Der Beitrag konnte nicht hinzugefügt werden.', 'error');
	} finally {
		busy.value = '';
	}
}

async function create(): Promise<void> {
	if (saving.value || title.value.trim() === '') {
		return;
	}

	saving.value = true;
	try {
		const collection = await getApi().createCollection(title.value.trim());
		collections.value = [collection, ...collections.value];
		title.value = '';
		creating.value = false;
		await add(collection);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'Die Sammlung konnte nicht angelegt werden.', 'error');
	} finally {
		saving.value = false;
	}
}

function close(): void {
	if (busy.value !== '' || saving.value) {
		return;
	}

	emit('close');
}
</script>

<template>
	<Teleport to="body">
		<div v-if="open" class="a-modal-overlay" role="presentation" @click.self="close" @keydown.esc="close">
			<article class="a-management-dialog a-collection-picker" role="dialog" aria-modal="true" aria-labelledby="a-collection-picker-title">
				<header class="a-profile-edit__head">
					<div>
						<p class="a-profile-edit__eyebrow">Sammlungen</p>
						<h2 id="a-collection-picker-title">Zu Sammlung hinzufügen</h2>
					</div>
					<button type="button" class="a-icon-btn" aria-label="Dialog schließen" @click="close">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p v-if="loading" class="a-muted">Sammlungen werden geladen …</p>
				<p v-else-if="error !== ''" class="a-feed__inline-error">{{ error }}</p>

				<ul v-else-if="collections.length > 0" class="a-collection-picker__list">
					<li v-for="collection in collections" :key="collection.id">
						<button type="button" :disabled="busy !== ''" @click="add(collection)">
							<span>
								<AppIcon :name="collection.is_public ? 'globe' : 'lock'" :size="14" />
								<strong>{{ collection.title }}</strong>
							</span>
							<small>{{ collection.item_count }} {{ collection.item_count === 1 ? 'Beitrag' : 'Beiträge' }}</small>
						</button>
					</li>
				</ul>

				<p v-else-if="!creating" class="a-muted">Du hast noch keine Sammlung.</p>

				<form v-if="creating" class="a-collection-picker__create" @submit.prevent="create">
					<input v-model="title" maxlength="100" required placeholder="Name der neuen Sammlung" />
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || title.trim() === ''">
						{{ saving ? 'Anlegen …' : 'Anlegen & hinzufügen' }}
					</button>
				</form>

				<footer class="a-collection-picker__footer">
					<button v-if="!creating" type="button" class="a-btn a-btn--ghost a-btn--small" @click="creating = true">
						<AppIcon name="plus" :size="15" />
						Neue Sammlung
					</button>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="close">Schließen</button>
				</footer>
			</article>
		</div>
	</Teleport>
</template>
