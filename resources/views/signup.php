<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var array<string, string> $errors
 * @var array<string, string> $values
 * @var bool $approval
 * @var bool $verify
 */

$error = $errors['_form'] ?? null;

?>
<section class="card">
    <h1>Create account</h1>
    <p class="muted">
        <?= $approval
            ? 'New accounts are reviewed by an administrator before they can sign in.'
            : ($verify
                ? 'We will email you a verification link to activate your account.'
                : 'Your account is active immediately.') ?>
    </p>

    <?php if ($error !== null): ?>
        <p class="error"><?= $escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/signup">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">

        <label for="handle">Handle</label>
        <input id="handle" name="handle" autocomplete="username" required autofocus
               pattern="[a-z0-9][a-z0-9_-]{1,29}" value="<?= $escape($values['handle'] ?? '') ?>">
        <?php if (isset($errors['handle'])): ?><p class="error"><?= $escape($errors['handle']) ?></p><?php endif; ?>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" required
               value="<?= $escape($values['email'] ?? '') ?>">
        <?php if (isset($errors['email'])): ?><p class="error"><?= $escape($errors['email']) ?></p><?php endif; ?>

        <label for="display_name">Display name <span class="muted">(optional)</span></label>
        <input id="display_name" name="display_name" maxlength="80" autocomplete="name"
               value="<?= $escape($values['display_name'] ?? '') ?>">
        <?php if (isset($errors['display_name'])): ?><p class="error"><?= $escape($errors['display_name']) ?></p><?php endif; ?>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
        <?php if (isset($errors['password'])): ?><p class="error"><?= $escape($errors['password']) ?></p><?php endif; ?>

        <button type="submit">Create account</button>
    </form>

    <p class="muted">Already have an account? <a href="/login">Sign in</a>.</p>
</section>
