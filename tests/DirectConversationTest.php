<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Migrations\Version202611280011RepairDirectMessageRoots;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class DirectConversationTest extends TestCase
{
	public function testConversationStatePersistsThroughTheHttpHost(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$server->createAccount('bob');
		$alice = $server->signIn('alice');
		$bob = $server->signIn('bob');

		$rootResponse = $server->json('POST', '/api/v1/posts', [
			'content' => 'Hello Bob',
			'visibility' => 'direct',
			'recipient_ids' => ['bob'],
		], $alice['cookies'], $alice['csrf']);
		self::assertSame(201, $rootResponse->status, $rootResponse->body);
		$root = json_decode($rootResponse->body, true, 512, JSON_THROW_ON_ERROR)['post'];

		$replyResponse = $server->json('POST', '/api/v1/posts', [
			'content' => 'Hi Alice',
			'visibility' => 'direct',
			'recipient_ids' => ['alice'],
			'in_reply_to_id' => $root['id'],
		], $bob['cookies'], $bob['csrf']);
		self::assertSame(201, $replyResponse->status, $replyResponse->body);
		$reply = json_decode($replyResponse->body, true, 512, JSON_THROW_ON_ERROR)['post'];

		$timeline = $server->send('GET', '/api/v1/timelines/direct', ['cookies' => $alice['cookies']]);
		self::assertSame(200, $timeline->status);
		$posts = json_decode($timeline->body, true, 512, JSON_THROW_ON_ERROR)['posts'];
		$incoming = array_values(array_filter($posts, static fn (array $post): bool => $post['id'] === $reply['id']))[0];
		self::assertSame($root['id'], $incoming['conversation_id']);
		self::assertTrue($incoming['unread']);

		$read = $server->json('POST', '/api/v1/conversations/' . $reply['id'] . '/read', [], $alice['cookies'], $alice['csrf']);
		self::assertSame(200, $read->status, $read->body);
		$timeline = $server->send('GET', '/api/v1/timelines/direct', ['cookies' => $alice['cookies']]);
		$posts = json_decode($timeline->body, true, 512, JSON_THROW_ON_ERROR)['posts'];
		$incoming = array_values(array_filter($posts, static fn (array $post): bool => $post['id'] === $reply['id']))[0];
		self::assertFalse($incoming['unread']);
	}

	public function testLegacyDirectRootsAreLinkedIntoOneConversation(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$server->createAccount('bob');
		$server->createAccount('carol');
		$alice = $server->signIn('alice');

		$first = $server->runtime->app->posts->create(new CreatePost(
			'alice', 'First root', Visibility::Direct, postId: 'dm-root-1', recipientIds: ['bob'],
		))->id;
		$second = $server->runtime->app->posts->create(new CreatePost(
			'bob', 'Second root', Visibility::Direct, postId: 'dm-root-2', recipientIds: ['alice'],
		))->id;
		$third = $server->runtime->app->posts->create(new CreatePost(
			'bob', 'Third root', Visibility::Direct, postId: 'dm-root-3', recipientIds: ['alice'],
		))->id;
		$carol = $server->runtime->app->posts->create(new CreatePost(
			'alice', 'Carol root', Visibility::Direct, postId: 'dm-root-carol', recipientIds: ['carol'],
		))->id;

		$database = $server->runtime->database;
		$migration = new Version202611280011RepairDirectMessageRoots();
		$migration->up($database);

		self::assertSame($first, $database->fetchValue('SELECT in_reply_to_id FROM social_post WHERE id = :id', ['id' => $second]));
		self::assertSame($second, $database->fetchValue('SELECT in_reply_to_id FROM social_post WHERE id = :id', ['id' => $third]));
		self::assertNull($database->fetchValue('SELECT in_reply_to_id FROM social_post WHERE id = :id', ['id' => $carol]));

		$timeline = $server->send('GET', '/api/v1/timelines/direct', ['cookies' => $alice['cookies']]);
		self::assertSame(200, $timeline->status, $timeline->body);
		$posts = json_decode($timeline->body, true, 512, JSON_THROW_ON_ERROR)['posts'];
		$byId = [];
		foreach ($posts as $post) {
			$byId[$post['id']] = $post;
		}
		self::assertSame($first, $byId[$first]['conversation_id']);
		self::assertSame($first, $byId[$second]['conversation_id']);
		self::assertSame($first, $byId[$third]['conversation_id']);
		self::assertSame($carol, $byId[$carol]['conversation_id']);

		$before = $database->fetchAll('SELECT id, in_reply_to_id FROM social_post ORDER BY id');
		$migration->up($database);
		self::assertSame($before, $database->fetchAll('SELECT id, in_reply_to_id FROM social_post ORDER BY id'));
	}
}
