<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import type { PostRevisionData } from '../lib/types';

const props = defineProps<{
	open: boolean;
	postId: string;
}>();

const emit = defineEmits<{ close: [] }>();

const data = ref<PostRevisionData | null>(null);
const loading = ref(false);
const error = ref('');

watch(() => props.open, async (open) => {
	if (!open || props.postId === '') {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		data.value = await getApi().postRevisions(props.postId);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The edit history could not be loaded.';
	} finally {
		loading.value = false;
	}
});

function close(): void {
	emit('close');
}
</script>

<template>
	<Teleport to="body">
		<div v-if="open" class="a-modal-overlay" role="presentation" @click.self="close" @keydown.esc="close">
			<article class="a-management-dialog a-revision-dialog" role="dialog" aria-modal="true" aria-labelledby="a-revision-title">
				<header class="a-profile-edit__head">
					<div>
						<p class="a-profile-edit__eyebrow">Post</p>
						<h2 id="a-revision-title">Edit history</h2>
					</div>
					<button type="button" class="a-icon-btn" aria-label="Close dialog" @click="close">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p v-if="loading" class="a-muted">Loading history…</p>
				<p v-else-if="error !== ''" class="a-feed__inline-error" role="alert">{{ error }}</p>

				<template v-else-if="data !== null">
					<section class="a-revision a-revision--current">
						<header>
							<strong>Version {{ data.current_revision }}</strong>
							<span>Current</span>
						</header>
						<p v-if="data.current.content_warning" class="a-revision__warning">
							<AppIcon name="alert" :size="13" />
							{{ data.current.content_warning }}
						</p>
						<p class="a-revision__content">{{ data.current.content }}</p>
					</section>

					<p v-if="data.revisions.length === 0" class="a-muted">This post has not been edited yet.</p>

					<section v-for="revision in [...data.revisions].reverse()" :key="revision.revision" class="a-revision">
						<header>
							<strong>Version {{ revision.revision }}</strong>
							<span>{{ new Date(revision.edited_at).toLocaleString('en-US') }}</span>
						</header>
						<p v-if="revision.content_warning" class="a-revision__warning">
							<AppIcon name="alert" :size="13" />
							{{ revision.content_warning }}
						</p>
						<p class="a-revision__content">{{ revision.content }}</p>
					</section>
				</template>
			</article>
		</div>
	</Teleport>
</template>
