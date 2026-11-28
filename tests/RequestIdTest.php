<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class RequestIdTest extends TestCase
{
    public function testValidClientRequestIdIsEchoedInHeaderAndErrors(): void
    {
        $server = new TestServer();

        $response = $server->send('GET', '/api/v1/host/preferences', [
            'headers' => ['X-Request-Id' => 'corr-12345-abc'],
        ]);

        self::assertSame('corr-12345-abc', $response->headers['X-Request-Id']);
        self::assertSame(401, $response->status);
        self::assertStringContainsString('"request_id":"corr-12345-abc"', $response->body);
    }

    public function testMissingOrUnsafeRequestIdIsReplaced(): void
    {
        $server = new TestServer();

        $missing = $server->send('GET', '/api/v1/host/preferences');
        self::assertMatchesRegularExpression('/\Areq_[0-9a-f]{16}\z/', (string) $missing->headers['X-Request-Id']);

        $unsafe = $server->send('GET', '/api/v1/host/preferences', [
            'headers' => ['X-Request-Id' => '<script>alert(1)</script>'],
        ]);
        self::assertMatchesRegularExpression('/\Areq_[0-9a-f]{16}\z/', (string) $unsafe->headers['X-Request-Id']);
        self::assertStringNotContainsString('script', $unsafe->body);
    }
}
