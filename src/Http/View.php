<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use RuntimeException;
use Throwable;

/**
 * Plain PHP templates.
 *
 * No template engine: the host pages are a login form and a small dashboard,
 * and a compiler would be more dependency than feature. Every template
 * receives `$escape` and is expected to use it for anything dynamic.
 */
final class View
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = rtrim($this->directory, '/') . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Unknown template: ' . $template);
        }

        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data['escape'] = $escape;

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }
    }
}
