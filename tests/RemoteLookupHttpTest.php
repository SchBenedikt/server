<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Server\Tests\Support\FakeBoundedHttpClient;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class RemoteLookupHttpTest extends TestCase
{
	public function testAnExactRemoteProfileCanBeResolvedWithoutSigningIn(): void
	{
		$http = new FakeBoundedHttpClient();
		$actorUrl = 'https://mastodon.test/users/alice';
		$http->json('https://mastodon.test/.well-known/webfinger?resource=acct%3Aalice%40mastodon.test', [
			'subject' => 'acct:alice@mastodon.test',
			'links' => [
				['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUrl],
			],
		]);
		$http->json($actorUrl, [
			'id' => $actorUrl,
			'type' => 'Person',
			'preferredUsername' => 'alice',
			'inbox' => $actorUrl . '/inbox',
		]);

		$server = new TestServer([], $http, new class implements HostResolver {
			public function resolve(string $host): array
			{
				return $host === 'mastodon.test' ? ['8.8.8.8'] : [];
			}
		});

		$response = $server->send('GET', '/api/v1/host/lookup', [
			'query' => ['handle' => '@alice@mastodon.test'],
		]);

		self::assertSame(200, $response->status);
		self::assertSame('activitypub', $response->decodedBody()['kind']);
		self::assertSame('@alice@mastodon.test', $response->decodedBody()['profile']['handle']);
		self::assertNull($response->header('Set-Cookie'));
	}
}
