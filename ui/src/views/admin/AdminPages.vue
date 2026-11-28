<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { getAdminApi } from '../../lib/admin';
import type { AdminInstancePage } from '../../lib/admin';
import { relativeTime } from '../../lib/format';
import { t } from '../../lib/i18n';
import { showToast } from '../../lib/state';

const pages = ref<AdminInstancePage[]>([]);
const loading = ref(false);
const error = ref('');
const slug = ref('');
const title = ref('');
const body = ref('');
const published = ref(true);
const editing = ref('');
const saving = ref(false);
const busy = ref('');

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		pages.value = await getAdminApi().instancePages();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('apage.loadFailed');
	} finally {
		loading.value = false;
	}
}

function edit(page: AdminInstancePage): void {
	slug.value = page.slug;
	title.value = page.title;
	body.value = page.body;
	published.value = page.published;
	editing.value = page.slug;
}

function reset(): void {
	slug.value = '';
	title.value = '';
	body.value = '';
	published.value = true;
	editing.value = '';
}

async function save(): Promise<void> {
	if (saving.value || slug.value.trim() === '' || title.value.trim() === '' || body.value.trim() === '') {
		return;
	}

	saving.value = true;
	try {
		const page = await getAdminApi().saveInstancePage(slug.value.trim(), {
			title: title.value.trim(),
			body: body.value,
			published: published.value,
		});
		pages.value = pages.value.some((entry) => entry.slug === page.slug)
			? pages.value.map((entry) => entry.slug === page.slug ? page : entry)
			: [...pages.value, page];
		showToast(t(page.published ? 'apage.publishedToast' : 'apage.draftToast'), 'success');
		reset();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('apage.saveFailed'), 'error');
	} finally {
		saving.value = false;
	}
}

async function remove(page: AdminInstancePage): Promise<void> {
	if (busy.value !== '' || !window.confirm(t('apage.confirmDelete', { title: page.title }))) {
		return;
	}

	busy.value = page.slug;
	try {
		await getAdminApi().deleteInstancePage(page.slug);
		pages.value = pages.value.filter((entry) => entry.slug !== page.slug);
		if (editing.value === page.slug) {
			reset();
		}
		showToast(t('apage.deleted'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('apage.deleteFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

onMounted(load);
</script>

<template>
	<section class="a-admin-section">
		<header class="a-section-head">
			<h2>{{ t('apage.title') }}</h2>
			<span>{{ t('apage.lead') }}</span>
		</header>

		<form class="a-panel a-announcement-form" @submit.prevent="save">
			<label>
				<span>{{ t('apage.address') }}</span>
				<input v-model="slug" :disabled="editing !== ''" pattern="[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?" :placeholder="t('apage.addressPlaceholder')" required />
			</label>
			<label>
				<span>{{ t('apage.pageTitle') }}</span>
				<input v-model="title" maxlength="200" :placeholder="t('apage.titlePlaceholder')" required />
			</label>
			<label>
				<span>{{ t('apage.text') }}</span>
				<textarea v-model="body" rows="10" maxlength="50000" required :placeholder="t('apage.bodyPlaceholder')" />
			</label>
			<label class="a-check">
				<input v-model="published" type="checkbox" />
				<span>{{ t('apage.published', { slug: slug || '…' }) }}</span>
			</label>
			<div class="a-announcement-form__actions">
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || slug.trim() === '' || title.trim() === '' || body.trim() === ''">
					{{ saving ? t('common.saving') : t(editing !== '' ? 'apage.saveChanges' : 'apage.create') }}
				</button>
				<button v-if="editing !== ''" type="button" class="a-btn a-btn--ghost a-btn--small" @click="reset">{{ t('common.cancel') }}</button>
			</div>
		</form>

		<p v-if="error !== ''" class="a-feed__inline-error" role="alert">{{ error }}</p>
		<p v-if="loading && pages.length === 0" class="a-muted">{{ t('apage.loading') }}</p>

		<ul v-else-if="pages.length > 0" class="a-announcement-list">
			<li v-for="entry in pages" :key="entry.slug" class="a-announcement-list__item">
				<div>
					<p>{{ entry.title }} <small class="a-muted">#/pages/{{ entry.slug }}</small></p>
					<small>
						{{ t(entry.published ? 'apage.publishedState' : 'apage.draftState') }}
						· {{ t('apage.updated', { time: relativeTime(entry.updated_at) }) }}
						· {{ t('apage.by', { name: entry.updated_by }) }}
					</small>
				</div>
				<div class="a-announcement-list__actions">
					<a class="a-btn a-btn--ghost a-btn--small" :href="`#/pages/${encodeURIComponent(entry.slug)}`" target="_blank" rel="noopener">{{ t('apage.view') }}</a>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="busy === entry.slug" @click="edit(entry)">
						{{ t('apage.edit') }}
					</button>
					<button type="button" class="a-icon-btn a-icon-btn--danger" :aria-label="t('apage.deleteAria')" :disabled="busy === entry.slug" @click="remove(entry)">
						<AppIcon name="trash" :size="16" />
					</button>
				</div>
			</li>
		</ul>

		<p v-else-if="!loading" class="a-muted">
			{{ t('apage.empty') }}
		</p>
	</section>
</template>
