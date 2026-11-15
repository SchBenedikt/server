<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';
import Avatar from './Avatar.vue';
import { accountName, handleLabel } from '../lib/format';
import type { Account } from '../lib/types';

const props = withDefaults(defineProps<{
	account: Account;
	subtitle?: string;
	href?: string | null;
	compact?: boolean;
}>(), {
	subtitle: '',
	href: null,
	compact: false,
});

const name = computed(() => accountName(props.account, props.account.id));
const handle = computed(() => `@${handleLabel(props.account.handle || props.account.id).replace(/^@/, '')}`);
const showHandle = computed(() => name.value !== handle.value);
const target = computed(() => {
	if (props.href !== null) {
		return props.href;
	}

	if (props.account.is_remote === true) {
		return props.account.url !== '' ? props.account.url : null;
	}

	return `#/u/${encodeURIComponent(props.account.handle || props.account.id)}`;
});
</script>

<template>
	<div class="a-account-row" :class="{ 'a-account-row--compact': compact }">
		<a
			v-if="target"
			:href="target"
			class="a-account-row__link"
			:target="account.is_remote === true && target.startsWith('http') ? '_blank' : undefined"
			:rel="account.is_remote === true && target.startsWith('http') ? 'noopener noreferrer' : undefined"
		>
			<Avatar :id="account.id" :name="name" :src="account.avatar" :size="compact ? 34 : 42" />
			<span class="a-account-row__copy">
				<strong>{{ name }}</strong>
				<small v-if="showHandle">{{ handle }}</small>
				<small v-if="subtitle" class="a-account-row__subtitle">{{ subtitle }}</small>
			</span>
		</a>
		<div v-else class="a-account-row__link">
			<Avatar :id="account.id" :name="name" :src="account.avatar" :size="compact ? 34 : 42" />
			<span class="a-account-row__copy">
				<strong>{{ name }}</strong>
				<small v-if="showHandle">{{ handle }}</small>
				<small v-if="subtitle" class="a-account-row__subtitle">{{ subtitle }}</small>
			</span>
		</div>
		<div v-if="$slots.actions" class="a-account-row__actions">
			<slot name="actions" />
		</div>
	</div>
</template>
