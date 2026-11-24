<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Server\Federation\CompositeAtprotoAccounts;
use PHPUnit\Framework\TestCase;

final class CompositeAtprotoAccountsTest extends TestCase
{
	public function testResolvesNativeAndExternalIdentitiesWithoutChangingTheDefault(): void
	{
		$native = new AtprotoAccount('did:plc:native', 'native.test', 'https://native.example', 'native-token');
		$external = new AtprotoAccount('did:plc:external', 'external.test', 'https://external.example', 'external-token');
		$accounts = new CompositeAtprotoAccounts(
			$this->resolver($native),
			$this->resolver($external),
		);

		self::assertSame($native, $accounts->resolve('alice'));
		self::assertSame([$native, $external], $accounts->resolveAll('alice'));
	}

	public function testDeduplicatesTheSameIdentityReturnedByBothSources(): void
	{
		$account = new AtprotoAccount('did:plc:shared', 'shared.test', 'https://pds.example', 'token');
		$accounts = new CompositeAtprotoAccounts(
			$this->resolver($account),
			$this->resolver($account),
		);

		self::assertSame([$account], $accounts->resolveAll('alice'));
	}

	private function resolver(AtprotoAccount $account): AtprotoAccountResolver
	{
		return new class($account) implements AtprotoAccountResolver {
			public function __construct(private readonly AtprotoAccount $account) {}
			public function resolve(string $localUserId): ?AtprotoAccount { return $this->account; }
		};
	}
}
