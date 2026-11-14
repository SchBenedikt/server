<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var string $token
 * @var bool $valid
 * @var string|null $error
 */

?>
<section class="card">
    <h1>Neues Passwort</h1>

    <?php if ($error !== null): ?>
        <p class="error"><?= $escape($error) ?></p>
    <?php endif; ?>

    <?php if (!$valid): ?>
        <p><a href="/forgot-password">Neuen Link anfordern</a></p>
    <?php else: ?>
        <p class="muted">Wähle ein neues Passwort mit mindestens 8 Zeichen.</p>

        <form method="post" action="/reset-password">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="token" value="<?= $escape($token) ?>">

            <label for="password">Neues Passwort</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>

            <label for="password_confirm">Passwort wiederholen</label>
            <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>

            <button type="submit">Passwort speichern</button>
        </form>
    <?php endif; ?>
</section>
