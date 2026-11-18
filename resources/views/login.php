<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var string|null $error
 * @var string|null $notice
 * @var string $handle
 * @var string $return_to
 * @var bool $registrations
 */

?>
<section class="card">
    <h1>Sign in</h1>
    <p class="muted">Sign in with your handle or email address.</p>

    <?php if ($notice !== null): ?>
        <p class="notice"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="error"><?= $escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <?php if ($return_to !== ''): ?>
            <input type="hidden" name="return_to" value="<?= $escape($return_to) ?>">
        <?php endif; ?>

        <label for="handle">Handle or email</label>
        <input id="handle" name="handle" autocomplete="username" required autofocus value="<?= $escape($handle) ?>">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button type="submit">Sign in</button>
    </form>

    <p class="muted"><a href="/forgot-password">Forgot password?</a></p>
    <?php if ($registrations): ?>
        <p class="muted">New here? <a href="/signup">Create an account</a>.</p>
    <?php endif; ?>
</section>
