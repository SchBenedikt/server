<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var callable $escape
 * @var string $title
 * @var string $instance
 * @var string $content
 */

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title) ?> · <?= $escape($instance) ?></title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f6f5f2;
            --card: #ffffff;
            --text: #1c1b19;
            --muted: #6b6862;
            --accent: #2f6f5e;
            --border: #e2ded7;
            --error: #a4351f;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #17181a;
                --card: #1f2124;
                --text: #ececec;
                --muted: #a5a39e;
                --accent: #7fd3ba;
                --border: #2e3135;
                --error: #ff8f76;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--bg);
            color: var(--text);
            font: 16px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        header, main, footer { width: min(760px, 100% - 2rem); margin-inline: auto; }

        header { padding: 1.5rem 0 0.5rem; }

        header a {
            color: var(--text);
            text-decoration: none;
            font-weight: 650;
            letter-spacing: -0.01em;
        }

        main { flex: 1; padding: 0.5rem 0 2rem; }

        footer {
            padding: 1rem 0 2rem;
            color: var(--muted);
            font-size: 0.85rem;
        }

        footer a { color: var(--muted); }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.5rem;
        }

        h1 { font-size: 1.45rem; margin: 0 0 0.35rem; letter-spacing: -0.02em; }
        h2 { font-size: 1rem; margin: 1.5rem 0 0.5rem; }

        p { margin: 0.4rem 0; }

        .muted { color: var(--muted); }

        .error {
            color: var(--error);
            border: 1px solid currentColor;
            border-radius: 8px;
            padding: 0.6rem 0.75rem;
            margin: 0.75rem 0;
            font-size: 0.92rem;
        }

        .notice {
            color: var(--accent);
            border: 1px solid currentColor;
            border-radius: 8px;
            padding: 0.6rem 0.75rem;
            margin: 0.75rem 0;
            font-size: 0.92rem;
        }

        label { display: block; margin: 0.9rem 0 0.3rem; font-size: 0.9rem; font-weight: 600; }

        input {
            width: 100%;
            padding: 0.6rem 0.7rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text);
            font: inherit;
        }

        button {
            margin-top: 1.1rem;
            padding: 0.6rem 1rem;
            border: 0;
            border-radius: 8px;
            background: var(--accent);
            color: var(--card);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button.secondary { background: transparent; color: var(--accent); border: 1px solid currentColor; }

        dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.35rem 1rem; margin: 1rem 0 0; }
        dt { color: var(--muted); }
        dd { margin: 0; overflow-wrap: anywhere; }

        ul { padding-left: 1.1rem; }
        a { color: var(--accent); }

        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9em; }
    </style>
</head>
<body>
    <header><a href="/"><?= $escape($instance) ?></a></header>
    <main><?= $content ?></main>
    <footer>Aloha Social server · core <?= $escape(\AlohaSocial\Server\Support\CoreVersion::current()) ?></footer>
</body>
</html>
