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
 * @var bool $appShell
 */

$appShell = $appShell ?? false;

?>
<!DOCTYPE html>
<html lang="<?= $escape($lang ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <title><?= $escape($title) ?> · <?= $escape($instance) ?></title>
    <link rel="icon" href="/ui/brand/favicon.svg" type="image/svg+xml">
    <?php if (!$appShell): ?>
    <style>
        @font-face {
            font-family: 'Fredoka';
            font-style: normal;
            font-weight: 300 700;
            font-display: swap;
            src: url('/ui/brand/fonts/Fredoka-Variable.woff2') format('woff2');
        }

        @font-face {
            font-family: 'Public Sans';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/ui/brand/fonts/PublicSans-Variable.woff2') format('woff2');
        }

        @font-face {
            font-family: 'IBM Plex Mono';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/ui/brand/fonts/IBMPlexMono-Regular.woff2') format('woff2');
        }

        :root {
            color-scheme: light;
            --bg: #fbf4ea;
            --card: #ffffff;
            --soft: #f7efe3;
            --text: #1c2f37;
            --strong: #0f4c5c;
            --muted: #5f7078;
            --faint: #7c898e;
            --accent: #b14330;
            --accent-strong: #963a2b;
            --accent-ink: #ffffff;
            --border: #e9dfd1;
            --border-strong: #d9cdba;
            --error: #a53232;
            --error-soft: #f8e3df;
            --success: #1d6b55;
            --glow-one: rgb(223 84 61 / 0.12);
            --glow-two: rgb(15 76 92 / 0.08);
            --shadow: 0 18px 48px rgb(72 48 30 / 0.08);
            --font-sans: 'Public Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            --font-brand: 'Fredoka', 'Public Sans', ui-sans-serif, system-ui, sans-serif;
            --font-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                color-scheme: dark;
                --bg: #0d2029;
                --card: #132b35;
                --soft: #17323e;
                --text: #f4eee4;
                --strong: #fdf8ef;
                --muted: #a0b2b9;
                --faint: #83979e;
                --accent: #ef7a66;
                --accent-strong: #f5917f;
                --accent-ink: #0d2029;
                --border: rgb(255 255 255 / 0.11);
                --border-strong: rgb(255 255 255 / 0.22);
                --error: #ff9b8c;
                --error-soft: rgb(255 155 140 / 0.14);
                --success: #8ad5bd;
                --glow-one: rgb(255 100 75 / 0.16);
                --glow-two: rgb(84 176 194 / 0.12);
                --shadow: 0 20px 56px rgb(0 0 0 / 0.26);
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background:
                radial-gradient(900px 520px at 8% -220px, var(--glow-one), transparent 72%),
                radial-gradient(760px 520px at 96% 8%, var(--glow-two), transparent 72%),
                var(--bg);
            color: var(--text);
            font: 15.5px/1.6 var(--font-sans);
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
        }

        header, main, footer { width: min(560px, 100% - 2rem); margin-inline: auto; }

        header { padding: 2rem 0 1rem; }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            color: var(--strong);
            text-decoration: none;
        }

        .brand img { width: 38px; height: 38px; }

        .brand-copy { display: grid; line-height: 1.1; }

        .brand-copy strong {
            color: var(--strong);
            font-family: var(--font-brand);
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .brand-copy small {
            color: var(--muted);
            font-family: var(--font-mono);
            font-size: 0.55rem;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        main { flex: 1; padding: 0.75rem 0 2.5rem; }

        footer {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 0.5rem 1rem;
            padding: 1.25rem 0 2.5rem;
            border-top: 1px solid var(--border);
            color: var(--faint);
            font-family: var(--font-mono);
            font-size: 0.62rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        footer a { color: var(--muted); }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 1.25rem;
            padding: 1.75rem;
            box-shadow: var(--shadow);
        }

        h1, h2 {
            font-family: var(--font-brand);
            font-weight: 500;
            letter-spacing: -0.025em;
        }

        h1 { margin: 0 0 0.4rem; color: var(--strong); font-size: 2rem; line-height: 1.1; }
        h2 { margin: 1.6rem 0 0.55rem; color: var(--strong); font-size: 1.25rem; }

        p { margin: 0.4rem 0; }

        .muted { color: var(--muted); }

        .error {
            color: var(--error);
            background: var(--error-soft);
            border: 1px solid color-mix(in srgb, var(--error) 25%, transparent);
            border-radius: 0.7rem;
            padding: 0.65rem 0.8rem;
            margin: 0.8rem 0;
            font-size: 0.9rem;
        }

        .notice {
            color: var(--success);
            background: color-mix(in srgb, var(--success) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--success) 25%, transparent);
            border-radius: 0.7rem;
            padding: 0.65rem 0.8rem;
            margin: 0.8rem 0;
            font-size: 0.9rem;
        }

        label {
            display: block;
            margin: 0.95rem 0 0.35rem;
            color: var(--strong);
            font-size: 0.8rem;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 0.7rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            background: var(--soft);
            color: var(--text);
            font: inherit;
        }

        input:focus-visible,
        button:focus-visible,
        a:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--accent) 62%, transparent);
            outline-offset: 2px;
        }

        button {
            margin-top: 1.15rem;
            padding: 0.65rem 1.15rem;
            border: 1px solid var(--accent);
            border-radius: 0.85rem;
            background: var(--accent);
            color: var(--accent-ink);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover { background: var(--accent-strong); border-color: var(--accent-strong); }

        button.secondary {
            background: transparent;
            color: var(--muted);
            border-color: var(--border-strong);
        }

        button.secondary:hover { background: var(--soft); color: var(--text); }

        dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.45rem 1rem; margin: 1.1rem 0 0; }

        dt {
            color: var(--faint);
            font-family: var(--font-mono);
            font-size: 0.68rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        dd { margin: 0; overflow-wrap: anywhere; }

        ul { padding-left: 1.1rem; }

        a { color: var(--accent); text-underline-offset: 0.18em; }

        code { font-family: var(--font-mono); font-size: 0.9em; }

    </style>
    <?php endif; ?>
</head>
<body<?= $appShell ? ' class="app-shell"' : '' ?>>
    <?php if (!$appShell): ?>
        <header>
            <a class="brand" href="/" aria-label="<?= $escape($instance) ?> — Home">
                <img src="/ui/brand/aloha-mark.svg" alt="">
                <span class="brand-copy">
                    <strong><?= $escape($instance) ?></strong>
                    <small>Open &amp; federated</small>
                </span>
            </a>
        </header>
    <?php endif; ?>
    <main><?= $content ?></main>
    <?php if (!$appShell): ?>
        <footer>
            <span>Aloha Social · Core <?= $escape(\AlohaSocial\Server\Support\CoreVersion::current()) ?></span>
            <span><a href="/app">Open app</a> · <a href="/api/v1/instance">API</a></span>
        </footer>
    <?php endif; ?>
</body>
</html>
