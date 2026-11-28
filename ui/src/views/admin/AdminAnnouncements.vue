<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import { getApi } from '../../lib/api';
import { relativeTime } from '../../lib/format';
import { t } from '../../lib/i18n';
import { showToast } from '../../lib/state';
import type { Announcement } from '../../lib/types';

const announcements = ref<Announcement[]>([]);
const loading = ref(false);
const error = ref('');
const content = ref('');
const expiresAt = ref('');
const saving = ref(false);
const busy = ref('');

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';
	try {
		announcements.value = await getApi().announcements(true);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : t('aann.loadFailed');
	} finally {
		loading.value = false;
	}
}

async function create(): Promise<void> {
	if (saving.value || content.value.trim() === '') {
		return;
	}

	saving.value = true;
	try {
		const announcement = await getApi().createAnnouncement(
			content.value.trim(),
			expiresAt.value === '' ? null : new Date(expiresAt.value).toISOString(),
		);
		announcements.value = [announcement, ...announcements.value];
		content.value = '';
		expiresAt.value = '';
		showToast(t('aann.live'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('aann.saveFailed'), 'error');
	} finally {
		saving.value = false;
	}
}

async function toggle(announcement: Announcement): Promise<void> {
	if (busy.value !== '') {
		return;
	}

	busy.value = announcement.id;
	try {
		const updated = await getApi().updateAnnouncement(announcement.id, { active: !announcement.active });
		announcements.value = announcements.value.map((entry) => entry.id === updated.id ? updated : entry);
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('aann.changeFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

async function remove(announcement: Announcement): Promise<void> {
	if (busy.value !== '' || !window.confirm(t('aann.confirmDelete'))) {
		return;
	}

	busy.value = announcement.id;
	try {
		await getApi().deleteAnnouncement(announcement.id);
		announcements.value = announcements.value.filter((entry) => entry.id !== announcement.id);
		showToast(t('aann.deleted'), 'success');
	} catch (caught) {
		showToast(caught instanceof Error ? caught.message : t('aann.deleteFailed'), 'error');
	} finally {
		busy.value = '';
	}
}

onMounted(load);
</script>

<template>
	<section class="a-admin-section">
		<header class="a-section-head">
			<h2>{{ t('aann.title') }}</h2>
			<span>{{ t('aann.lead') }}</span>
		</header>

		<form class="a-panel a-announcement-form" @submit.prevent="create">
			<label>
				<span>{{ t('aann.text') }}</span>
				<textarea v-model="content" maxlength="500" rows="3" required :placeholder="t('aann.placeholder')" />
			</label>
			<label>
				<span>{{ t('aann.expiry') }}</span>
				<input v-model="expiresAt" type="datetime-local" />
			</label>
			<div class="a-announcement-form__actions">
				<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || content.trim() === ''">
					{{ saving ? t('common.saving') : t('aann.publish') }}
				</button>
			</div>
		</form>

		<p v-if="error !== ''" class="a-feed__inline-error" role="alert">{{ error }}</p>
		<p v-if="loading && announcements.length === 0" class="a-muted">{{ t('aann.loading') }}</p>

		<ul v-else-if="announcements.length > 0" class="a-announcement-list">
			<li v-for="announcement in announcements" :key="announcement.id" class="a-announcement-list__item">
				<div>
					<p>{{ announcement.content }}</p>
					<small>
						{{ relativeTime(announcement.created_at) }}
						· {{ t(announcement.active ? 'aann.active' : 'aann.hidden') }}
						<template v-if="announcement.expires_at"> · {{ t('aann.until', { date: new Date(announcement.expires_at).toLocaleString() }) }}</template>
					</small>
				</div>
				<div class="a-announcement-list__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="busy === announcement.id" @click="toggle(announcement)">
						{{ t(announcement.active ? 'aann.hide' : 'aann.activate') }}
					</button>
					<button type="button" class="a-icon-btn a-icon-btn--danger" :aria-label="t('aann.deleteAria')" :disabled="busy === announcement.id" @click="remove(announcement)">
						<AppIcon name="trash" :size="16" />
					</button>
				</div>
			</li>
		</ul>

		<p v-else-if="!loading" class="a-muted">{{ t('aann.empty') }}</p>
	</section>
</template>
