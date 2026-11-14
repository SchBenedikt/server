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
    <h1>Hallo, <?= $escape($name) ?></h1>
    <p class="muted">
        Angemeldet als <strong>@<?= $escape($handle) ?></strong><?= $isAdmin ? ' · Administrator' : '' ?>.
    </p>

    <dl>
        <dt>Actor</dt>
        <dd><a href="<?= $escape($actorUrl) ?>"><?= $escape($actorUrl) ?></a></dd>
        <dt>Instanz</dt>
        <dd><?= $escape($instanceUrl) ?></dd>
        <dt>Core</dt>
        <dd><?= $escape($version) ?></dd>
    </dl>

    <h2>API</h2>
    <ul>
        <li><a href="/healthz">/healthz</a></li>
        <li><a href="/api/v1/instance">/api/v1/instance</a></li>
        <li><a href="/api/v1/timelines/public">/api/v1/timelines/public</a></li>
        <li><a href="/.well-known/webfinger?resource=acct:<?= $escape(rawurlencode($handle)) ?>@<?= $escape(parse_url($instanceUrl, PHP_URL_HOST) ?: 'localhost') ?>">WebFinger</a></li>
    </ul>

    <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <button type="submit" class="secondary">Abmelden</button>
    </form>
</section>
