<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Mail;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rtrim;
use function str_replace;

/**
 * Writes each message as an `.eml` file into a directory.
 *
 * This is the default transport: a fresh instance must not try to talk to a
 * mail server before the operator configured one. The file is a complete
 * message (headers plus body), so a reset link can be read during setup and
 * mail is never silently dropped.
 */
final class LogMailer implements Mailer
{
    public function __construct(
        private readonly string $directory,
        private readonly string $from,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
            throw new MailException('Cannot create the mail directory: ' . $this->directory);
        }

        $id = bin2hex(random_bytes(10));
        $message = 'From: ' . $this->from . "\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
            . 'Message-ID: <' . $id . '@' . (parse_url('http://' . $this->from, PHP_URL_HOST) ?: 'localhost') . ">\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . str_replace("\n", "\r\n", $body) . "\r\n";

        $path = rtrim($this->directory, '/') . '/' . gmdate('Ymd-His') . '-' . $id . '.eml';
        if (file_put_contents($path, $message) === false) {
            throw new MailException('Cannot write the mail file: ' . $path);
        }

        @chmod($path, 0o640);
    }
}
