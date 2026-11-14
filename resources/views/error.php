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
    <h1>Das hat nicht geklappt</h1>
    <p class="error"><?= $escape($message) ?></p>
    <p><a href="/">Zurück zur Übersicht</a></p>
</section>
