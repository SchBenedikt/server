<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var string $token
 * @var string|null $error
 */

?>
<section class="card">
    <h1>Two-factor sign-in</h1>
    <p class="muted">Enter the six-digit code from your authenticator app, or one of your recovery codes.</p>

    <?php if ($error !== null): ?>
        <p class="error"><?= $escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login/mfa">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="token" value="<?= $escape($token) ?>">

        <label for="code">Authentication code</label>
        <input id="code" name="code" autocomplete="one-time-code" inputmode="numeric" required autofocus>

        <button type="submit">Verify</button>
    </form>

    <p class="muted"><a href="/login">Back to sign in</a></p>
</section>
