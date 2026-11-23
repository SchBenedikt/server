<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Http\TrustedProxyClientAddress;
use PHPUnit\Framework\TestCase;

final class TrustedProxyClientAddressTest extends TestCase
{
    public function testForwardedHeaderIsIgnoredForAnUntrustedPeer(): void
    {
        self::assertSame(
            '198.51.100.8',
            TrustedProxyClientAddress::resolve('198.51.100.8', '203.0.113.77', ['10.0.0.0/8']),
        );
    }

    public function testResolvesClientThroughTrustedProxyChain(): void
    {
        self::assertSame(
            '203.0.113.77',
            TrustedProxyClientAddress::resolve(
                '10.0.0.2',
                '203.0.113.77, 10.0.0.1',
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testStopsAtFirstUntrustedForwardingHop(): void
    {
        self::assertSame(
            '198.51.100.9',
            TrustedProxyClientAddress::resolve(
                '10.0.0.2',
                '203.0.113.77, 198.51.100.9',
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testMalformedOrExcessiveForwardingHeaderFallsBackToPeer(): void
    {
        self::assertSame('10.0.0.2', TrustedProxyClientAddress::resolve('10.0.0.2', 'bad, 203.0.113.7', ['10.0.0.0/8']));
        self::assertSame('10.0.0.2', TrustedProxyClientAddress::resolve('10.0.0.2', str_repeat('1.1.1.1,', 40), ['10.0.0.0/8']));
    }

    public function testValidatesIPv4AndIPv6Ranges(): void
    {
        self::assertTrue(TrustedProxyClientAddress::isValidRange('127.0.0.1'));
        self::assertTrue(TrustedProxyClientAddress::isValidRange('10.0.0.0/8'));
        self::assertTrue(TrustedProxyClientAddress::isValidRange('2001:db8::/32'));
        self::assertFalse(TrustedProxyClientAddress::isValidRange('10.0.0.0/33'));
        self::assertFalse(TrustedProxyClientAddress::isValidRange('proxy.local'));
    }
}
