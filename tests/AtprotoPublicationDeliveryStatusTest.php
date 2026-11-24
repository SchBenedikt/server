<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class AtprotoPublicationDeliveryStatusTest extends TestCase
{
	public function testPublicationStatusDistinguishesPendingAndDeadDeliveryJobs(): void
	{
		$server = new TestServer(['atproto_publish' => true]);
		$server->createAccount('alice');
		$session = $server->signIn('alice');
		$post = $server->runtime->app->posts->create(new CreatePost('alice', 'Queued post', Visibility::Public));
		$this->connectBluesky($server);

		$pending = $this->fetchStatus($server, $session['cookies'], $post->id);
		self::assertSame(200, $pending->status, $pending->body);
		self::assertSame('not_queued', $this->decoded($pending)['state']);

		$this->insertJob($server, $post->id, 'publish-pending', null);
		$pending = $this->fetchStatus($server, $session['cookies'], $post->id);
		self::assertSame('pending', $this->decoded($pending)['state']);
		self::assertSame(2, $this->decoded($pending)['attempts']);

		$server->runtime->database->execute('DELETE FROM social_job');
		$this->insertPublication($server, $post->id);
		$published = $this->fetchStatus($server, $session['cookies'], $post->id);
		self::assertSame('published', $this->decoded($published)['state']);
		self::assertNotNull($this->decoded($published)['publication']);

		$this->insertJob($server, $post->id, 'publish-dead', '2026-11-24T12:00:00Z');
		$failed = $this->fetchStatus($server, $session['cookies'], $post->id);
		self::assertSame(200, $failed->status, $failed->body);
		self::assertSame('failed', $this->decoded($failed)['state']);
		self::assertTrue($this->decoded($failed)['retryable']);
		self::assertNotNull($this->decoded($failed)['publication']);

		$server->runtime->database->execute(
			'UPDATE social_atproto_publication SET published_at = :published WHERE post_id = :post',
			['published' => '2026-11-24T12:30:00Z', 'post' => $post->id],
		);
		$retried = $this->fetchStatus($server, $session['cookies'], $post->id);
		self::assertSame('published', $this->decoded($retried)['state']);
		self::assertFalse($this->decoded($retried)['retryable']);
	}

	private function insertPublication(TestServer $server, string $postId): void
	{
		$server->runtime->database->execute(
			'INSERT INTO social_atproto_publication'
			. ' (local_user_id, account_did, post_id, rkey, uri, cid, pds_url, published_at)'
			. ' VALUES (:user, :did, :post, :rkey, :uri, :cid, :pds, :published)',
			[
				'user' => 'alice',
				'did' => 'did:plc:alice123',
				'post' => $postId,
				'rkey' => 'abcdefghijklm',
				'uri' => 'at://did:plc:alice123/app.bsky.feed.post/abcdefghijklm',
				'cid' => 'bafypublished',
				'pds' => 'https://pds.example',
				'published' => '2026-11-24T11:30:00Z',
			],
		);
	}

	public function testPublicationStatusIsPrivateAndRejectsAnUnlinkedIdentity(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$session = $server->signIn('alice');
		$post = $server->runtime->app->posts->create(new CreatePost('alice', 'Private status', Visibility::Public));

		$anonymous = $this->fetchStatus($server, [], $post->id);
		self::assertSame(401, $anonymous->status);

		$unlinked = $this->fetchStatus($server, $session['cookies'], $post->id);
		self::assertSame(404, $unlinked->status);
	}

	private function connectBluesky(TestServer $server): void
	{
		$server->runtime->database->execute(
			'INSERT INTO atproto_account'
			. ' (account_id, did, handle, pds_url, access_token, refresh_token, expires_at, active, created_at, updated_at)'
			. ' VALUES (:account_id, :did, :handle, :pds_url, :access, :refresh, 0, 1, :created, :updated)',
			[
				'account_id' => 'alice',
				'did' => 'did:plc:alice123',
				'handle' => 'alice.bsky.social',
				'pds_url' => 'https://pds.example',
				'access' => 'sealed-access-token',
				'refresh' => '',
				'created' => '2026-11-24T10:00:00Z',
				'updated' => '2026-11-24T10:00:00Z',
			],
		);
	}

	private function insertJob(TestServer $server, string $postId, string $id, ?string $deadAt): void
	{
		$server->runtime->database->execute(
			'INSERT INTO social_job'
			. ' (id, type, payload, available_at, attempts, reserved_at, dead_at, subject_type, subject_id)'
			. ' VALUES (:id, :type, :payload, :available, :attempts, NULL, :dead, :subject_type, :subject_id)',
			[
				'id' => $id,
				'type' => JobType::ATPROTO_PUBLISH,
				'payload' => json_encode([
					'user_id' => 'alice',
					'post_id' => $postId,
					'account_did' => 'did:plc:alice123',
				], JSON_THROW_ON_ERROR),
				'available' => '2026-11-24T11:00:00Z',
				'attempts' => 2,
				'dead' => $deadAt,
				'subject_type' => 'account',
				'subject_id' => 'alice',
			],
		);
	}

	/** @param array<string, string> $cookies */
	private function fetchStatus(TestServer $server, array $cookies, string $postId): \AlohaSocial\Core\Http\Response
	{
		return $server->send('GET', '/api/v1/host/bluesky/publication-status', [
			'cookies' => $cookies,
			'query' => ['post_id' => $postId, 'account_did' => 'did:plc:alice123'],
		]);
	}

	/** @return array<string, mixed> */
	private function decoded(\AlohaSocial\Core\Http\Response $response): array
	{
		return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
	}
}
