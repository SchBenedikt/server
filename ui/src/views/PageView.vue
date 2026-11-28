<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { getApi } from '../lib/api';
import { route } from '../lib/router';
import { relativeTime } from '../lib/format';
import type { InstancePage } from '../lib/types';

const page = ref<InstancePage | null>(null);
const loading = ref(true);
const missing = ref(false);

const slug = computed(() => route.value.name === 'page' ? route.value.slug : '');
const paragraphs = computed(() =>
	(page.value?.body ?? '')
		.split(/\n{2,}/)
		.map((block) => block.trim())
		.filter((block) => block !== ''),
);

async function load(): Promise<void> {
	loading.value = true;
	missing.value = false;
	page.value = null;
	try {
		page.value = await getApi().instancePage(slug.value);
		missing.value = page.value === null;
	} catch {
		missing.value = true;
	} finally {
		loading.value = false;
	}
}

onMounted(load);
watch(slug, load);
</script>

<template>
	<div class="a-page">
		<PageHeader
			:title="page?.title ?? 'Instance page'"
			:subtitle="page ? `Updated ${relativeTime(page.updated_at)}` : 'A page published by the administration.'"
			back-href="#/about"
			back-label="Back to about"
		/>

		<p v-if="loading" class="a-muted">Loading…</p>

		<article v-else-if="page" class="a-page-doc">
			<p v-for="(block, index) in paragraphs" :key="index" class="a-page-doc__block">{{ block }}</p>
		</article>

		<section v-else class="a-rail__card a-page-doc__missing">
			<h3><AppIcon name="info" :size="18" /> Page not found</h3>
			<p>
				This page does not exist or has not been published yet.
				The administration has not configured it — nothing is hidden on purpose.
			</p>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/about">Back to about this instance</a>
		</section>
	</div>
</template>
