<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use PHPUnit\Framework\TestCase;

final class NativePdsFirehoseHostTest extends TestCase
{
    public function testHostWrapperServesSubscribeReposFromConfiguredDatabase(): void
    {
        $directory = sys_get_temp_dir() . '/aloha-pds-firehose-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o700));

        $portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($portProbe, $errorMessage);
        $address = stream_socket_get_name($portProbe, false);
        self::assertIsString($address);
        $port = (int) substr(strrchr($address, ':'), 1);
        fclose($portProbe);

        $databasePath = $directory . '/server.sqlite';
        $previousDsn = getenv('ALOHASOCIAL_DB_DSN');
        putenv('ALOHASOCIAL_DB_DSN=sqlite:' . $databasePath);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/pds-firehose.php', '--port=' . $port],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
        );

        try {
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $connection = false;
            $startupOutput = '';
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && !is_resource($connection)) {
                $startupOutput .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.1);
                if (!is_resource($connection)) {
                    usleep(25_000);
                }
            }

            self::assertIsResource($connection, 'The configured firehose did not start listening. ' . $startupOutput);
            stream_set_timeout($connection, 2);
            fwrite($connection, "GET /xrpc/com.atproto.sync.subscribeRepos HTTP/1.1\r\n"
                . "Host: localhost\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n");
            $response = '';
            while (!str_contains($response, "\r\n\r\n") && !feof($connection)) {
                $response .= fread($connection, 4096);
            }
            fclose($connection);

            self::assertStringStartsWith('HTTP/1.1 101 Switching Protocols', $response);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ([1, 2] as $pipe) {
                    if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                        fclose($pipes[$pipe]);
                    }
                }
                proc_close($process);
            }

            if ($previousDsn === false) {
                putenv('ALOHASOCIAL_DB_DSN');
            } else {
                putenv('ALOHASOCIAL_DB_DSN=' . $previousDsn);
            }

            foreach (glob($directory . '/server.sqlite*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
