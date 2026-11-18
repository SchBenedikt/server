<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var string $handle
 * @var string $instance
 */

?>
<!DOCTYPE html>
<html lang="en" data-csrf="<?= $escape($csrf) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration · <?= $escape($instance) ?></title>
    <link rel="stylesheet" href="/admin/admin.css">
    <script src="/admin/admin.js" defer></script>
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <span class="admin-brand__mark" aria-hidden="true">A</span>
            <div>
                <strong><?= $escape($instance) ?></strong>
                <small>Administration</small>
            </div>
        </div>

        <nav class="admin-nav" aria-label="Administration">
            <button type="button" data-section="overview" class="is-active">Overview</button>
            <button type="button" data-section="users">Accounts</button>
            <button type="button" data-section="reports">Reports</button>
            <button type="button" data-section="queue">Queue</button>
            <button type="button" data-section="errors">Errors</button>
            <button type="button" data-section="settings">Settings</button>
            <button type="button" data-section="audit">Audit log</button>
        </nav>

        <div class="admin-sidebar__foot">
            <span>Signed in as <strong>@<?= $escape($handle) ?></strong></span>
            <a href="/app">Open social app</a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Standalone console</p>
                <h1 id="admin-title">Overview</h1>
            </div>
            <button type="button" id="admin-refresh" class="admin-button admin-button--quiet">Refresh</button>
        </header>

        <p id="admin-error" class="admin-alert" role="alert" hidden></p>
        <div id="admin-view" aria-live="polite">
            <p class="admin-loading">Loading…</p>
        </div>
    </main>
</div>
</body>
</html>
