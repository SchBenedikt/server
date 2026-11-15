<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

/**
 * Turns the HTML a remote actor publishes into safe plain text.
 *
 * Remote profiles and posts are rendered by the host's Vue components, which
 * intentionally do not use `v-html`. Stripping the markup here keeps the
 * boundary explicit: a peer cannot inject script or an event handler through
 * a bio or a note.
 */
final class HtmlText
{
    public static function toPlainText(?string $html, int $maxLength = 10_000): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = preg_replace(
            '#<\s*(br|/p|/div|/li|/h[1-6]|/blockquote|/tr)\s*/?\s*>#i',
            "\n",
            $html,
        ) ?? $html;

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', "\n", "\n"], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength)) . '…';
        }

        return $text;
    }
}
