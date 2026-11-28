<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import { t } from '../lib/i18n';
</script>

<template>
	<div class="a-page">
		<EmptyState
			icon="compass"
			:title="t('notfound.title')"
			:text="t('notfound.text')"
		>
			<a class="a-btn a-btn--primary a-btn--small" href="#/">
				<AppIcon name="home" :size="15" /> {{ t('common.backHome') }}
			</a>
		</EmptyState>
	</div>
</template>
