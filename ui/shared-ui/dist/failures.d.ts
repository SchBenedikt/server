/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { type UiFailure } from './api-error.js';
/**
 * Converts any thrown value into the package's stable failure shape. A host
 * transport error must not leak its URL or exception message into a component.
 */
export declare function toUiFailure(error: unknown): UiFailure;
