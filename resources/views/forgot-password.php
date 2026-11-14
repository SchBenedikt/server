<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var bool $sent
 * @var string|null $error
 * @var string $value
 */

?>
<section class="card">
    <h1>Passwort zurücksetzen</h1>

    <?php if ($sent): ?>
        <p>
            Wenn ein Konto zu dieser Angabe existiert, ist eine E-Mail mit einem
            Reset-Link unterwegs. Der Link ist eine Stunde gültig.
        </p>
        <p class="muted">
            Entwicklung ohne SMTP: Die Nachricht liegt als Datei unter
            <code>var/mail/</code>.
        </p>
        <p><a href="/login">Zurück zur Anmeldung</a></p>
    <?php else: ?>
        <p class="muted">Gib dein Handle oder deine E-Mail-Adresse ein.</p>

        <?php if ($error !== null): ?>
            <p class="error"><?= $escape($error) ?></p>
        <?php endif; ?>

        <form method="post" action="/forgot-password">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">

            <label for="identifier">Handle oder E-Mail</label>
            <input id="identifier" name="identifier" autocomplete="username" required autofocus value="<?= $escape($value) ?>">

            <button type="submit">Link anfordern</button>
        </form>

        <p><a href="/login">Zurück zur Anmeldung</a></p>
    <?php endif; ?>
</section>
