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
    <h1>New password</h1>

    <?php if ($error !== null): ?>
        <p class="error"><?= $escape($error) ?></p>
    <?php endif; ?>

    <?php if (!$valid): ?>
        <p><a href="/forgot-password">Request a new link</a></p>
    <?php else: ?>
        <p class="muted">Choose a new password with at least 8 characters.</p>

        <form method="post" action="/reset-password">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="token" value="<?= $escape($token) ?>">

            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>

            <label for="password_confirm">Repeat password</label>
            <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>

            <button type="submit">Save password</button>
        </form>
    <?php endif; ?>
</section>
