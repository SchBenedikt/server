<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class HomeTimelineFilterPaginationTest extends TestCase
{
	public function testHomeFeedReturnsVisiblePostsPastFilteredRows(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$alice = $server->signIn('alice');

		$visible = $this->createPost($server, $alice, 'ordinary post');
		$middle = $this->createPost($server, $alice, 'secret middle');
		$newest = $this->createPost($server, $alice, 'secret newest');
		foreach ([
			$visible => '2026-11-24T10:00:00Z',
			$middle => '2026-11-24T10:01:00Z',
			$newest => '2026-11-24T10:02:00Z',
		] as $postId => $publishedAt) {
			$server->runtime->database->execute(
				'UPDATE social_post SET published_at = :published WHERE id = :id',
				['published' => $publishedAt, 'id' => $postId],
			);
		}

		$filter = $server->json('POST', '/api/v1/filters', [
			'name' => 'Hide secrets',
			'action' => 'hide',
			'contexts' => ['home'],
			'keywords' => [['text' => 'secret']],
		], $alice['cookies'], $alice['csrf']);
		self::assertSame(201, $filter->status, $filter->body);

		$page = $server->send('GET', '/api/v1/timelines/home', [
			'cookies' => $alice['cookies'],
			'query' => ['limit' => '1'],
		]);
		self::assertSame(200, $page->status, $page->body);
		$body = json_decode($page->body, true, 512, JSON_THROW_ON_ERROR);
		self::assertSame([$visible], array_column($body['posts'], 'id'));
		self::assertFalse($body['has_more']);
	}

	/** @param array{cookies: array<string, string>, csrf: string} $session */
	private function createPost(TestServer $server, array $session, string $content): string
	{
		$response = $server->json('POST', '/api/v1/posts', [
			'content' => $content,
			'visibility' => 'public',
		], $session['cookies'], $session['csrf']);
		self::assertSame(201, $response->status, $response->body);

		return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['post']['id'];
	}
}
