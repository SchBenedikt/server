<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onMounted, ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { getApi } from '../lib/api';
import { relativeTime } from '../lib/format';
import type { Announcement } from '../lib/types';

const announcements = ref<Announcement[]>([]);
const dismissed = ref<Set<string>>(new Set());
const storageKey = 'aloha.dismissed-announcements';

function readDismissed(): Set<string> {
	try {
		const raw = localStorage.getItem(storageKey);
		const values: unknown = raw === null ? [] : JSON.parse(raw);
		return new Set(Array.isArray(values) ? values.filter((value): value is string => typeof value === 'string') : []);
	} catch {
		return new Set();
	}
}

function dismiss(id: string): void {
	dismissed.value = new Set([...dismissed.value, id]);
	try {
		localStorage.setItem(storageKey, JSON.stringify([...dismissed.value]));
	} catch {
		// The banner stays hidden for this page.
	}
}

onMounted(async () => {
	dismissed.value = readDismissed();
	try {
		announcements.value = await getApi().announcements();
	} catch {
		announcements.value = [];
	}
});
</script>

<template>
	<section v-if="announcements.some((entry) => !dismissed.has(entry.id))" class="a-announcements" aria-label="Announcements">
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
					instance-announcement · {{ relativeTime(entry.created_at) }}
					<template v-if="entry.expires_at"> · until {{ new Date(entry.expires_at).toLocaleString() }}</template>
				</small>
			</div>
			<button
				type="button"
				class="a-icon-btn"
				aria-label="Hide announcement"
				title="Hide"
				@click="dismiss(entry.id)"
			>
				<AppIcon name="x" :size="16" />
			</button>
		</article>
	</section>
</template>
