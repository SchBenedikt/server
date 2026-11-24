<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class ActivityPubDeliveryStatusTest extends TestCase
{
	public function testOwnerCanSeeAndRetryOnlyTheirPostDeliveryFailures(): void
	{
		$server = new TestServer();
		$server->createAccount('alice', 'alice@example.test');
		$server->createAccount('bob', 'bob@example.test');
		$alice = $server->signIn('alice');
		$bob = $server->signIn('bob');
		$server->runtime->database->execute(
			'INSERT INTO social_follower (id, local_user_id, actor_id, inbox_url, followed_at, is_local, state)'
			. " VALUES ('f-remote', 'alice', 'https://remote.example/users/reader', 'https://remote.example/inbox', '2026-11-24T00:00:00Z', 0, 'accepted')",
		);
		$post = $server->runtime->app->posts->create(new CreatePost('alice', 'A federated post', Visibility::Public));
		$bobPost = $server->runtime->app->posts->create(new CreatePost('bob', 'Another account post', Visibility::Public));
		$path = '/api/v1/host/posts/' . rawurlencode($post->id) . '/activitypub-delivery';

		$anonymous = $server->send('GET', $path);
		self::assertSame(401, $anonymous->status);
		$foreign = $server->send('GET', $path, ['cookies' => $bob['cookies']]);
		self::assertSame(404, $foreign->status, 'Another account cannot inspect this post\'s queue state.');
		$otherPost = $server->send('GET', '/api/v1/host/posts/' . rawurlencode($bobPost->id) . '/activitypub-delivery', [
			'cookies' => $bob['cookies'],
		]);
		self::assertSame(200, $otherPost->status);
		self::assertSame(0, json_decode($otherPost->body, true, 32, JSON_THROW_ON_ERROR)['pending']);

		$pending = $server->send('GET', $path, ['cookies' => $alice['cookies']]);
		self::assertSame(200, $pending->status, $pending->body);
		$pendingData = json_decode($pending->body, true, 32, JSON_THROW_ON_ERROR);
		self::assertSame('pending', $pendingData['state']);
		self::assertSame(1, $pendingData['pending']);

		$server->runtime->database->execute(
			"UPDATE social_job SET attempts = 10, dead_at = '2026-11-24T00:01:00Z' WHERE subject_id = 'alice' AND type = 'federation.deliver'",
		);
		$failed = $server->send('GET', $path, ['cookies' => $alice['cookies']]);
		$failedData = json_decode($failed->body, true, 32, JSON_THROW_ON_ERROR);
		self::assertSame('failed', $failedData['state']);
		self::assertSame(1, $failedData['failed']);
		self::assertTrue($failedData['retryable']);

		$retried = $server->json('POST', $path . '/retry', [], $alice['cookies'], $alice['csrf']);
		self::assertSame(200, $retried->status, $retried->body);
		$retriedData = json_decode($retried->body, true, 32, JSON_THROW_ON_ERROR);
		self::assertSame(1, $retriedData['retried']);
		self::assertSame('pending', $retriedData['state']);
		self::assertSame(1, $retriedData['pending']);
		self::assertSame(0, $retriedData['failed']);
	}
}
