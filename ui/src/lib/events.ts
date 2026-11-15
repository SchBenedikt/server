/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { TimelineItem } from './types';

type PostListener = (post: TimelineItem) => void;
type DeleteListener = (postId: string) => void;

const createdListeners = new Set<PostListener>();
const updatedListeners = new Set<PostListener>();
const deletedListeners = new Set<DeleteListener>();

export function onPostCreated(listener: PostListener): () => void {
	createdListeners.add(listener);
	return () => createdListeners.delete(listener);
}

export function onPostUpdated(listener: PostListener): () => void {
	updatedListeners.add(listener);
	return () => updatedListeners.delete(listener);
}

export function onPostDeleted(listener: DeleteListener): () => void {
	deletedListeners.add(listener);
	return () => deletedListeners.delete(listener);
}

export function announcePostCreated(post: TimelineItem): void {
	for (const listener of createdListeners) {
		listener(post);
	}
}

export function announcePostUpdated(post: TimelineItem): void {
	for (const listener of updatedListeners) {
		listener(post);
	}
}

export function announcePostDeleted(postId: string): void {
	for (const listener of deletedListeners) {
		listener(postId);
	}
}
