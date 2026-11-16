<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $message
 */

?>
<section class="card">
    <h1>Something went wrong</h1>
    <p class="error"><?= $escape($message) ?></p>
    <p><a href="/">Back to overview</a></p>
</section>
