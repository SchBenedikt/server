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
import { t } from '../lib/i18n';
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
			:title="page?.title ?? t('page.fallbackTitle')"
			:subtitle="page ? t('page.updated', { time: relativeTime(page.updated_at) }) : t('page.fallbackSubtitle')"
			back-href="#/about"
			:back-label="t('page.backAbout')"
		/>

		<p v-if="loading" class="a-muted">{{ t('page.loading') }}</p>

		<article v-else-if="page" class="a-page-doc">
			<p v-for="(block, index) in paragraphs" :key="index" class="a-page-doc__block">{{ block }}</p>
		</article>

		<section v-else class="a-rail__card a-page-doc__missing">
			<h3><AppIcon name="info" :size="18" /> {{ t('page.notFoundTitle') }}</h3>
			<p>
				{{ t('page.notFoundBody') }}
			</p>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/about">{{ t('page.backAboutInstance') }}</a>
		</section>
	</div>
</template>
