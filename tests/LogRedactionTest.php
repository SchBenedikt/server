<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Server\Support\ErrorLogLogger;
use AlohaSocial\Server\Support\RuntimeLogger;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogRedactionTest extends TestCase
{
    private string $logFile;

    private string $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'alohasocial-log-');
        file_put_contents($this->logFile, '');
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testMessageUrlReasonAndPeerBodyCannotLeakToEitherSink(): void
    {
        $server = new TestServer();
        $canaries = [];
        for ($i = 0; $i < 7; $i++) {
            $canaries[] = 'CANARY-' . bin2hex(random_bytes(8));
        }

        (new RuntimeLogger($server->runtime->database, $server->runtime->clock))->error(
            'Delivery to https://alice:' . $canaries[0] . '@peer.example/inbox?token=' . $canaries[1]
            . '#fragment failed: password=' . $canaries[2] . ' Authorization: Bearer ' . $canaries[3]
            . ' {peer_message} {url} {reason} {job_id}',
            [
                'url' => 'https://peer.example/inbox?access_token=' . $canaries[4] . '#fragment',
                'reason' => 'refresh_token=' . $canaries[5],
                'peer_message' => 'raw peer body ' . $canaries[6],
                'job_id' => 'job-42',
                'status' => 401,
            ],
        );

        $row = $server->runtime->database->fetchOne(
            'SELECT message, context FROM runtime_error ORDER BY created_at DESC, id DESC LIMIT 1',
        );
        self::assertNotNull($row);
        $all = $this->sapiLog() . ' ' . (string) $row['message'] . ' ' . (string) $row['context'];

        foreach ($canaries as $canary) {
            self::assertStringNotContainsString($canary, $all);
        }
        self::assertStringContainsString('https://peer.example/inbox', $all);
        self::assertStringContainsString('job-42', $all);
        self::assertStringContainsString('401', $all);
        self::assertStringContainsString('[redacted]', $all);
    }

    public function testMalformedUtf8AndOversizedValuesAreBounded(): void
    {
        $server = new TestServer();
        $canary = 'UTF8-CANARY-' . bin2hex(random_bytes(8));

        (new RuntimeLogger($server->runtime->database, $server->runtime->clock))->warning(
            "malformed \xC3\x28 token=" . $canary,
            ['reason' => "bad \xFF value"],
        );
        (new RuntimeLogger($server->runtime->database, $server->runtime->clock))->warning(
            str_repeat('x', 5000),
            ['reason' => str_repeat('y', 5000)],
        );

        $rows = $server->runtime->database->fetchAll('SELECT message, context FROM runtime_error ORDER BY created_at ASC');
        self::assertCount(2, $rows);
        $sapi = $this->sapiLog();

        self::assertTrue(mb_check_encoding($sapi, 'UTF-8'));
        self::assertStringNotContainsString($canary, $sapi . $rows[0]['message'] . $rows[0]['context']);
        self::assertLessThanOrEqual(1000, mb_strlen((string) $rows[0]['message']));
        self::assertLessThanOrEqual(1000, mb_strlen((string) $rows[1]['message']));
        self::assertLessThanOrEqual(4000, strlen((string) $rows[1]['context']));
        self::assertLessThanOrEqual(2200, strlen($sapi));
    }

    public function testDatabaseFailureDoesNotExposeTheDatabaseException(): void
    {
        $server = new TestServer();
        $database = new class () implements Connection {
            public function execute(string $sql, array $parameters = []): int
            {
                throw new RuntimeException('DB-SECRET-CANARY');
            }

            public function fetchAll(string $sql, array $parameters = []): array
            {
                throw new RuntimeException('DB-SECRET-CANARY');
            }

            public function fetchOne(string $sql, array $parameters = []): ?array
            {
                throw new RuntimeException('DB-SECRET-CANARY');
            }

            public function fetchValue(string $sql, array $parameters = []): mixed
            {
                throw new RuntimeException('DB-SECRET-CANARY');
            }

            public function transaction(callable $work): mixed
            {
                throw new RuntimeException('DB-SECRET-CANARY');
            }

            public function driver(): string
            {
                return 'sqlite';
            }
        };

        $canary = 'MESSAGE-CANARY-' . bin2hex(random_bytes(8));
        (new RuntimeLogger($database, $server->runtime->clock))->error('token=' . $canary);

        $sapi = $this->sapiLog();
        self::assertStringContainsString('runtime_error could not be stored', $sapi);
        self::assertStringNotContainsString('DB-SECRET-CANARY', $sapi);
        self::assertStringNotContainsString($canary, $sapi);
    }

    public function testErrorLogLoggerUsesTheSameRedaction(): void
    {
        $canary = 'ERRORLOG-CANARY-' . bin2hex(random_bytes(8));

        (new ErrorLogLogger())->warning(
            'peer {peer_message} token=' . $canary,
            ['peer_message' => 'raw peer body'],
        );

        $sapi = $this->sapiLog();
        self::assertStringNotContainsString($canary, $sapi);
        self::assertStringNotContainsString('raw peer body', $sapi);
        self::assertStringContainsString('[redacted]', $sapi);
    }

    public function testAdminCommunicationsSanitizesLegacyRows(): void
    {
        $server = new TestServer();
        $canaries = [
            'OLD-SOURCE-' . bin2hex(random_bytes(8)),
            'OLD-MESSAGE-' . bin2hex(random_bytes(8)),
            'OLD-REASON-' . bin2hex(random_bytes(8)),
            'OLD-PEER-' . bin2hex(random_bytes(8)),
        ];

        $server->runtime->database->execute(
            'INSERT INTO runtime_error (id, level, source, message, context, created_at)'
            . ' VALUES (:id, :level, :source, :message, :context, :created_at)',
            [
                'id' => bin2hex(random_bytes(16)),
                'level' => 'warning',
                'source' => 'token=' . $canaries[0],
                'message' => 'legacy password=' . $canaries[1],
                'context' => json_encode([
                    'code' => 'timeout',
                    'reason' => 'access_token=' . $canaries[2],
                    'peer_message' => 'raw ' . $canaries[3],
                ], JSON_THROW_ON_ERROR),
                'created_at' => '2026-11-27T00:00:00Z',
            ],
        );

        $encoded = json_encode($server->runtime->admin->communications(), JSON_THROW_ON_ERROR);
        foreach ($canaries as $canary) {
            self::assertStringNotContainsString($canary, $encoded);
        }
        self::assertStringContainsString('timeout', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
    }

    private function sapiLog(): string
    {
        clearstatcache(true, $this->logFile);

        return (string) file_get_contents($this->logFile);
    }
}
