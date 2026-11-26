/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest';
import { notificationPostHref } from './format';
import type { TimelineItem } from './types';

function item(id: string, objectId: string | null = null): TimelineItem {
	return { id, object_id: objectId } as TimelineItem;
}

describe('notificationPostHref', () => {
	it('links a local status through its local route', () => {
		expect(notificationPostHref(item('local-post'), 'local-post')).toBe('#/p/local-post');
	});

	it('fell back to the durable target when the status is remote', () => {
		const remote = item('https://remote.example/users/eve/statuses/1');

		expect(notificationPostHref(remote, remote.id)).toBe(
			'#/p/https%3A%2F%2Fremote.example%2Fusers%2Feve%2Fstatuses%2F1',
		);
	});

	it('uses the target when a remote notification has no status projection', () => {
		expect(notificationPostHref(null, 'https://remote.example/statuses/2')).toBe(
			'#/p/https%3A%2F%2Fremote.example%2Fstatuses%2F2',
		);
	});

	it('returns null when neither a route nor a target exists', () => {
		expect(notificationPostHref(null, null)).toBeNull();
	});
});
