<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class NotificationControlsTest extends TestCase
{
	public function testTheHostServesOwnerScopedNotificationPolicyAndConversationMuteRoutes(): void
	{
		$server = new TestServer();
		$server->createAccount();
		$session = $server->signIn();

		$policy = $server->send('GET', '/api/v1/notifications/policy', ['cookies' => $session['cookies']]);
		self::assertSame(200, $policy->status);
		self::assertSame('allow', $policy->decodedBody()['policy']['actions']['stranger']);

		$updated = $server->json('PATCH', '/api/v1/notifications/policy', [
			'actions' => ['stranger' => 'hold'],
		], $session['cookies'], $session['csrf']);
		self::assertSame(200, $updated->status);
		self::assertSame('hold', $updated->decodedBody()['policy']['actions']['stranger']);

		$post = $server->json('POST', '/api/v1/posts', [
			'content' => 'A thread to mute',
			'visibility' => 'public',
		], $session['cookies'], $session['csrf']);
		self::assertSame(201, $post->status);
		$postId = $post->decodedBody()['post']['id'];

		$muted = $server->json('POST', '/api/v1/conversations/' . rawurlencode($postId) . '/mute', [], $session['cookies'], $session['csrf']);
		self::assertSame(201, $muted->status);
		self::assertSame($postId, $muted->decodedBody()['conversation']['conversation_id']);

		$unmuted = $server->json('DELETE', '/api/v1/conversations/' . rawurlencode($postId) . '/mute', [], $session['cookies'], $session['csrf']);
		self::assertSame(200, $unmuted->status);
		self::assertTrue($unmuted->decodedBody()['unmuted']);
	}
}
