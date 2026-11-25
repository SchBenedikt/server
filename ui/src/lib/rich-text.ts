/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Post bodies come from two places: the Mastodon-compatible presenter wraps a
 * note in `<p>` and keeps line breaks as `<br />`, while a locally written post
 * is plain text. This module decides which one a body is and rebuilds markup
 * from an allow list, so a remote actor cannot ship script, event handlers or
 * inline styles into the feed.
 */

const MARKUP = /<\/?(?:p|br|div|blockquote|ul|ol|li|strong|b|em|i|u|s|del|code|pre|a|span)\b[^>]*>/i;

const ALLOWED_TAGS = new Set([
	'P', 'BR', 'DIV', 'BLOCKQUOTE', 'UL', 'OL', 'LI',
	'STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL', 'CODE', 'PRE', 'A', 'SPAN',
]);

// Elements whose content is not prose a reader should see, or that would load
// something: they are dropped together with their children.
const DROPPED_TAGS = new Set([
	'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE', 'SVG', 'MATH',
	'IMG', 'VIDEO', 'AUDIO', 'SOURCE', 'FORM', 'INPUT', 'BUTTON', 'LINK', 'META',
]);

const SAFE_HREF = /^(?:https?:\/\/|mailto:|#)/i;

export function hasMarkup(text: string): boolean {
	return MARKUP.test(text);
}

export function sanitizeMarkup(markup: string): string {
	const template = document.createElement('template');
	template.innerHTML = markup;
	const container = document.createElement('div');

	const copy = (source: Node, target: Node): void => {
		source.childNodes.forEach((node) => {
			if (node.nodeType === Node.TEXT_NODE) {
				target.appendChild(document.createTextNode(node.textContent ?? ''));
				return;
			}
			if (!(node instanceof Element)) {
				return;
			}

			const tag = node.tagName.toUpperCase();
			if (DROPPED_TAGS.has(tag)) {
				return;
			}
			if (!ALLOWED_TAGS.has(tag)) {
				// Unknown wrappers keep their text but lose the element.
				copy(node, target);
				return;
			}

			const clean = document.createElement(tag.toLowerCase());
			if (tag === 'A') {
				const href = (node.getAttribute('href') ?? '').trim();
				if (!SAFE_HREF.test(href)) {
					copy(node, target);
					return;
				}
				clean.setAttribute('href', href);
				clean.setAttribute('rel', 'nofollow noopener noreferrer');
				if (!href.startsWith('#')) {
					clean.setAttribute('target', '_blank');
				}
				clean.className = 'a-rich-text__link';
			}

			copy(node, clean);
			target.appendChild(clean);
		});
	};

	copy(template.content, container);

	return container.innerHTML;
}
