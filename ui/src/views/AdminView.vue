<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { ADMIN_SECTIONS, adminSectionHint, adminSectionLabel } from '../lib/admin';
import type { AdminSection } from '../lib/admin';
import { route } from '../lib/router';
import { t } from '../lib/i18n';
import { appState } from '../lib/state';
import AdminAnnouncements from './admin/AdminAnnouncements.vue';
import AdminAudit from './admin/AdminAudit.vue';
import AdminErrors from './admin/AdminErrors.vue';
import AdminFederation from './admin/AdminFederation.vue';
import AdminInvitations from './admin/AdminInvitations.vue';
import AdminModeration from './admin/AdminModeration.vue';
import AdminOverview from './admin/AdminOverview.vue';
import AdminPages from './admin/AdminPages.vue';
import AdminQueue from './admin/AdminQueue.vue';
import AdminReports from './admin/AdminReports.vue';
import AdminSettings from './admin/AdminSettings.vue';
import AdminUsers from './admin/AdminUsers.vue';

const isAdmin = computed(() => appState.session.admin === true);
const isModerator = computed(() => appState.session.moderator === true);
const canModerate = computed(() => isAdmin.value || isModerator.value);
const signedIn = computed(() => appState.session.user !== null);
const MODERATOR_SECTIONS = new Set(['reports', 'moderation']);
const section = computed<AdminSection>(() => {
	const requested = route.value.name === 'admin' ? route.value.section : 'overview';
	if (!isAdmin.value && !MODERATOR_SECTIONS.has(requested)) {
		return 'moderation';
	}
	return requested;
});
const visibleSections = computed(() => (
	isAdmin.value ? ADMIN_SECTIONS : ADMIN_SECTIONS.filter((entry) => MODERATOR_SECTIONS.has(entry.id))
));
const currentLabel = computed(() => adminSectionLabel(section.value));
const currentHint = computed(() => adminSectionHint(section.value));

const sectionView = computed(() => {
	switch (section.value) {
		case 'users':
			return AdminUsers;
		case 'reports':
			return AdminReports;
		case 'moderation':
			return AdminModeration;
		case 'federation':
			return AdminFederation;
		case 'communications':
			return AdminErrors;
		case 'queue':
			return AdminQueue;
		case 'settings':
			return AdminSettings;
		case 'announcements':
			return AdminAnnouncements;
		case 'pages':
			return AdminPages;
		case 'invitations':
			return AdminInvitations;
		case 'audit':
			return AdminAudit;
		default:
			return AdminOverview;
	}
});
</script>

<template>
	<div class="a-page a-admin">
		<PageHeader
			:title="section === 'overview' ? t('admin.title') : currentLabel"
			:subtitle="currentHint"
			back-href="#/"
			:back-label="t('common.backHome')"
		>
			<template #actions>
				<span v-if="isAdmin" class="a-admin-role">
					<AppIcon name="shield" :size="15" />
					{{ t('admin.roleAdmin') }}
				</span>
				<span v-else-if="isModerator" class="a-admin-role">
					<AppIcon name="shield" :size="15" />
					{{ t('admin.roleModerator') }}
				</span>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>{{ t('admin.signInRequired') }}</strong>
				<p>{{ t('admin.signInBody') }}</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">{{ t('admin.signIn') }}</a>
		</div>

		<div v-else-if="!canModerate" class="a-error-panel">
			<AppIcon name="shield" :size="22" />
			<div>
				<strong>{{ t('admin.noAccess') }}</strong>
				<p>{{ t('admin.noAccessBody') }}</p>
			</div>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/">{{ t('common.backHome') }}</a>
		</div>

		<template v-else>
			<nav class="a-admin-tabs" :aria-label="t('admin.tabsAria')">
				<a
					v-for="entry in visibleSections"
					:key="entry.id"
					:href="`#/admin/${entry.id}`"
					class="a-admin-tab"
					:class="{ 'a-admin-tab--active': entry.id === section }"
					:aria-current="entry.id === section ? 'page' : undefined"
				>
					<AppIcon :name="entry.icon" :size="17" />
					<span>{{ adminSectionLabel(entry.id) }}</span>
				</a>
			</nav>

			<component :is="sectionView" :key="section" />
		</template>
	</div>
</template>
