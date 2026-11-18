#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A small release guard: the license, security policy and third-party notice
 * must exist, and every bundled font must be named in the notice file.
 */

$root = dirname(__DIR__);
$required = ['LICENSE', 'SECURITY.md', 'THIRD_PARTY_NOTICES.md'];
$missing = [];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        $missing[] = $file;
    }
}

$fonts = glob($root . '/public/ui/brand/fonts/*.woff2') ?: [];
$notices = is_file($root . '/THIRD_PARTY_NOTICES.md')
    ? (string) file_get_contents($root . '/THIRD_PARTY_NOTICES.md')
    : '';
$unlisted = [];
foreach ($fonts as $font) {
    $name = basename($font);
    if (!str_contains($notices, $name)) {
        $unlisted[] = $name;
    }
}

if ($missing !== [] || $unlisted !== []) {
    fwrite(STDERR, "License check failed.\n");
    foreach ($missing as $file) {
        fwrite(STDERR, "Missing: {$file}\n");
    }
    foreach ($unlisted as $font) {
        fwrite(STDERR, "No attribution entry for font: {$font}\n");
    }
    exit(1);
}

fwrite(STDOUT, "License and attribution files are complete.\n");
