/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { HostBridge } from '../host-bridge.js';
import type { UiCapabilities, UiRequest, UiResponse, UiUser } from '../types.js';
export interface FakeResponse {
    status?: number;
    headers?: Record<string, string>;
    body?: string;
}
export type FakeResponder = FakeResponse | ((request: UiRequest) => FakeResponse | Promise<FakeResponse>);
export interface FakeHostBridgeOptions {
    user?: UiUser | null;
    responses?: Record<string, FakeResponder>;
    capabilities?: UiCapabilities;
    apiBase?: string;
    contractVersion?: number;
}
/**
 * A bridge for tests and component previews. It never performs a network
 * request and records every call so a test can assert the exact core path.
 *
 * A response can be keyed by `METHOD path?query` or by `METHOD path`, or be a
 * function when the answer depends on the cursor or another request field.
 */
export declare class FakeHostBridge implements HostBridge {
    readonly apiBase: string;
    readonly contractVersion: number;
    readonly calls: UiRequest[];
    readonly loginUrls: string[];
    readonly navigations: string[];
    logoutCalls: number;
    private readonly user;
    private readonly capabilitiesValue;
    private readonly responses;
    constructor(options?: FakeHostBridgeOptions);
    currentUser(): Promise<UiUser | null>;
    request(request: UiRequest): Promise<UiResponse>;
    loginUrl(returnTo: string): string;
    logout(): Promise<void>;
    navigate(path: string): void;
    capabilities(): UiCapabilities;
    setResponse(key: string, responder: FakeResponder): void;
}
export declare function fakeRequestKey(request: UiRequest): string;
