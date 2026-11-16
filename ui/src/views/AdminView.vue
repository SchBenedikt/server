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
import AdminFederation from './admin/AdminFederation.vue';
import AdminOverview from './admin/AdminOverview.vue';
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
		case 'federation':
			return AdminFederation;
		case 'queue':
			return AdminQueue;
		case 'settings':
			return AdminSettings;
		case 'announcements':
			return AdminAnnouncements;
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
			back-label="Zurück zur Startseite"
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
				<strong>Anmeldung erforderlich</strong>
				<p>Die Administration ist nur für angemeldete Konten erreichbar.</p>
			</div>
			<a class="a-btn a-btn--primary a-btn--small" href="/login">Anmelden</a>
		</div>

		<div v-else-if="!isAdmin" class="a-error-panel">
			<AppIcon name="shield" :size="22" />
			<div>
				<strong>Kein Zugriff</strong>
				<p>Diese Oberfläche ist der Administration dieser Instanz vorbehalten.</p>
			</div>
			<a class="a-btn a-btn--ghost a-btn--small" href="#/">Zur Startseite</a>
		</div>

		<template v-else>
			<nav class="a-admin-tabs" aria-label="Administrationsbereiche">
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
