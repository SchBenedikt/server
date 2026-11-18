/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge } from './host-bridge.js';
/**
 * Fail fast at the host page boundary when a bridge is too old, too new or
 * incomplete. A missing `contractVersion` is deliberately accepted as contract
 * 1 so existing bridges do not need a new mandatory property.
 */
export declare function assertHostBridgeCompatible(bridge: HostBridge): void;
