<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $instance
 * @var bool $ok
 */

?>
<section class="card">
    <?php if ($ok): ?>
        <h1>Email address changed</h1>
        <p class="muted">The new address is confirmed and now receives account mail. <a href="/login">Sign in</a> to continue.</p>
    <?php else: ?>
        <h1>Confirmation failed</h1>
        <p class="error">The link is invalid, has expired, or was superseded by a newer request. Request a new one from your account settings.</p>
    <?php endif; ?>
</section>
