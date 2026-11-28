<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import { ADMIN_SECTIONS, adminSectionDefinition } from '../lib/admin';
import { route } from '../lib/router';
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
const signedIn = computed(() => appState.session.user !== null);
const section = computed(() => route.value.name === 'admin' ? route.value.section : 'overview');
const current = computed(() => adminSectionDefinition(section.value));

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
			:title="section === 'overview' ? 'Administration' : current.label"
			:subtitle="current.hint"
			back-href="#/"
			back-label="Back to home"
		>
			<template #actions>
				<span v-if="isAdmin" class="a-admin-role">
					<AppIcon name="shield" :size="15" />
					Administrator
				</span>
			</template>
		</PageHeader>

		<div v-if="!signedIn" class="a-error-panel">
			<AppIcon name="lock" :size="22" />
			<div>
				<strong>Sign-in required</strong>
				<p>The administration is only available to signed-in accounts.</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">Sign in</a>
		</div>

		<div v-else-if="!isAdmin" class="a-error-panel">
			<AppIcon name="shield" :size="22" />
			<div>
				<strong>No access</strong>
				<p>This interface is reserved for the administration of this instance.</p>
			</div>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/">Back to home</a>
		</div>

		<template v-else>
			<nav class="a-admin-tabs" aria-label="Admin areas">
				<a
					v-for="entry in ADMIN_SECTIONS"
					:key="entry.id"
					:href="`#/admin/${entry.id}`"
					class="a-admin-tab"
					:class="{ 'a-admin-tab--active': entry.id === section }"
					:aria-current="entry.id === section ? 'page' : undefined"
				>
					<AppIcon :name="entry.icon" :size="17" />
					<span>{{ entry.label }}</span>
				</a>
			</nav>

			<component :is="sectionView" :key="section" />
		</template>
	</div>
</template>
