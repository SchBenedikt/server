<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A Jetstream stand-in for tests.
 *
 * It performs the RFC 6455 handshake, sends a fixed sequence of Jetstream
 * messages and closes, for as many connections as the caller asks for. That
 * exercises the real socket, frame parser, reconnect path and event
 * normalization without contacting a public relay.
 */

$port = (int) ($argv[1] ?? 0);
$connections = max(1, (int) ($argv[2] ?? 1));
$legacy = ($argv[3] ?? '') === 'legacy';
$infoOnly = ($argv[3] ?? '') === 'info';
$expectedResumeCursor = is_string($argv[4] ?? null) ? $argv[4] : null;

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);
if ($server === false) {
    fwrite(STDERR, 'listen failed: ' . $errorMessage . "\n");
    exit(1);
}

$messages = [
    // A followed account creates a post.
    json_encode([
        'did' => 'did:plc:followed',
        'time_us' => 1_700_000_000_000_001,
        'kind' => 'commit',
        'commit' => [
            'rev' => '3kabc',
            'operation' => 'create',
            'collection' => 'app.bsky.feed.post',
            'rkey' => '3kpost',
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Hello from Jetstream',
                'createdAt' => '2026-11-18T09:00:00.000Z',
            ],
            'cid' => 'bafypost',
        ],
    ], JSON_THROW_ON_ERROR),
    // A like on one of this instance's own published records.
    json_encode([
        'did' => 'did:plc:fan',
        'time_us' => 1_700_000_000_000_002,
        'kind' => 'commit',
        'commit' => [
            'rev' => '3kdef',
            'operation' => 'create',
            'collection' => 'app.bsky.feed.like',
            'rkey' => '3klike',
            'record' => [
                '$type' => 'app.bsky.feed.like',
                'subject' => [
                    'uri' => 'at://did:plc:local/app.bsky.feed.post/3klocal',
                    'cid' => 'bafylocal',
                ],
                'createdAt' => '2026-11-18T09:01:00.000Z',
            ],
            'cid' => 'bafylike',
        ],
    ], JSON_THROW_ON_ERROR),
    // An unfollowed account: the source must drop it.
    json_encode([
        'did' => 'did:plc:stranger',
        'time_us' => 1_700_000_000_000_003,
        'kind' => 'commit',
        'commit' => [
            'rev' => '3kghi',
            'operation' => 'create',
            'collection' => 'app.bsky.feed.post',
            'rkey' => '3kspam',
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Spam from a stranger',
                'createdAt' => '2026-11-18T09:02:00.000Z',
            ],
            'cid' => 'bafyspam',
        ],
    ], JSON_THROW_ON_ERROR),
    // An identity update for a followed account.
    json_encode([
        'did' => 'did:plc:followed',
        'time_us' => 1_700_000_000_000_004,
        'kind' => 'identity',
        'identity' => [
            'did' => 'did:plc:followed',
            'handle' => 'followed.test',
            'seq' => 4,
            'time' => '2026-11-18T09:03:00.000Z',
        ],
    ], JSON_THROW_ON_ERROR),
];

if (!$legacy) {
    foreach ($messages as $index => $message) {
        $event = json_decode($message, true, 64, JSON_THROW_ON_ERROR);
        // Display timestamps can collide; sequence cursors must still advance.
        $event['time_us'] = 1_700_000_000_000_001;
        $event['cursor'] = 900 + $index;
        $event['witnessed_at_us'] = 1_790_000_000_000_001 + $index;
        $messages[$index] = json_encode($event, JSON_THROW_ON_ERROR);
    }
}

if ($infoOnly) {
    array_unshift($messages, json_encode([
        '$type' => '#info',
        'name' => 'OutdatedCursor',
        'message' => 'The requested cursor is older than the replay window.',
    ], JSON_THROW_ON_ERROR));
}

for ($served = 0; $served < $connections; ++$served) {
    $connection = @stream_socket_accept($server, 10);
    if ($connection === false) {
        break;
    }

    $headers = '';
    while (!str_contains($headers, "\r\n\r\n") && strlen($headers) < 65_536) {
        $chunk = fread($connection, 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }

        $headers .= $chunk;
    }

    if ($expectedResumeCursor !== null && $served === 2 && !str_contains($headers, 'cursor=' . $expectedResumeCursor)) {
        fwrite(STDERR, 'The client did not resume from the expected sequence cursor.' . "\n");
        fclose($connection);
        break;
    }

    $key = '';
    if (preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $headers, $match) === 1) {
        $key = $match[1];
    }

    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    fwrite(
        $connection,
        "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . 'Sec-WebSocket-Accept: ' . $accept . "\r\n\r\n",
    );

    foreach ($messages as $message) {
        fwrite($connection, frame($message));
        usleep(15_000);
    }

    usleep(30_000);
    fclose($connection);
}

fclose($server);

function frame(string $payload): string
{
    $length = strlen($payload);
    if ($length < 126) {
        return chr(0x81) . chr($length) . $payload;
    }

    if ($length < 65_536) {
        return chr(0x81) . chr(126) . pack('n', $length) . $payload;
    }

    return chr(0x81) . chr(127) . pack('J', $length) . $payload;
}
