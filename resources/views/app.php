<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $instance
 * @var string $csrf
 * @var string $handle
 * @var string $displayName
 * @var bool $isAdmin
 */

// The bundle filenames never change, so browsers and CDNs would keep serving
// the previous build. Tie the URLs to the file mtimes instead.
$assetVersion = static function (string $file): string {
    $path = __DIR__ . '/../../public/ui/' . $file;
    $mtime = @filemtime($path);

    return $mtime === false ? '' : '?v=' . $mtime;
};
?>
<link rel="icon" href="/ui/brand/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/ui/app.css<?= $assetVersion('app.css') ?>">
<div
    id="aloha-social"
    class="aloha-root"
    data-instance="<?= $escape($instance) ?>"
    data-user="<?= $escape($handle) ?>"
    data-display-name="<?= $escape($displayName) ?>"
    data-csrf="<?= $escape($csrf) ?>"
    data-signed-in="<?= $handle !== '' ? '1' : '0' ?>"
    data-is-admin="<?= ($isAdmin ?? false) ? '1' : '0' ?>"
    data-atproto="<?= ($atproto ?? false) ? '1' : '0' ?>"
    data-media="<?= ($media ?? false) ? '1' : '0' ?>"
>
    <div class="aloha-boot" aria-hidden="true">
        <span class="aloha-boot__mark"><img src="/ui/brand/aloha-mark.svg" alt=""></span>
        <span class="aloha-boot__text">Opening <?= $escape($instance) ?>…</span>
    </div>
    <noscript>
        <section class="card">
            <h1><?= $escape($instance) ?></h1>
            <p class="muted">
                The Aloha interface requires JavaScript. The classic pages and the API
                still work without it.
            </p>
            <p><a href="/">Go to dashboard</a></p>
        </section>
    </noscript>
</div>
<script type="module" src="/ui/app.js<?= $assetVersion('app.js') ?>"></script>
