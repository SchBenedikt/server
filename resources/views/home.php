<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $name
 * @var string $handle
 * @var bool $isAdmin
 * @var string $actorUrl
 * @var string $instanceUrl
 * @var string $csrf
 * @var string $version
 */

?>
<section class="card">
    <h1>Hello, <?= $escape($name) ?></h1>
    <p class="muted">
        Signed in as <strong>@<?= $escape($handle) ?></strong><?= $isAdmin ? ' · Administrator' : '' ?>.
    </p>

    <dl>
        <dt>Actor</dt>
        <dd><a href="/@<?= $escape(rawurlencode($handle)) ?>"><?= $escape($actorUrl) ?></a></dd>
        <dt>Instance</dt>
        <dd><?= $escape($instanceUrl) ?></dd>
        <dt>Core</dt>
        <dd><?= $escape($version) ?></dd>
    </dl>

    <h2>App</h2>
    <p><a href="/app">Open the Aloha interface</a></p>

    <?php if ($isAdmin): ?>
    <h2>Administration</h2>
    <p>
        <a href="/admin">Open admin interface</a> ·
        Accounts, reports, federation, queue and audit log.
    </p>
    <?php endif; ?>

    <h2>API</h2>
    <ul>
        <li><a href="/healthz">/healthz</a></li>
        <li><a href="/api/v1/instance">/api/v1/instance</a></li>
        <li><a href="/api/v1/timelines/public">/api/v1/timelines/public</a></li>
        <li><a href="/.well-known/webfinger?resource=acct:<?= $escape(rawurlencode($handle)) ?>@<?= $escape(parse_url($instanceUrl, PHP_URL_HOST) ?: 'localhost') ?>">WebFinger</a></li>
    </ul>

    <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <button type="submit" class="secondary">Sign out</button>
    </form>
</section>
