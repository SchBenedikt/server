/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { type InjectionKey, type Plugin } from 'vue';
import type { HostBridge } from './host-bridge.js';
export declare const hostBridgeKey: InjectionKey<HostBridge>;
export interface AlohaUiOptions {
    bridge: HostBridge;
}
/**
 * The host installs one bridge at its page boundary. Components never import
 * a singleton, read a global or construct a transport themselves.
 */
export declare function createAlohaUi(options: AlohaUiOptions): Plugin;
/**
 * A component calls this in setup(). A missing provider is a host wiring bug,
 * not a state the shared component can recover from.
 */
export declare function useHostBridge(): HostBridge;
