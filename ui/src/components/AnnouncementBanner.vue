<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import { absoluteTime, relativeTime } from '../lib/format';
import { t } from '../lib/i18n';
import { appState } from '../lib/state';
import type { Announcement } from '../lib/types';

const announcements = ref<Announcement[]>([]);
const dismissed = ref<Set<string>>(new Set());
const guestStorageKey = 'aloha.dismissed-announcements';

// Dismissals are account data once signed in; keying the browser copy by
// handle keeps one account's choices from leaking into the next session on
// a shared browser.
function storageKey(): string {
	const handle = appState.session.user?.handle;
	return handle ? `${guestStorageKey}.${handle}` : guestStorageKey;
}

function readDismissed(): Set<string> {
	try {
		const raw = localStorage.getItem(storageKey());
		const values: unknown = raw === null ? [] : JSON.parse(raw);
		return new Set(Array.isArray(values) ? values.filter((value): value is string => typeof value === 'string') : []);
	} catch {
		return new Set();
	}
}

function persistLocal(): void {
	try {
		localStorage.setItem(storageKey(), JSON.stringify([...dismissed.value]));
	} catch {
		// The banner stays hidden for this page.
	}
}

function dismiss(id: string): void {
	dismissed.value = new Set([...dismissed.value, id]);
	persistLocal();

	// Signed-in users keep dismissals on the account so they follow across
	// browsers; guests only have this browser's localStorage.
	if (appState.session.user !== null) {
		void getApi().updatePreferences({ dismissed_announcements: [...dismissed.value] }).catch(() => {
			// The local copy already hides it; the next save retries.
		});
	}
}

onMounted(async () => {
	dismissed.value = readDismissed();
	try {
		announcements.value = await getApi().announcements();
	} catch {
		announcements.value = [];
	}

	if (appState.session.user !== null) {
		try {
			const preferences = await getApi().preferences();
			dismissed.value = new Set([...dismissed.value, ...preferences.dismissed_announcements]);
		} catch {
			// Local dismissals still apply.
		}
	}
});
</script>

<template>
	<section v-if="announcements.some((entry) => !dismissed.has(entry.id))" class="a-announcements" :aria-label="t('announce.aria')">
		<article
			v-for="entry in announcements.filter((item) => !dismissed.has(item.id))"
			:key="entry.id"
			class="a-announcement"
		>
			<span class="a-announcement__icon" aria-hidden="true">
				<AppIcon name="bell" :size="18" />
			</span>
			<div class="a-announcement__copy">
				<p>{{ entry.content }}</p>
				<small>
					{{ t('announce.source') }} · {{ relativeTime(entry.created_at) }}
					<template v-if="entry.expires_at"> · {{ t('announce.until', { time: absoluteTime(entry.expires_at) }) }}</template>
				</small>
			</div>
			<button
				type="button"
				class="a-icon-btn"
				:aria-label="t('announce.hide')"
				:title="t('common.hide')"
				@click="dismiss(entry.id)"
			>
				<AppIcon name="x" :size="16" />
			</button>
		</article>
	</section>
</template>
