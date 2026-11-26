/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest';
import { normalizeMastodonStatus } from './api';

describe('normalizeMastodonStatus', () => {
	it('decodes a doubly escaped remote status before turning it into text', () => {
		const status = normalizeMastodonStatus({
			id: 'https://remote.example/users/eve/statuses/1',
			created_at: '2026-11-26T22:49:29.000Z',
			content: '<p>&lt;p&gt;Remote &lt;script&gt;window.__remote_xss=1&lt;/script&gt; '
				+ '&lt;strong&gt;bold&lt;/strong&gt;&lt;/p&gt;</p>',
			account: {
				id: 'https://remote.example/users/eve',
				username: 'eve',
				acct: 'eve@remote.example',
			},
		});

		expect(status?.content).toBe('Remote window.__remote_xss=1 bold');
		expect(status?.content).not.toContain('<p>');
		expect(status?.content).not.toContain('&lt;');
	});
});
