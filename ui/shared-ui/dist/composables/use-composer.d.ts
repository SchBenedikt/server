/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { type Ref, type ShallowRef } from 'vue';
import type { UiFailure } from '../api-error.js';
import type { CreatePostInput, Post } from '../types.js';
export interface ComposerState {
    readonly submitting: Ref<boolean>;
    readonly error: ShallowRef<UiFailure | null>;
    submit(input: CreatePostInput): Promise<Post | null>;
    resetError(): void;
}
/**
 * Submits one post through the neutral write route. Client-side rules mirror
 * the core only to keep an empty or oversized draft from making a request;
 * the service remains the authority and its validation errors are shown.
 */
export declare function useComposer(): ComposerState;
