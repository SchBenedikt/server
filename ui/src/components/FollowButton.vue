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
const requested = ref(props.account?.relationship?.requested === true);

watch(() => props.account?.relationship?.following, (value) => {
	following.value = value === true;
}, { immediate: true });

watch(() => props.account?.relationship?.requested, (value) => {
	requested.value = value === true;
}, { immediate: true });

const isSelf = computed(() => {
	const user = appState.session.user;
	if (user === null || props.account === null) {
		return false;
	}

	return props.account.id === user.id
		|| props.account.id === user.handle
		|| props.account.handle === user.handle;
});
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
		requested.value = relationship.requested === true;
		applyRelationship(account.id, relationship);
		emit('changed', relationship.following);
		showToast(
			relationship.following
				? `You now follow @${account.id}.`
				: relationship.requested === true
					? `Follow request sent to @${account.id}.`
					: `You no longer follow @${account.id}.`,
			'success',
		);
	} catch (error) {
		showToast(error instanceof Error ? error.message : 'The action failed.', 'error');
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
			'a-follow--requested': requested && !following,
			'a-follow--compact': compact,
		}"
		:disabled="busy"
		:aria-pressed="following || requested"
		@click.stop="toggle"
	>
		<AppIcon :name="following ? 'check' : (requested ? 'clock' : 'plus')" :size="compact ? 14 : 16" />
		<span>{{ following ? 'Following' : (requested ? 'Requested' : 'Follow') }}</span>
	</button>
	<a
		v-else-if="appState.session.user === null && account !== null && !isSelf"
		class="a-follow a-follow--compact"
		href="/login"
	>
		Follow
	</a>
</template>
