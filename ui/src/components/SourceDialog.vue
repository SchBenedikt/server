<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import { t } from '../lib/i18n';
import type { PostDelivery, PostSource } from '../lib/types';

const props = defineProps<{
	open: boolean;
	postId: string;
}>();

const emit = defineEmits<{ close: [] }>();

const source = ref<PostSource | null>(null);
const delivery = ref<PostDelivery | null>(null);
const loading = ref(false);
const error = ref('');

watch(() => props.open, async (open) => {
	if (!open || props.postId === '') {
		return;
	}

	loading.value = true;
	error.value = '';
	delivery.value = null;
	try {
		const [sourceResult, deliveryResult] = await Promise.all([
			getApi().postSource(props.postId),
			// Delivery is best-effort: an older core or a transient failure
			// must not hide the source itself.
			getApi().postDelivery(props.postId).catch(() => null),
		]);
		source.value = sourceResult;
		delivery.value = deliveryResult;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The post source could not be loaded.';
	} finally {
		loading.value = false;
	}
});

function stateLabel(state: string): string {
	return t(`delivery.${state}`);
}

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
					<button type="button" class="a-icon-btn" :aria-label="t('common.closeDialog')" @click="close">
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

					<section v-if="delivery !== null" class="a-source-delivery">
						<h3 class="a-source-delivery__title">{{ t('delivery.title') }}</h3>
						<p v-if="delivery.total === 0" class="a-muted">{{ t('delivery.none') }}</p>
						<template v-else>
							<ul class="a-source-delivery__counts">
								<li v-if="delivery.delivered > 0">{{ delivery.delivered }} {{ t('delivery.delivered') }}</li>
								<li v-if="delivery.sending > 0">{{ delivery.sending }} {{ t('delivery.sending') }}</li>
								<li v-if="delivery.waiting > 0">{{ delivery.waiting }} {{ t('delivery.waiting') }}</li>
								<li v-if="delivery.failing > 0">{{ delivery.failing }} {{ t('delivery.failing') }}</li>
								<li v-if="delivery.abandoned > 0">{{ delivery.abandoned }} {{ t('delivery.abandoned') }}</li>
							</ul>
							<table v-if="delivery.instances.length > 0" class="a-source-delivery__table">
								<thead>
									<tr>
										<th>{{ t('delivery.host') }}</th>
										<th>{{ t('delivery.state') }}</th>
										<th>{{ t('delivery.tries') }}</th>
										<th>{{ t('delivery.last') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="instance in delivery.instances" :key="instance.host">
										<td>{{ instance.host }}</td>
										<td>{{ stateLabel(instance.state) }}</td>
										<td>{{ instance.tries }}</td>
										<td>{{ instance.last }}</td>
									</tr>
								</tbody>
							</table>
						</template>
					</section>
				</template>
			</article>
		</div>
	</Teleport>
</template>
