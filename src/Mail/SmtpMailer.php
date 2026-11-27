<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Mail;

use function base64_encode;
use function feof;
use function fclose;
use function fgets;
use function fwrite;
use function gmdate;
use function implode;
use function in_array;
use function is_array;
use function mb_scrub;
use function mb_strcut;
use function microtime;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function rtrim;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strlen;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function substr;
use function trim;
use function usleep;
use function bin2hex;

use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;

/**
 * A small SMTP client: implicit TLS, STARTTLS and AUTH LOGIN.
 *
 * Every write is completed byte-for-byte, every reply is bounded and parsed as
 * a real SMTP status line, and the final DATA 250 is the acceptance boundary.
 * A failed QUIT after that point is cleanup noise, not a reason to send the
 * logical message again. Server response text is never copied into an
 * exception, so a peer cannot leak credentials or private content through an
 * error message.
 */
final class SmtpMailer implements Mailer
{
    private const MAX_REPLY_BYTES = 8192;

    private const MAX_REPLY_LINES = 50;

    private const MAX_REPLY_LINE = 2048;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly ?string $user,
        private readonly ?string $password,
        private readonly string $from,
        private readonly int $timeout = 10,
        private readonly bool $allowPlaintextAuth = false,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        self::assertEnvelopeAddress($to);
        self::assertEnvelopeAddress($this->from);
        self::assertHeaderValue($subject);

        if ($this->user !== null && $this->password === null) {
            throw new MailException('SMTP authentication is configured without a password.');
        }
        if ($this->user !== null && $this->encryption === 'none' && !$this->allowPlaintextAuth) {
            throw new MailException(
                'Plaintext SMTP authentication is disabled. Use STARTTLS or implicit TLS, '
                . 'or explicitly allow it for local testing.',
            );
        }

        $scheme = $this->encryption === 'tls' ? 'ssl' : 'tcp';
        $context = $this->tlsContext();
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            $scheme . '://' . $this->host . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
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

            $this->writeAll($socket, $this->message($to, $subject, $body));
            $this->expect($socket, [250]);

            // The message is accepted now. QUIT is only a courtesy; a missing
            // 221 or a closed socket must not turn an accepted mail into a
            // retryable failure.
            try {
                $this->command($socket, 'QUIT', [221]);
            } catch (MailException) {
                // Keep the accepted result.
            }
        } finally {
            @fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function command($socket, string $line, array $allowed): void
    {
        $this->writeAll($socket, $line . "\r\n");
        $this->expect($socket, $allowed);
    }

    /**
     * @param resource $socket
     * @param list<int> $allowed
     */
    private function expect($socket, array $allowed): void
    {
        $code = null;
        $lines = 0;
        $bytes = 0;

        do {
            if ($lines >= self::MAX_REPLY_LINES || $bytes >= self::MAX_REPLY_BYTES) {
                throw new MailException('The SMTP server sent an oversized reply.');
            }

            $line = fgets($socket, self::MAX_REPLY_LINE);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new MailException(
                    ($meta['timed_out'] ?? false)
                        ? 'The SMTP server timed out.'
                        : 'The SMTP server closed the connection.',
                );
            }

            $bytes += strlen($line);
            $lines++;
            if ($bytes > self::MAX_REPLY_BYTES || !str_ends_with($line, "\n")) {
                throw new MailException('The SMTP server sent an oversized reply.');
            }

            $line = rtrim($line, "\r\n");
            if (preg_match('/^([0-9]{3})([ -])(.*)$/', $line, $match) !== 1) {
                throw new MailException('The SMTP server sent a malformed reply.');
            }

            $lineCode = (int) $match[1];
            if ($code === null) {
                $code = $lineCode;
            } elseif ($lineCode !== $code) {
                throw new MailException('The SMTP server sent a malformed multiline reply.');
            }

            $more = $match[2] === '-';
        } while ($more);

        if ($code === null || !in_array($code, $allowed, true)) {
            // Do not include peer-controlled reply text: it can echo a
            // password, token or private message.
            throw new MailException('The SMTP server rejected the request with status ' . (int) $code . '.');
        }
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        $deadline = microtime(true) + $this->timeout;

        while ($offset < $length) {
            if (microtime(true) >= $deadline) {
                throw new MailException('The SMTP write timed out.');
            }

            $written = @fwrite($socket, substr($data, $offset, 8192));
            if ($written === false) {
                throw new MailException('The SMTP data could not be written.');
            }

            if ($written === 0) {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) || feof($socket)) {
                    throw new MailException('The SMTP connection closed while writing data.');
                }
                usleep(10_000);
                continue;
            }

            $offset += $written;
        }
    }

    private function message(string $to, string $subject, string $body): string
    {
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        // Dot-stuff every line that would otherwise terminate DATA, including
        // the first line of the body.
        $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody) ?? $normalizedBody;
        $normalizedBody = rtrim($normalizedBody, "\n");
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);

        $headers = 'From: ' . $this->from . "\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
            . 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $this->host . ">\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";

        return $headers . "\r\n" . $normalizedBody . "\r\n.\r\n";
    }

    private static function encodeHeader(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        $chunks = [];
        while ($value !== '') {
            $chunk = mb_strcut($value, 0, 45, 'UTF-8');
            if ($chunk === '') {
                throw new MailException('The SMTP header value could not be encoded.');
            }
            $chunks[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $value = substr($value, strlen($chunk));
        }

        return implode("\r\n ", $chunks);
    }

    private static function assertEnvelopeAddress(string $value): void
    {
        if ($value === ''
            || preg_match('/[\r\n<>]/', $value) === 1
            || preg_match('/^[^@\s<>]+@[^@\s<>]+$/', $value) !== 1
        ) {
            throw new MailException('The SMTP envelope address is invalid.');
        }
    }

    private static function assertHeaderValue(string $value): void
    {
        if ($value === '' || preg_match('/[\r\n]/', $value) === 1) {
            throw new MailException('A mail header value is empty or contains a line break.');
        }
    }

    /**
     * Verified TLS is the default. A self-signed or wrong-host certificate
     * must fail instead of silently downgrading the connection.
     *
     * @return resource
     */
    private function tlsContext()
    {
        return stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $this->host,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            ],
        ]);
    }
}
