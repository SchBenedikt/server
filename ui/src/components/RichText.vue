<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';

const props = defineProps<{ text: string }>();

interface Token {
	type: 'text' | 'url' | 'tag' | 'mention';
	text: string;
	href?: string;
}

const tokens = computed<Token[]>(() => {
	const pattern = /(https?:\/\/[^\s<>"']+)|(#[\p{L}\p{N}_]+)|(@[a-zA-Z0-9](?:[a-zA-Z0-9_.-]*[a-zA-Z0-9])?(?:@[a-zA-Z0-9.-]+)?)/gu;
	const result: Token[] = [];
	let lastIndex = 0;

	for (const match of props.text.matchAll(pattern)) {
		const index = match.index ?? 0;
		if (index > lastIndex) {
			result.push({ type: 'text', text: props.text.slice(lastIndex, index) });
		}

		let tokenText = match[0];
		if (match[1] !== undefined) {
			const trailing = tokenText.match(/[.,!?;:)\]}]+$/)?.[0] ?? '';
			const clean = trailing === '' ? tokenText : tokenText.slice(0, -trailing.length);
			if (clean !== '') {
				result.push({ type: 'url', text: clean, href: clean });
			}
			if (trailing !== '') {
				result.push({ type: 'text', text: trailing });
			}
		} else if (match[2] !== undefined) {
			const tag = tokenText.slice(1);
			result.push({ type: 'tag', text: `#${tag}`, href: `#/tag/${encodeURIComponent(tag)}` });
		} else if (match[3] !== undefined) {
			const mention = tokenText.slice(1);
			const parts = mention.split('@');
			if (parts.length === 1) {
				result.push({ type: 'mention', text: tokenText, href: `#/u/${encodeURIComponent(mention)}` });
			} else {
				result.push({ type: 'text', text: tokenText });
			}
		}

		lastIndex = index + tokenText.length;
	}

	if (lastIndex < props.text.length) {
		result.push({ type: 'text', text: props.text.slice(lastIndex) });
	}

	return result;
});
</script>

<template>
	<p class="a-rich-text">
		<template v-for="(token, index) in tokens" :key="index">
			<a
				v-if="token.type === 'url'"
				:href="token.href"
				class="a-rich-text__link"
				target="_blank"
				rel="noopener noreferrer nofollow"
			>{{ token.text }}</a>
			<a
				v-else-if="token.type === 'tag'"
				:href="token.href"
				class="a-rich-text__tag"
			>{{ token.text }}</a>
			<a
				v-else-if="token.type === 'mention'"
				:href="token.href"
				class="a-rich-text__mention"
			>{{ token.text }}</a>
			<template v-else>{{ token.text }}</template>
		</template>
	</p>
</template>
