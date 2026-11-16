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
    <h1>Reset password</h1>

    <?php if ($sent): ?>
        <p>
            If an account matches this identifier, an email with a reset link is on its way.
            The link is valid for one hour.
        </p>
        <p class="muted">
            Development without SMTP: the message is stored as a file under
            <code>var/mail/</code>.
        </p>
        <p><a href="/login">Back to sign in</a></p>
    <?php else: ?>
        <p class="muted">Enter your handle or email address.</p>

        <?php if ($error !== null): ?>
            <p class="error"><?= $escape($error) ?></p>
        <?php endif; ?>

        <form method="post" action="/forgot-password">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">

            <label for="identifier">Handle or email</label>
            <input id="identifier" name="identifier" autocomplete="username" required autofocus value="<?= $escape($value) ?>">

            <button type="submit">Request link</button>
        </form>

        <p><a href="/login">Back to sign in</a></p>
    <?php endif; ?>
</section>
