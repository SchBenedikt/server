<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { appState, showToast } from '../lib/state';
import {
	MAX_PRIMARY,
	SIDEBAR_CATALOG,
	primarySidebarIds,
	resetSidebar,
	setPrimarySidebar,
} from '../lib/sidebar';
import type { SidebarItem } from '../lib/sidebar';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const draft = ref<string[]>([]);

const available = computed<SidebarItem[]>(() => SIDEBAR_CATALOG.filter((item) => (
	(item.requiresAuth !== true || appState.session.user !== null)
	&& (item.adminOnly !== true || (appState.session.admin || appState.session.moderator))
)));

const ordered = computed<SidebarItem[]>(() => {
	const map = new Map(available.value.map((item) => [item.id, item]));
	return draft.value
		.map((id) => map.get(id))
		.filter((item): item is SidebarItem => item !== undefined);
});

const remaining = computed<SidebarItem[]>(() => (
	available.value.filter((item) => !draft.value.includes(item.id))
));

watch(() => props.open, (open) => {
	if (open) {
		draft.value = [...primarySidebarIds.value];
	}
});

function isPinned(id: string): boolean {
	return draft.value.includes(id);
}

function toggle(id: string): void {
	if (isPinned(id)) {
		draft.value = draft.value.filter((item) => item !== id);
		return;
	}
	if (draft.value.length >= MAX_PRIMARY) {
		showToast(`The main navigation holds at most ${MAX_PRIMARY} entries.`, 'error');
		return;
	}
	draft.value = [...draft.value, id];
}

function move(id: string, delta: number): void {
	const index = draft.value.indexOf(id);
	const target = index + delta;
	if (index === -1 || target < 0 || target >= draft.value.length) {
		return;
	}

	const next = [...draft.value];
	[next[index], next[target]] = [next[target], next[index]];
	draft.value = next;
}

function save(): void {
	setPrimarySidebar(draft.value);
	showToast('Sidebar layout saved.', 'success');
	emit('close');
}

function reset(): void {
	resetSidebar();
	draft.value = [...primarySidebarIds.value];
	showToast('Sidebar reset to the default.', 'success');
}

function close(): void {
	emit('close');
}
</script>

<template>
	<Teleport to="body">
		<div v-if="open" class="a-modal-overlay" role="presentation" @click.self="close" @keydown.esc="close">
			<article class="a-management-dialog a-sidebar-settings" role="dialog" aria-modal="true" aria-labelledby="a-sidebar-settings-title">
				<header class="a-profile-edit__head">
					<div>
						<p class="a-profile-edit__eyebrow">Customize</p>
						<h2 id="a-sidebar-settings-title">Sidebar</h2>
					</div>
					<button type="button" class="a-icon-btn" aria-label="Close dialog" @click="close">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p class="a-muted a-sidebar-settings__hint">
					Choose up to {{ MAX_PRIMARY }} entries for the main navigation. Everything else stays reachable under “More”.
				</p>

				<section class="a-sidebar-settings__section">
					<h3>Main navigation</h3>
					<ul v-if="ordered.length > 0" class="a-sidebar-settings__list">
						<li v-for="(item, index) in ordered" :key="item.id">
							<span class="a-sidebar-settings__label">
								<AppIcon :name="item.icon" :size="17" />
								{{ item.label }}
							</span>
							<span class="a-sidebar-settings__actions">
								<button type="button" class="a-icon-btn" :disabled="index === 0" :aria-label="`Move ${item.label} up`" @click="move(item.id, -1)">
									<AppIcon name="chevronDown" :size="16" class="a-sidebar-settings__up" />
								</button>
								<button type="button" class="a-icon-btn" :disabled="index === ordered.length - 1" :aria-label="`Move ${item.label} down`" @click="move(item.id, 1)">
									<AppIcon name="chevronDown" :size="16" />
								</button>
								<button type="button" class="a-icon-btn a-icon-btn--danger" :aria-label="`Remove ${item.label} from the main navigation`" @click="toggle(item.id)">
									<AppIcon name="x" :size="16" />
								</button>
							</span>
						</li>
					</ul>
					<p v-else class="a-muted">No entries pinned. Pick from the list below.</p>
				</section>

				<section v-if="remaining.length > 0" class="a-sidebar-settings__section">
					<h3>More entries</h3>
					<ul class="a-sidebar-settings__list">
						<li v-for="item in remaining" :key="item.id">
							<span class="a-sidebar-settings__label">
								<AppIcon :name="item.icon" :size="17" />
								{{ item.label }}
							</span>
							<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="draft.length >= MAX_PRIMARY" @click="toggle(item.id)">
								<AppIcon name="plus" :size="14" />
								Pin
							</button>
						</li>
					</ul>
				</section>

				<footer class="a-sidebar-settings__footer">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="reset">
						<AppIcon name="refresh" :size="15" />
						Reset
					</button>
					<div class="a-sidebar-settings__footer-actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="close">Cancel</button>
						<button type="button" class="a-btn a-btn--primary a-btn--small" @click="save">Save</button>
					</div>
				</footer>
			</article>
		</div>
	</Teleport>
</template>
