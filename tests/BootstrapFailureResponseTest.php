<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Http\BootstrapFailureResponse;
use PHPUnit\Framework\TestCase;

final class BootstrapFailureResponseTest extends TestCase
{
    public function testHtmlFailurePageDoesNotExposeInfrastructureDetails(): void
    {
        $response = BootstrapFailureResponse::create(false);

        self::assertSame(503, $response->status);
        self::assertStringContainsString('This instance is temporarily unavailable.', $response->body);
        self::assertStringNotContainsString('/var/private', $response->body);
        self::assertStringNotContainsString('secret-password', $response->body);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testApiFailureIsGenericEvenWhenDebugWasConfigured(): void
    {
        // The presenter deliberately receives no exception or debug flag:
        // boot details belong in server logs, never a public response.
        $response = BootstrapFailureResponse::create(true);

        self::assertSame(503, $response->status);
        self::assertStringContainsString('The server is not ready.', $response->body);
        self::assertStringNotContainsString('secret-password', $response->body);
        self::assertStringNotContainsString('/var/private', $response->body);
    }
}
