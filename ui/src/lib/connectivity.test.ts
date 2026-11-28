/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest';
import { markOffline, markOnline, offline } from './connectivity';

describe('connectivity', () => {
	it('toggles the shared offline flag', () => {
		markOffline();
		expect(offline.value).toBe(true);
		markOnline();
		expect(offline.value).toBe(false);
	});
});
