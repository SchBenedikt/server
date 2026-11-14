<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Mail;

use function base64_encode;
use function fclose;
use function fgets;
use function fwrite;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function str_replace;
use function strlen;
use function substr;
use function trim;

/**
 * A small SMTP client: implicit TLS, STARTTLS and AUTH LOGIN.
 *
 * Deliberately not a mail library: the server sends one kind of transactional
 * message, and a dependency would own retries and queues the worker already
 * covers. Failures are visible: a mail that cannot be handed off throws, and
 * the reset request reports it instead of pretending to have sent a link.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly ?string $user,
        private readonly ?string $password,
        private readonly string $from,
        private readonly int $timeout = 10,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        self::assertHeaderValue($to);
        self::assertHeaderValue($subject);
        self::assertHeaderValue($this->from);

        $scheme = $this->encryption === 'tls' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            $scheme . '://' . $this->host . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->timeout,
        );

        if ($socket === false) {
            throw new MailException(
                'The SMTP connection to ' . $this->host . ':' . $this->port . ' failed: '
                . ($errorMessage !== '' ? $errorMessage : 'error ' . $errorCode),
            );
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO alohasocial', [250]);

            if ($this->encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new MailException('The SMTP STARTTLS handshake failed.');
                }
                $this->command($socket, 'EHLO alohasocial', [250]);
            }

            if ($this->user !== null) {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->user), [334]);
                $this->command($socket, base64_encode((string) $this->password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
            $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
            // Dot-stuffing: a line that is a single dot ends DATA.
            $normalizedBody = str_replace("\r\n.", "\r\n..", $normalizedBody);

            $headers = 'From: ' . $this->from . "\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $this->host . ">\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n";

            if (fwrite($socket, $headers . "\r\n" . $normalizedBody . "\r\n.\r\n") === false) {
                throw new MailException('The SMTP message could not be written.');
            }

            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param list<int> $allowed
     */
    private function command($socket, string $line, array $allowed): void
    {
        if (fwrite($socket, $line . "\r\n") === false) {
            throw new MailException('The SMTP command could not be written.');
        }

        $this->expect($socket, $allowed);
    }

    /**
     * @param resource $socket
     * @param list<int> $allowed
     */
    private function expect($socket, array $allowed): void
    {
        $code = -1;
        $text = '';

        do {
            $line = fgets($socket, 2048);
            if ($line === false) {
                throw new MailException('The SMTP server closed the connection.');
            }

            $code = (int) substr($line, 0, 3);
            $text .= trim(substr($line, 4));
            $more = strlen($line) > 3 && $line[3] === '-';
        } while ($more);

        if (!in_array($code, $allowed, true)) {
            throw new MailException('The SMTP server answered ' . $code . ' ' . $text);
        }
    }

    private static function assertHeaderValue(string $value): void
    {
        if ($value === '' || preg_match('/[\r\n]/', $value) === 1) {
            throw new MailException('A mail header value is empty or contains a line break.');
        }
    }
}
