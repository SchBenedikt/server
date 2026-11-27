<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A one-connection SMTP peer for the transport tests. It deliberately writes
 * only protocol data and never includes credentials in its replies.
 */

$portFile = $argv[1] ?? '';
$mode = $argv[2] ?? 'normal';
$messageFile = $argv[3] ?? '';
$certFile = $argv[4] ?? '';
$keyFile = $argv[5] ?? '';

$context = null;
if ($mode === 'tls') {
    if (!is_file($certFile) || !is_file($keyFile)) {
        fwrite(STDERR, "TLS certificate missing\n");
        exit(1);
    }
    $context = stream_context_create([
        'ssl' => [
            'local_cert' => $certFile,
            'local_pk' => $keyFile,
            'verify_peer' => false,
            'allow_self_signed' => true,
        ],
    ]);
}

$server = @stream_socket_server(
    ($mode === 'tls' ? 'tls' : 'tcp') . '://127.0.0.1:0',
    $errorCode,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);
if ($server === false) {
    fwrite(STDERR, 'listen failed: ' . $errorMessage . "\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
if ($portFile === '' || file_put_contents($portFile, (string) $port) === false) {
    fwrite(STDERR, "cannot write port file\n");
    exit(1);
}

$client = @stream_socket_accept($server, 10);
if ($client === false) {
    exit(1);
}

$write = static function ($socket, string $line): void {
    fwrite($socket, $line . "\r\n");
};

$write($client, '220 fake ESMTP');

if ($mode === 'malformed') {
    $write($client, 'ABC');
    fclose($client);
    fclose($server);
    exit(0);
}

if ($mode === 'oversized') {
    // Wait for EHLO so the client observes the reply after sending its command,
    // not a close race during the initial greeting.
    fgets($client, 4096);
    for ($i = 0; $i < 60; $i++) {
        fwrite($client, '250-fake' . $i . "\r\n");
    }
    fwrite($client, "250 done\r\n");
    fclose($client);
    fclose($server);
    exit(0);
}

$dataMode = false;
$authenticating = null;
$buffer = '';

while (($line = fgets($client, 4096)) !== false) {
    $raw = $line;
    $line = rtrim($line, "\r\n");

    if ($dataMode) {
        if ($line === '.') {
            if ($messageFile !== '') {
                file_put_contents($messageFile, $buffer);
            }

            if ($mode === 'reject-data') {
                $write($client, '451 4.3.0 try later');
                break;
            }

            $write($client, '250 2.0.0 queued');
            if ($mode === 'quit-close') {
                break;
            }

            $dataMode = false;
            $buffer = '';
            continue;
        }

        $buffer .= $raw;
        continue;
    }

    if ($authenticating === 'user') {
        $authenticating = 'password';
        $write($client, '334 UGFzc3dvcmQ6');
        continue;
    }

    if ($authenticating === 'password') {
        $authenticating = null;
        if ($mode === 'reject-auth') {
            $write($client, '535 5.7.8 password=SMTP-CANARY');
            break;
        }
        $write($client, '235 2.7.0 authenticated');
        continue;
    }

    if (str_starts_with(strtoupper($line), 'EHLO ') || str_starts_with(strtoupper($line), 'HELO ')) {
        fwrite($client, "250-fake\r\n250-AUTH LOGIN\r\n250 HELP\r\n");
        continue;
    }

    if (strtoupper($line) === 'AUTH LOGIN') {
        $authenticating = 'user';
        $write($client, '334 VXNlcm5hbWU6');
        continue;
    }

    if (str_starts_with(strtoupper($line), 'MAIL FROM:')) {
        $write($client, '250 2.1.0 ok');
        continue;
    }

    if (str_starts_with(strtoupper($line), 'RCPT TO:')) {
        $write($client, '250 2.1.5 ok');
        continue;
    }

    if (strtoupper($line) === 'DATA') {
        $write($client, '354 end with dot');
        $dataMode = true;
        continue;
    }

    if (strtoupper($line) === 'STARTTLS') {
        $write($client, '454 4.7.0 tls unavailable');
        continue;
    }

    if (strtoupper($line) === 'QUIT') {
        $write($client, '221 2.0.0 bye');
        break;
    }

    $write($client, '500 5.5.1 unknown');
}

fclose($client);
fclose($server);
