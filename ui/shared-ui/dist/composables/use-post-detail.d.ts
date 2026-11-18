/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { type MaybeRefOrGetter, type Ref, type ShallowRef } from 'vue';
import { type UiFailure } from '../api-error.js';
import type { Post } from '../types.js';
export interface PostDetailState {
    readonly post: Ref<Post | null>;
    readonly loading: Ref<boolean>;
    readonly error: ShallowRef<UiFailure | null>;
    reload(): Promise<void>;
}
/**
 * Loads one post by its opaque core id. The route is public, so a signed-out
 * reader gets the same projection as the timeline; the composable still
 * leaves 401 handling to the host bridge and never navigates on its own.
 *
 * The request id makes a prop change safe: a late response for the previous
 * post id cannot overwrite the new detail view.
 */
export declare function usePostDetail(postId: MaybeRefOrGetter<string>): PostDetailState;
