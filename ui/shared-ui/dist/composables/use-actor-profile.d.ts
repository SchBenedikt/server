/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { type Ref, type ShallowRef } from 'vue';
import { type UiFailure } from '../api-error.js';
import type { Account } from '../types.js';
export interface ActorProfileState {
    readonly account: Ref<Account | null>;
    readonly loading: Ref<boolean>;
    readonly busy: Ref<boolean>;
    readonly error: ShallowRef<UiFailure | null>;
    toggleFollow(): Promise<void>;
    reload(): Promise<void>;
}
/**
 * Loads one local profile and, when the core returns a viewer relationship,
 * toggles the follow edge. A late response for a previous account id is
 * ignored, so switching profiles cannot flash the old account back.
 */
export declare function useActorProfile(accountId: Ref<string>): ActorProfileState;
