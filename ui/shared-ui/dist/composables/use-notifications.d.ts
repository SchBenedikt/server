/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { type Ref, type ShallowRef } from 'vue';
import type { UiFailure } from '../api-error.js';
import type { Notification } from '../types.js';
export interface NotificationsState {
    readonly notifications: Ref<Notification[]>;
    readonly unreadCount: Ref<number>;
    readonly loading: Ref<boolean>;
    readonly mutating: Ref<boolean>;
    readonly error: ShallowRef<UiFailure | null>;
    readonly hasMore: Ref<boolean>;
    loadMore(): Promise<void>;
    reload(): Promise<void>;
    dismiss(id: string): Promise<void>;
    clear(): Promise<void>;
}
/**
 * The first page of unread notifications. The cursor is the last notification
 * id; the core returns the current unread count with every page, so the badge
 * and list cannot disagree after a dismiss or clear.
 */
export declare function useNotifications(limit?: number, enabled?: Ref<boolean>): NotificationsState;
