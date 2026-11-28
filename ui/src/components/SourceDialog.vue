<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import type { PostSource } from '../lib/types';

const props = defineProps<{
	open: boolean;
	postId: string;
}>();

const emit = defineEmits<{ close: [] }>();

const source = ref<PostSource | null>(null);
const loading = ref(false);
const error = ref('');

watch(() => props.open, async (open) => {
	if (!open || props.postId === '') {
		return;
	}

	loading.value = true;
	error.value = '';
	try {
		source.value = await getApi().postSource(props.postId);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The post source could not be loaded.';
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
			<article class="a-management-dialog a-revision-dialog" role="dialog" aria-modal="true" aria-labelledby="a-source-title">
				<header class="a-profile-edit__head">
					<div>
						<p class="a-profile-edit__eyebrow">Post</p>
						<h2 id="a-source-title">Source</h2>
					</div>
					<button type="button" class="a-icon-btn" aria-label="Close dialog" @click="close">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p v-if="loading" class="a-muted">Loading source…</p>
				<p v-else-if="error !== ''" class="a-feed__inline-error" role="alert">{{ error }}</p>

				<template v-else-if="source !== null">
					<p v-if="source.spoiler_text" class="a-revision__warning">
						<AppIcon name="alert" :size="13" />
						{{ source.spoiler_text }}
					</p>
					<pre class="a-revision__source">{{ source.text }}</pre>
				</template>
			</article>
		</div>
	</Teleport>
</template>
