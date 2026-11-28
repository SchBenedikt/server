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
import { labelFor, t } from '../lib/i18n';

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
		showToast(t('sidebar.maxReached', { max: MAX_PRIMARY }), 'error');
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
	showToast(t('sidebar.saved'), 'success');
	emit('close');
}

function reset(): void {
	resetSidebar();
	draft.value = [...primarySidebarIds.value];
	showToast(t('sidebar.resetDone'), 'success');
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
						<p class="a-profile-edit__eyebrow">{{ t('sidebar.customize') }}</p>
						<h2 id="a-sidebar-settings-title">{{ t('sidebar.title') }}</h2>
					</div>
					<button type="button" class="a-icon-btn" :aria-label="t('common.closeDialog')" @click="close">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p class="a-muted a-sidebar-settings__hint">
					{{ t('sidebar.hint', { max: MAX_PRIMARY }) }}
				</p>

				<section class="a-sidebar-settings__section">
					<h3>{{ t('sidebar.mainNav') }}</h3>
					<ul v-if="ordered.length > 0" class="a-sidebar-settings__list">
						<li v-for="(item, index) in ordered" :key="item.id">
							<span class="a-sidebar-settings__label">
								<AppIcon :name="item.icon" :size="17" />
								{{ labelFor(item) }}
							</span>
							<span class="a-sidebar-settings__actions">
								<button type="button" class="a-icon-btn" :disabled="index === 0" :aria-label="t('sidebar.moveUp', { name: labelFor(item) })" @click="move(item.id, -1)">
									<AppIcon name="chevronDown" :size="16" class="a-sidebar-settings__up" />
								</button>
								<button type="button" class="a-icon-btn" :disabled="index === ordered.length - 1" :aria-label="t('sidebar.moveDown', { name: labelFor(item) })" @click="move(item.id, 1)">
									<AppIcon name="chevronDown" :size="16" />
								</button>
								<button type="button" class="a-icon-btn a-icon-btn--danger" :aria-label="t('sidebar.removeFromNav', { name: labelFor(item) })" @click="toggle(item.id)">
									<AppIcon name="x" :size="16" />
								</button>
							</span>
						</li>
					</ul>
					<p v-else class="a-muted">{{ t('sidebar.empty') }}</p>
				</section>

				<section v-if="remaining.length > 0" class="a-sidebar-settings__section">
					<h3>{{ t('sidebar.moreEntries') }}</h3>
					<ul class="a-sidebar-settings__list">
						<li v-for="item in remaining" :key="item.id">
							<span class="a-sidebar-settings__label">
								<AppIcon :name="item.icon" :size="17" />
								{{ labelFor(item) }}
							</span>
							<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="draft.length >= MAX_PRIMARY" @click="toggle(item.id)">
								<AppIcon name="plus" :size="14" />
								{{ t('sidebar.pin') }}
							</button>
						</li>
					</ul>
				</section>

				<footer class="a-sidebar-settings__footer">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="reset">
						<AppIcon name="refresh" :size="15" />
						{{ t('sidebar.reset') }}
					</button>
					<div class="a-sidebar-settings__footer-actions">
						<button type="button" class="a-btn a-btn--ghost a-btn--small" @click="close">{{ t('common.cancel') }}</button>
						<button type="button" class="a-btn a-btn--primary a-btn--small" @click="save">{{ t('common.save') }}</button>
					</div>
				</footer>
			</article>
		</div>
	</Teleport>
</template>
