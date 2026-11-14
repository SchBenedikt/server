<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var string|null $error
 * @var string $handle
 */

?>
<section class="card">
    <h1>Anmelden</h1>
    <p class="muted">Melde dich mit deinem Handle oder deiner E-Mail-Adresse an.</p>

    <?php if ($error !== null): ?>
        <p class="error"><?= $escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">

        <label for="handle">Handle oder E-Mail</label>
        <input id="handle" name="handle" autocomplete="username" required autofocus value="<?= $escape($handle) ?>">

        <label for="password">Passwort</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button type="submit">Anmelden</button>
    </form>
</section>
