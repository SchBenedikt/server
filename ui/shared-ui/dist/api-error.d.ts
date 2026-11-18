/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export type UiErrorFields = Record<string, string>;
/**
 * A non-2xx response from the core's documented error shape.
 *
 * `message` comes from the core and is safe to render. The bridge response
 * body is deliberately not retained, so a host cannot accidentally render a
 * body containing a token or a raw upstream response.
 */
export declare class UiApiError extends Error {
    readonly status: number;
    readonly code: string;
    readonly fields: UiErrorFields;
    constructor(status: number, code: string, message: string, fields?: UiErrorFields);
}
/** A request that never produced a usable response. */
export declare class UiTransportError extends Error {
    constructor(message?: string);
}
export type UiFailure = UiApiError | UiTransportError;
export declare function isUiFailure(error: unknown): error is UiFailure;
