<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import type { PostRevisionData } from '../lib/types';
import { t } from '../lib/i18n';
import { absoluteTime } from '../lib/format';

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
		error.value = caught instanceof Error ? caught.message : t('rev.loadFailed');
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
						<p class="a-profile-edit__eyebrow">{{ t('post.title') }}</p>
						<h2 id="a-revision-title">{{ t('rev.title') }}</h2>
					</div>
					<button type="button" class="a-icon-btn" :aria-label="t('common.closeDialog')" @click="close">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p v-if="loading" class="a-muted">{{ t('rev.loading') }}</p>
				<p v-else-if="error !== ''" class="a-feed__inline-error" role="alert">{{ error }}</p>

				<template v-else-if="data !== null">
					<section class="a-revision a-revision--current">
						<header>
							<strong>{{ t('rev.version', { number: data.current_revision }) }}</strong>
							<span>{{ t('rev.current') }}</span>
						</header>
						<p v-if="data.current.content_warning" class="a-revision__warning">
							<AppIcon name="alert" :size="13" />
							{{ data.current.content_warning }}
						</p>
						<p class="a-revision__content">{{ data.current.content }}</p>
					</section>

					<p v-if="data.revisions.length === 0" class="a-muted">{{ t('rev.unedited') }}</p>

					<section v-for="revision in [...data.revisions].reverse()" :key="revision.revision" class="a-revision">
						<header>
							<strong>{{ t('rev.version', { number: revision.revision }) }}</strong>
							<span>{{ absoluteTime(revision.edited_at) }}</span>
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
