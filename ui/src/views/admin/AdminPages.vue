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
		error.value = caught instanceof Error ? caught.message : 'Pages could not be loaded.';
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
		showToast(page.published ? 'Page is published.' : 'Draft saved.', 'success');
		reset();
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The page could not be saved.', 'error');
	} finally {
		saving.value = false;
	}
}

async function remove(page: AdminInstancePage): Promise<void> {
	if (busy.value !== '' || !window.confirm(`Really delete the page "${page.title}"?`)) {
		return;
	}

	busy.value = page.slug;
	try {
		await getAdminApi().deleteInstancePage(page.slug);
		pages.value = pages.value.filter((entry) => entry.slug !== page.slug);
		if (editing.value === page.slug) {
			reset();
		}
		showToast('Page deleted.', 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : 'The page could not be deleted.', 'error');
	} finally {
		busy.value = '';
	}
}

onMounted(load);
</script>

<template>
	<section class="a-admin-section">
		<header class="a-section-head">
			<h2>Instance pages</h2>
			<span>Public rules, terms and notices — plain text, never HTML</span>
		</header>

		<form class="a-panel a-announcement-form" @submit.prevent="save">
			<label>
				<span>Address</span>
				<input v-model="slug" :disabled="editing !== ''" pattern="[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?" placeholder="rules" required />
			</label>
			<label>
				<span>Title</span>
				<input v-model="title" maxlength="200" placeholder="Community rules" required />
			</label>
			<label>
				<span>Text</span>
				<textarea v-model="body" rows="10" maxlength="50000" required placeholder="Write the page as plain text. Blank lines start a new paragraph." />
			</label>
			<label class="a-check">
				<input v-model="published" type="checkbox" />
				<span>Published — visible to everyone at #/pages/{{ slug || '…' }}</span>
			</label>
			<div class="a-announcement-form__actions">
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || slug.trim() === '' || title.trim() === '' || body.trim() === ''">
					{{ saving ? 'Saving…' : editing !== '' ? 'Save changes' : 'Create page' }}
				</button>
				<button v-if="editing !== ''" type="button" class="a-btn a-btn--ghost a-btn--small" @click="reset">Cancel</button>
			</div>
		</form>

		<p v-if="error !== ''" class="a-feed__inline-error">{{ error }}</p>
		<p v-if="loading && pages.length === 0" class="a-muted">Loading pages…</p>

		<ul v-else-if="pages.length > 0" class="a-announcement-list">
			<li v-for="entry in pages" :key="entry.slug" class="a-announcement-list__item">
				<div>
					<p>{{ entry.title }} <small class="a-muted">#/pages/{{ entry.slug }}</small></p>
					<small>
						{{ entry.published ? 'published' : 'draft' }}
						· updated {{ relativeTime(entry.updated_at) }}
						· by {{ entry.updated_by }}
					</small>
				</div>
				<div class="a-announcement-list__actions">
					<a class="a-btn a-btn--ghost a-btn--small" :href="`#/pages/${encodeURIComponent(entry.slug)}`" target="_blank" rel="noopener">View</a>
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="busy === entry.slug" @click="edit(entry)">
						Edit
					</button>
					<button type="button" class="a-icon-btn a-icon-btn--danger" aria-label="Delete page" :disabled="busy === entry.slug" @click="remove(entry)">
						<AppIcon name="trash" :size="16" />
					</button>
				</div>
			</li>
		</ul>

		<p v-else-if="!loading" class="a-muted">
			No pages yet. Create rules, terms or a privacy notice above — readers see them under “Instance pages” on the about page.
		</p>
	</section>
</template>
