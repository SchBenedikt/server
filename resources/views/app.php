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
<link rel="stylesheet" href="/ui/app.css">
<section class="card">
    <h1><?= $escape($instance) ?></h1>
    <p class="muted">Die gemeinsame Aloha-Oberfläche, gemountet über den HostBridge dieses Servers.</p>

    <div
        id="aloha-social"
        data-instance="<?= $escape($instance) ?>"
        data-user="<?= $escape($handle) ?>"
        data-display-name="<?= $escape($displayName) ?>"
        data-csrf="<?= $escape($csrf) ?>"
        data-signed-in="<?= $handle !== '' ? '1' : '0' ?>"
    >
        <noscript>Die App braucht JavaScript. Die API und die klassischen Seiten funktionieren ohne.</noscript>
    </div>
</section>
<script type="module" src="/ui/app.js"></script>
