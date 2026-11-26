<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue';
import { hasMarkup, sanitizeMarkup } from '../lib/rich-text';

const props = withDefaults(defineProps<{ text: string; allowMarkup?: boolean }>(), {
	allowMarkup: false,
});

interface Token {
	type: 'text' | 'url' | 'tag' | 'mention';
	text: string;
	href?: string;
}

/**
 * Federated bodies are HTML and keep their formatting, local bodies are plain
 * text and keep the link, hashtag and mention highlighter. Both paths render
 * inside the same `.a-rich-text` box, so a post looks the same wherever it was
 * written.
 */
// Local posts are plain text even when they contain angle brackets. Only a
// federated body is HTML, so a local post can never inject markup into the
// page through the feed.
const html = computed(() => (
	props.allowMarkup && hasMarkup(props.text) && typeof document !== 'undefined'
		? sanitizeMarkup(props.text)
		: ''
));

const tokens = computed<Token[]>(() => {
	if (html.value !== '') {
		return [];
	}

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
	<div v-if="html !== ''" class="a-rich-text a-rich-text--html" v-html="html" />
	<p v-else class="a-rich-text">
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
