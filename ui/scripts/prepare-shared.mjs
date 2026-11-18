/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const shared = resolve(root, 'shared-ui');

if (!existsSync(resolve(shared, 'dist/index.js')) || !existsSync(resolve(shared, 'dist/index.css'))) {
    console.error(
        '[@alohasocial/ui] The committed shared UI package is incomplete.\n'
        + 'Restore ui/shared-ui/ from git; no Core checkout or npm install is needed.',
    );
    process.exit(1);
}

console.log('[@alohasocial/ui] Using the committed shared UI package (ui/shared-ui).');
