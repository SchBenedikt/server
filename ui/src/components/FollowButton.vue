<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { applyRelationship } from '../lib/account';
import { getApi } from '../lib/api';
import { appState, showToast } from '../lib/state';
import type { Account } from '../lib/types';

const props = withDefaults(defineProps<{
	account: Account | null;
	compact?: boolean;
}>(), {
	compact: false,
});

const emit = defineEmits<{ changed: [following: boolean] }>();

const busy = ref(false);
const following = ref(props.account?.relationship?.following === true);

watch(() => props.account?.relationship?.following, (value) => {
	following.value = value === true;
}, { immediate: true });

const isSelf = computed(() => props.account?.id === appState.session.user?.handle);
const canFollow = computed(() => (
	appState.session.user !== null
	&& props.account !== null
	&& props.account.relationship !== null
	&& !isSelf.value
));

async function toggle(): Promise<void> {
	const account = props.account;
	if (account === null || busy.value) {
		return;
	}

	busy.value = true;
	try {
		const relationship = following.value
			? await getApi().unfollowAccount(account.id)
			: await getApi().followAccount(account.id);
		following.value = relationship.following;
		applyRelationship(account.id, relationship);
		emit('changed', relationship.following);
		showToast(
			relationship.following ? `Du folgst jetzt @${account.id}.` : `Du folgst @${account.id} nicht mehr.`,
			'success',
		);
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'Die Aktion ist fehlgeschlagen.', 'error');
	} finally {
		busy.value = false;
	}
}
</script>

<template>
	<button
		v-if="canFollow"
		type="button"
		class="a-follow"
		:class="{
			'a-follow--following': following,
			'a-follow--compact': compact,
		}"
		:disabled="busy"
		:aria-pressed="following"
		@click.stop="toggle"
	>
		<AppIcon :name="following ? 'check' : 'plus'" :size="compact ? 14 : 16" />
		<span>{{ following ? 'Gefolgt' : 'Folgen' }}</span>
	</button>
	<a
		v-else-if="appState.session.user === null && account !== null && !isSelf"
		class="a-follow a-follow--compact"
		href="/login"
	>
		Folgen
	</a>
</template>
