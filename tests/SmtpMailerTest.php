<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Mail\MailException;
use AlohaSocial\Server\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function fclose;
use function file_get_contents;
use function is_file;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function usleep;

final class SmtpMailerTest extends TestCase
{
    /** @var list<array{0: resource, 1: array<int, resource>, 2: string, 3: string}> */
    private array $servers = [];

    /** @var list<string> */
    private array $certificateFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as [$process, $pipes, $portFile, $messageFile]) {
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }
            @proc_terminate($process);
            @proc_close($process);
            @unlink($portFile);
            @unlink($messageFile);
        }
        foreach ($this->certificateFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testSendsMessageAndEncodesUnicodeSubject(): void
    {
        [$port, $messageFile] = $this->server('normal');

        (new SmtpMailer('127.0.0.1', $port, 'none', null, null, 'no-reply@example.test', 2))
            ->send('bob@example.test', 'Hello Wörld', "first\n.second");

        $message = (string) file_get_contents($messageFile);
        self::assertStringContainsString('To: bob@example.test', $message);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Hello Wörld') . '?=', $message);
        self::assertStringContainsString("\r\n..second", $message);
    }

    public function testData250IsAcceptedEvenWhenQuitCloses(): void
    {
        [$port, $messageFile] = $this->server('quit-close');

        (new SmtpMailer('127.0.0.1', $port, 'none', null, null, 'no-reply@example.test', 2))
            ->send('bob@example.test', 'Accepted', 'body');

        self::assertFileExists($messageFile);
        self::assertStringContainsString('body', (string) file_get_contents($messageFile));
    }

    public function testRejectedDataIsAReportedFailureWithoutPeerText(): void
    {
        [$port] = $this->server('reject-data');

        try {
            (new SmtpMailer('127.0.0.1', $port, 'none', null, null, 'no-reply@example.test', 2))
                ->send('bob@example.test', 'Rejected', 'body');
            self::fail('Expected a rejected DATA response to fail.');
        } catch (MailException $exception) {
            self::assertStringContainsString('status 451', $exception->getMessage());
            self::assertStringNotContainsString('try later', $exception->getMessage());
        }
    }

    public function testOversizedReplyIsRejected(): void
    {
        [$port] = $this->server('oversized');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('oversized reply');
        (new SmtpMailer('127.0.0.1', $port, 'none', null, null, 'no-reply@example.test', 2))
            ->send('bob@example.test', 'Large reply', 'body');
    }

    public function testMalformedReplyIsRejected(): void
    {
        [$port] = $this->server('malformed');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('malformed reply');
        (new SmtpMailer('127.0.0.1', $port, 'none', null, null, 'no-reply@example.test', 2))
            ->send('bob@example.test', 'Malformed', 'body');
    }

    public function testStartTlsRejectionIsReported(): void
    {
        [$port] = $this->server('normal');

        try {
            (new SmtpMailer('127.0.0.1', $port, 'starttls', null, null, 'no-reply@example.test', 2))
                ->send('bob@example.test', 'TLS', 'body');
            self::fail('Expected a STARTTLS rejection to fail.');
        } catch (MailException $exception) {
            self::assertStringContainsString('status 454', $exception->getMessage());
        }
    }

    public function testPlaintextAuthNeedsExplicitOptIn(): void
    {
        try {
            (new SmtpMailer('127.0.0.1', 25, 'none', 'alice', 'secret', 'no-reply@example.test', 1))
                ->send('bob@example.test', 'Auth', 'body');
            self::fail('Expected plaintext authentication to be refused.');
        } catch (MailException $exception) {
            self::assertStringContainsString('Plaintext SMTP authentication is disabled', $exception->getMessage());
        }
    }

    public function testAuthCompletesWhenExplicitlyAllowed(): void
    {
        [$port, $messageFile] = $this->server('normal');

        (new SmtpMailer(
            '127.0.0.1',
            $port,
            'none',
            'alice',
            'secret',
            'no-reply@example.test',
            2,
            true,
        ))->send('bob@example.test', 'Authenticated', 'body');

        self::assertStringContainsString('body', (string) file_get_contents($messageFile));
    }

    public function testPeerTextCannotLeakThroughAuthFailure(): void
    {
        [$port] = $this->server('reject-auth');

        try {
            (new SmtpMailer(
                '127.0.0.1',
                $port,
                'none',
                'alice',
                'secret',
                'no-reply@example.test',
                2,
                true,
            ))->send('bob@example.test', 'Rejected auth', 'body');
            self::fail('Expected AUTH failure.');
        } catch (MailException $exception) {
            self::assertStringContainsString('status 535', $exception->getMessage());
            self::assertStringNotContainsString('SMTP-CANARY', $exception->getMessage());
        }
    }

    public function testLargeBodyIsWrittenCompletely(): void
    {
        [$port, $messageFile] = $this->server('normal');
        $body = str_repeat('a', 100_000);

        (new SmtpMailer('127.0.0.1', $port, 'none', null, null, 'no-reply@example.test', 5))
            ->send('bob@example.test', 'Large body', $body);

        self::assertStringContainsString($body, (string) file_get_contents($messageFile));
    }

    public function testSelfSignedTlsCertificateIsRejected(): void
    {
        [$certFile, $keyFile] = $this->certificate();
        [$port] = $this->server('tls', $certFile, $keyFile);

        try {
            (new SmtpMailer('127.0.0.1', $port, 'tls', null, null, 'no-reply@example.test', 2))
                ->send('bob@example.test', 'TLS', 'body');
            self::fail('Expected a self-signed certificate to be rejected.');
        } catch (MailException $exception) {
            self::assertStringContainsString('SMTP connection', $exception->getMessage());
        }
    }

    public function testHeaderInjectionAndInvalidEnvelopeAreRejected(): void
    {
        $mailer = new SmtpMailer('127.0.0.1', 25, 'none', null, null, 'no-reply@example.test', 1);

        try {
            $mailer->send("bob@example.test\r\nRCPT TO:<evil@example.test>", 'Subject', 'body');
            self::fail('Expected an invalid envelope to fail.');
        } catch (MailException $exception) {
            self::assertStringContainsString('envelope address is invalid', $exception->getMessage());
        }

        try {
            $mailer->send('bob@example.test', "Subject\r\nBcc: evil@example.test", 'body');
            self::fail('Expected a header injection to fail.');
        } catch (MailException $exception) {
            self::assertStringContainsString('header value', $exception->getMessage());
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function server(string $mode, string $certFile = '', string $keyFile = ''): array
    {
        $portFile = sys_get_temp_dir() . '/aloha-smtp-port-' . bin2hex(random_bytes(8));
        $messageFile = tempnam(sys_get_temp_dir(), 'aloha-smtp-message-');
        if ($messageFile === false) {
            self::fail('Cannot create the SMTP message fixture.');
        }

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Support/fake-smtp-server.php', $portFile, $mode, $messageFile, $certFile, $keyFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            self::fail('Cannot start the fake SMTP server.');
        }

        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (is_file($portFile) && trim((string) file_get_contents($portFile)) !== '') {
                $this->servers[] = [$process, $pipes, $portFile, $messageFile];

                return [(int) file_get_contents($portFile), $messageFile];
            }
            usleep(10_000);
        }

        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
        self::fail('Fake SMTP server did not start: ' . $error);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function certificate(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ]);
        if ($key === false) {
            self::fail('Cannot generate a test TLS key.');
        }

        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            self::fail('Cannot generate a test TLS CSR.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false || !openssl_x509_export($certificate, $certPem) || !openssl_pkey_export($key, $keyPem)) {
            self::fail('Cannot export the test TLS certificate.');
        }

        $certFile = tempnam(sys_get_temp_dir(), 'aloha-smtp-cert-');
        $keyFile = tempnam(sys_get_temp_dir(), 'aloha-smtp-key-');
        if ($certFile === false || $keyFile === false) {
            self::fail('Cannot create TLS fixture files.');
        }

        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);
        $this->certificateFiles[] = $certFile;
        $this->certificateFiles[] = $keyFile;

        return [$certFile, $keyFile];
    }
}
