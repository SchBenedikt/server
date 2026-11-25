<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Server\Support\PdsDiscoveryTargetGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdsDiscoveryTargetGuardTest extends TestCase
{
    public function testPinsEveryPublicAddressForTheHttpsRequest(): void
    {
        $guard = new PdsDiscoveryTargetGuard(new FakePdsDiscoveryHostResolver([
            '8.8.8.8',
            '2001:4860:4860::8888',
        ]));

        self::assertSame([
            'example.org:443:8.8.8.8',
            'example.org:443:[2001:4860:4860::8888]',
        ], $guard->curlResolveEntries('https://example.org/xrpc/check'));
    }

    public function testRejectsTheWholeHostWhenAnyDnsAnswerIsNotPublic(): void
    {
        $guard = new PdsDiscoveryTargetGuard(new FakePdsDiscoveryHostResolver([
            '8.8.8.8',
            '0.0.0.0',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-public address: 0.0.0.0');
        $guard->curlResolveEntries('https://example.org/check');
    }

    public function testRejectsUnresolvedHostsAndNonHttpsTargets(): void
    {
        $guard = new PdsDiscoveryTargetGuard(new FakePdsDiscoveryHostResolver([]));
        try {
            $guard->curlResolveEntries('https://missing.example/check');
            self::fail('Expected an unresolved host to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('did not resolve', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $guard->curlResolveEntries('http://example.org/check');
    }
}

/** @internal */
final class FakePdsDiscoveryHostResolver implements HostResolver
{
    /** @param list<string> $addresses */
    public function __construct(private readonly array $addresses)
    {
    }

    public function resolve(string $host): array
    {
        return $this->addresses;
    }
}
