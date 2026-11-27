<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $csrf
 * @var string $token
 * @var bool $tokenValid
 * @var string|null $tokenExpiresAt
 * @var string $effectiveUrl
 * @var string $envFile
 * @var list<string> $environmentWarnings
 * @var string|null $error
 * @var array<string, string> $errors
 * @var array<string, string> $values
 */

?>
<section class="card">
    <h1>Set up your instance</h1>
    <p class="muted">
        Choose the public address before the first account exists. Actor IDs,
        WebFinger handles and federation links are derived from it, so this is
        the moment to use the final domain.
    </p>
    <p class="muted">
        Effective origin: <code><?= $escape($effectiveUrl) ?></code> from
        <code><?= $escape($envFile) ?></code>.
    </p>

    <?php foreach ($environmentWarnings as $warning): ?>
        <p class="error" role="status"><?= $escape($warning) ?></p>
    <?php endforeach; ?>

    <?php if ($error !== null): ?>
        <p class="error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>

    <?php if (!$tokenValid): ?>
        <h2>Setup is locked</h2>
        <p>
            This page needs the one-time token printed by the setup command.
            Run <code>php bin/setup-token.php</code> on the server and open the
            URL it prints. The token is also stored in
            <code>var/setup.token</code>.
        </p>
        <p class="muted">
            No token is accepted after the first administrator exists. The
            destructive <code>bin/reset.php</code> is only for deliberately
            wiping an installation.
        </p>
    <?php else: ?>
        <?php if ($tokenExpiresAt !== null): ?>
            <p class="muted">
                This setup link expires at
                <time datetime="<?= $escape($tokenExpiresAt) ?>"><?= $escape($tokenExpiresAt) ?></time>
                UTC. Run <code>php bin/setup-token.php</code> again if it has
                expired.
            </p>
        <?php endif; ?>

        <form method="post" action="/setup">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="token" value="<?= $escape($token) ?>">

            <h2>Public identity</h2>
            <label for="setup-url">Public URL</label>
            <input
                id="setup-url"
                name="url"
                type="url"
                required
                maxlength="255"
                placeholder="https://social.example"
                value="<?= $escape($values['url']) ?>"
                autocomplete="url"
            >
            <p class="muted">Use the final HTTPS origin. A path is not supported.</p>
            <?php if (isset($errors['url'])): ?>
                <p class="error" role="alert"><?= $escape($errors['url']) ?></p>
            <?php endif; ?>

            <label for="setup-name">Instance name</label>
            <input
                id="setup-name"
                name="name"
                type="text"
                required
                maxlength="80"
                value="<?= $escape($values['name']) ?>"
                autocomplete="organization"
            >
            <?php if (isset($errors['name'])): ?>
                <p class="error" role="alert"><?= $escape($errors['name']) ?></p>
            <?php endif; ?>

            <label for="setup-description">Short description</label>
            <input
                id="setup-description"
                name="description"
                type="text"
                maxlength="500"
                value="<?= $escape($values['description']) ?>"
            >
            <?php if (isset($errors['description'])): ?>
                <p class="error" role="alert"><?= $escape($errors['description']) ?></p>
            <?php endif; ?>

            <label for="setup-contact">Contact email</label>
            <input
                id="setup-contact"
                name="contact"
                type="email"
                maxlength="254"
                value="<?= $escape($values['contact']) ?>"
                autocomplete="email"
            >
            <p class="muted">Shown to other servers and used for federation questions.</p>
            <?php if (isset($errors['contact'])): ?>
                <p class="error" role="alert"><?= $escape($errors['contact']) ?></p>
            <?php endif; ?>

            <h2>First administrator</h2>
            <label for="setup-handle">Handle</label>
            <input
                id="setup-handle"
                name="handle"
                type="text"
                required
                maxlength="30"
                pattern="[a-z0-9][a-z0-9_-]{1,29}"
                value="<?= $escape($values['handle']) ?>"
                autocomplete="username"
            >
            <p class="muted">2–30 lowercase characters, digits, “_” or “-”.</p>
            <?php if (isset($errors['handle'])): ?>
                <p class="error" role="alert"><?= $escape($errors['handle']) ?></p>
            <?php endif; ?>

            <label for="setup-email">Email address</label>
            <input
                id="setup-email"
                name="email"
                type="email"
                required
                maxlength="254"
                value="<?= $escape($values['email']) ?>"
                autocomplete="email"
            >
            <?php if (isset($errors['email'])): ?>
                <p class="error" role="alert"><?= $escape($errors['email']) ?></p>
            <?php endif; ?>

            <label for="setup-display-name">Display name</label>
            <input
                id="setup-display-name"
                name="display_name"
                type="text"
                maxlength="80"
                value="<?= $escape($values['display_name']) ?>"
                autocomplete="name"
            >
            <?php if (isset($errors['display_name'])): ?>
                <p class="error" role="alert"><?= $escape($errors['display_name']) ?></p>
            <?php endif; ?>

            <label for="setup-password">Password</label>
            <input
                id="setup-password"
                name="password"
                type="password"
                required
                minlength="8"
                maxlength="200"
                autocomplete="new-password"
            >
            <?php if (isset($errors['password'])): ?>
                <p class="error" role="alert"><?= $escape($errors['password']) ?></p>
            <?php endif; ?>

            <label for="setup-password-confirm">Repeat password</label>
            <input
                id="setup-password-confirm"
                name="password_confirm"
                type="password"
                required
                minlength="8"
                maxlength="200"
                autocomplete="new-password"
            >
            <?php if (isset($errors['password_confirm'])): ?>
                <p class="error" role="alert"><?= $escape($errors['password_confirm']) ?></p>
            <?php endif; ?>

            <button type="submit">Create the instance</button>
        </form>
    <?php endif; ?>
</section>
