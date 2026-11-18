/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { type Ref, type ShallowRef } from 'vue';
import { type UiFailure } from '../api-error.js';
import type { Post } from '../types.js';
export interface PublicTimelineState {
    readonly posts: Ref<Post[]>;
    readonly loading: Ref<boolean>;
    readonly error: ShallowRef<UiFailure | null>;
    readonly hasMore: Ref<boolean>;
    loadMore(): Promise<void>;
    reload(): Promise<void>;
}
export declare function usePublicTimeline(limit?: number, tag?: string): PublicTimelineState;
