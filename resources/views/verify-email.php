<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $instance
 * @var bool $ok
 * @var bool|null $sent
 * @var string|null $error
 */

$sent = $sent ?? null;
$error = $error ?? null;

?>
<section class="card">
    <?php if ($ok && $sent === true): ?>
        <h1>Verification email sent</h1>
        <p class="muted">If the address belongs to an unverified account, a new link is on its way. The link is valid for 24 hours.</p>
    <?php elseif ($ok): ?>
        <h1>Email verified</h1>
        <p class="muted">Your email address is confirmed. <a href="/login">Sign in</a> to continue.</p>
    <?php else: ?>
        <h1>Verification failed</h1>
        <p class="error"><?= $escape($error ?? 'The link is invalid or has expired. Request a new one below.') ?></p>
        <form method="post" action="/verify-email/resend">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf ?? '') ?>">
            <label for="identifier">Email or handle</label>
            <input id="identifier" name="identifier" type="text" autocomplete="username" required>
            <button type="submit">Send a new link</button>
        </form>
    <?php endif; ?>
</section>
