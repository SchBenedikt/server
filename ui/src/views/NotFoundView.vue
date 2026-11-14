<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
</script>

<template>
	<div class="a-page">
		<EmptyState
			icon="compass"
			title="Hier führt kein Weg hin"
			text="Die Seite existiert nicht oder wurde verschoben. Zurück zur Startseite findest du wieder Anschluss."
		>
			<a class="a-btn a-btn--primary a-btn--small" href="#/">
				<AppIcon name="home" :size="15" /> Zur Startseite
			</a>
		</EmptyState>
	</div>
</template>
