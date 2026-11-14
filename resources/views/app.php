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
 */

?>
<link rel="icon" href="/ui/brand/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/ui/app.css">
<div
    id="aloha-social"
    class="aloha-root"
    data-instance="<?= $escape($instance) ?>"
    data-user="<?= $escape($handle) ?>"
    data-display-name="<?= $escape($displayName) ?>"
    data-csrf="<?= $escape($csrf) ?>"
    data-signed-in="<?= $handle !== '' ? '1' : '0' ?>"
>
    <div class="aloha-boot" aria-hidden="true">
        <span class="aloha-boot__mark"><img src="/ui/brand/aloha-mark.svg" alt=""></span>
        <span class="aloha-boot__text"><?= $escape($instance) ?> wird geöffnet …</span>
    </div>
    <noscript>
        <section class="card">
            <h1><?= $escape($instance) ?></h1>
            <p class="muted">
                Die Aloha-Oberfläche braucht JavaScript. Die klassischen Seiten und die API
                funktionieren auch ohne.
            </p>
            <p><a href="/">Zum Dashboard</a></p>
        </section>
    </noscript>
</div>
<script type="module" src="/ui/app.js"></script>
