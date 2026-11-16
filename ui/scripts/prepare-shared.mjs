/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const shared = resolve(root, '../vendor/alohasocial/core/ui');
const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';

function run(args) {
    const result = spawnSync(npm, args, {
        cwd: shared,
        stdio: 'inherit',
        env: process.env,
    });

    if (result.error) {
        console.error(`[@alohasocial/ui] ${result.error.message}`);
        process.exit(1);
    }

    if (result.status !== 0) {
        process.exit(result.status ?? 1);
    }
}

if (existsSync(resolve(shared, 'dist/index.js'))) {
    process.exit(0);
}

if (!existsSync(resolve(shared, 'package.json'))) {
    console.error(
        '[@alohasocial/ui] The installed core archive does not contain ui/.\n'
        + 'Run "composer reinstall alohasocial/core --prefer-source" once, then retry.\n'
        + 'The committed public/ui/ bundle is unaffected; this is only needed to rebuild the UI.',
    );
    process.exit(1);
}

if (!existsSync(resolve(shared, 'node_modules'))) {
    console.log('[@alohasocial/ui] Installing shared UI dependencies...');
    run(['ci']);
}

console.log('[@alohasocial/ui] Building the shared UI package...');
run(['run', 'build']);
