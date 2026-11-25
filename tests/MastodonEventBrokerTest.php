<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Events\StreamAudience;
use AlohaSocial\Core\Platform\Events\StreamEventDraft;
use AlohaSocial\Core\Platform\Events\StreamSubscription;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Auth\MastodonOAuthRepository;
use AlohaSocial\Server\Http\FrontController;
use AlohaSocial\Server\Http\MastodonStreamingController;
use AlohaSocial\Server\Federation\Mastodon\PdoEventBroker;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

final class MastodonEventBrokerTest extends TestCase
{
	public function testNewPostIsStoredForPublicAndHomeReplayWithStableCursor(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$post = $server->runtime->app->posts->create(new CreatePost('alice', 'hello #social'));
		$broker = $server->runtime->app->eventBroker;
		self::assertNotNull($broker);

		$public = $broker->read(new StreamSubscription('alice', ['public']), null, 10);
		$home = $broker->read(new StreamSubscription('alice', ['home']), null, 10);
		self::assertCount(1, $public);
		self::assertCount(1, $home);
		self::assertSame(StreamAudience::Public, $public[0]->audience);
		self::assertSame(['status_id' => $post->id], $public[0]->payload);
		self::assertNotSame('', $public[0]->id);
		self::assertSame([], $broker->read(new StreamSubscription('alice', ['public']), $public[0]->id, 10));
	}

	public function testDirectPostNeverAppearsInPublicOrHomeStreams(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$server->createAccount('bob');
		$server->runtime->app->posts->create(new CreatePost(
			'alice',
			'private',
			Visibility::Direct,
			recipientIds: ['bob'],
		));
		$broker = $server->runtime->app->eventBroker;
		self::assertNotNull($broker);

		self::assertCount(0, $broker->read(new StreamSubscription('bob', ['public']), null, 10));
		self::assertCount(0, $broker->read(new StreamSubscription('bob', ['home']), null, 10));
		$direct = $broker->read(new StreamSubscription('bob', ['direct']), null, 10);
		self::assertCount(1, $direct);
		self::assertSame(StreamAudience::Direct, $direct[0]->audience);
		self::assertSame(['alice', 'bob'], $direct[0]->recipientIds);
	}

	public function testPublicLocalStreamExcludesRemotePublicStatuses(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$localPost = $server->runtime->app->posts->create(new CreatePost('alice', 'local status'));
		$broker = $server->runtime->app->eventBroker;
		self::assertNotNull($broker);
		$broker->publish(new StreamEventDraft(
			name: 'status.created',
			audience: StreamAudience::Public,
			actorId: 'https://remote.example/users/remy',
			recipientIds: ['alice'],
			payload: ['status_id' => 'https://remote.example/posts/1'],
			occurredAt: '2026-11-25T00:00:00Z',
		));

		$localEvents = $broker->read(new StreamSubscription('alice', ['public:local']), null, 10);
		$publicEvents = $broker->read(new StreamSubscription('alice', ['public']), null, 10);

		self::assertCount(1, $localEvents);
		self::assertSame(['status_id' => $localPost->id], $localEvents[0]->payload);
		self::assertCount(2, $publicEvents);
	}

	public function testPublishRollsBackTheEventWhenRecipientFanoutFails(): void
	{
		$server = new TestServer();
		$server->createAccount('alice');
		$broker = $server->runtime->app->eventBroker;
		self::assertNotNull($broker);
		$server->runtime->database->execute('DROP TABLE mastodon_stream_event_recipient');

		$failed = false;
		try {
			$broker->publish(new StreamEventDraft(
				name: 'status.created',
				audience: StreamAudience::Public,
				actorId: 'alice',
				recipientIds: ['alice'],
				payload: ['status_id' => 'post-1'],
				occurredAt: '2026-11-25T00:00:00Z',
			));
		} catch (\Throwable) {
			$failed = true;
		}

		self::assertTrue($failed, 'The missing recipient table must abort the event write.');
		self::assertSame(0, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM mastodon_stream_event'));
	}

	public function testRejectsUnsupportedStreamChannelsInsteadOfSilentlyReturningEmpty(): void
	{
		$server = new TestServer();
		$broker = $server->runtime->app->eventBroker;
		self::assertNotNull($broker);

		$this->expectException(\InvalidArgumentException::class);
		$broker->read(new StreamSubscription('alice', ['hashtag'], hashtags: ['cats']), null, 10);
	}

    public function testReplayLogExpiresAfterSevenDays(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $server->runtime->app->posts->create(new CreatePost('alice', 'hello'));
        $broker = $server->runtime->app->eventBroker;
        self::assertNotNull($broker);
        $events = $broker->read(new StreamSubscription('alice', ['public']), null, 10);
        self::assertCount(1, $events);

        $futureClock = new FrozenClock($server->runtime->clock->now()->modify('+8 days'));
        $expiredBroker = new PdoEventBroker($server->runtime->database, $futureClock);
        self::assertSame([], $expiredBroker->read(new StreamSubscription('alice', ['public']), null, 10));
        $this->expectException(\InvalidArgumentException::class);
        $expiredBroker->read(new StreamSubscription('alice', ['public']), $events[0]->id, 10);
    }

    public function testAccountErasureRemovesStreamEventsAndRecipientRows(): void
    {
        $server = new TestServer();
        $server->createAccount('alice');
        $server->createAccount('bob');
        $server->runtime->app->posts->create(new CreatePost('alice', 'private', Visibility::Direct, recipientIds: ['bob']));
        self::assertSame(2, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM mastodon_stream_event'));
        self::assertSame(3, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM mastodon_stream_event_recipient'));

        $server->runtime->accountErasureCleanup->erase('alice');

        self::assertSame(0, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM mastodon_stream_event'));
        self::assertSame(0, (int) $server->runtime->database->fetchValue('SELECT COUNT(*) FROM mastodon_stream_event_recipient'));
    }

    public function testStreamingRouteRequiresOAuthAndEmitsMastodonUpdateFrames(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $server->runtime->app->posts->create(new CreatePost('alice', 'hello'));

        $unauthenticated = (new FrontController($server->runtime))->handle(
            new Request('GET', '/api/mastodon/v1/streaming', query: ['stream' => 'public']),
        );
        self::assertSame(401, $unauthenticated->status);

        $token = $this->issueToken($server, 'alice');
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'public'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );
        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame('text/event-stream; charset=utf-8', $response->header('Content-Type'));

        $chunks = iterator_to_array($response->chunks(), false);
        self::assertNotEmpty($chunks);
        self::assertStringContainsString("event: update\ndata: {\"id\":", $chunks[0]);
    }

    public function testPublicLocalStreamingExcludesRemotePublicStatuses(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $localPost = $server->runtime->app->posts->create(new CreatePost('alice', 'local status'));
        $broker = $server->runtime->app->eventBroker;
        self::assertNotNull($broker);
        (new PdoRemotePostRepository($server->runtime->database))->insert(new RemotePost(
            activityId: 'https://remote.example/activities/1',
            objectId: 'https://remote.example/posts/1',
            actorId: 'https://remote.example/users/remy',
            kind: RemotePostKind::Post,
            content: 'remote status must not be in the local stream',
            contentWarning: null,
            publishedAt: new \DateTimeImmutable('2026-11-25T00:00:00Z'),
            objectAuthorId: 'https://remote.example/users/remy',
        ));
        $broker->publish(new StreamEventDraft(
            name: 'status.created',
            audience: StreamAudience::Public,
            actorId: 'https://remote.example/users/remy',
            recipientIds: ['alice'],
            payload: ['status_id' => 'https://remote.example/posts/1'],
            occurredAt: '2026-11-25T00:00:00Z',
        ));

        $token = $this->issueToken($server, 'alice');
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'public:local'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );
        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        $frames = iterator_to_array($response->chunks(), false);
        $body = implode('', $frames);

        self::assertCount(1, $frames);
        self::assertStringContainsString((string) $localPost->id, $body);
        self::assertStringNotContainsString('https://remote.example/posts/1', $body);
    }

    public function testStreamingEmitsDeleteFrameForRemovedPublicStatus(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $post = $server->runtime->app->posts->create(new CreatePost('alice', 'remove me'));
        $broker = $server->runtime->app->eventBroker;
        self::assertNotNull($broker);
        $created = $broker->read(new StreamSubscription('alice', ['public']), null, 10)[0];
        $server->runtime->app->posts->delete('alice', $post->id);
        $token = $this->issueToken($server, 'alice');
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'public'],
            headers: ['Authorization' => 'Bearer ' . $token, 'Last-Event-ID' => $created->id],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );

        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        $frames = iterator_to_array($response->chunks(), false);
        self::assertCount(1, $frames);
        self::assertStringContainsString("event: delete\ndata: \"{$post->id}\"", $frames[0]);
    }

    public function testStreamingRendersTheCurrentStatusAfterAnEdit(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $post = $server->runtime->app->posts->create(new CreatePost('alice', 'before'));
        $broker = $server->runtime->app->eventBroker;
        self::assertNotNull($broker);
        $created = $broker->read(new StreamSubscription('alice', ['public']), null, 10)[0];
        $server->runtime->app->posts->edit('alice', $post->id, new \AlohaSocial\Core\Service\UpdatePost(content: 'after'));
        $token = $this->issueToken($server, 'alice');
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'public'],
            headers: ['Authorization' => 'Bearer ' . $token, 'Last-Event-ID' => $created->id],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );

        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        $frames = iterator_to_array($response->chunks(), false);
        self::assertCount(1, $frames);
        self::assertStringContainsString('event: update', $frames[0]);
        self::assertStringContainsString('after', $frames[0]);
    }

    public function testStreamingStopsWhenOAuthTokenIsRevoked(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $server->runtime->app->posts->create(new CreatePost('alice', 'hello'));
        $token = $this->issueToken($server, 'alice');
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'public'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );
        $response = (new MastodonStreamingController($server->runtime, 30, 10_000))->handle($request);
        self::assertNotNull($response);

        $chunks = $response->chunks();
        $chunks->rewind();
        self::assertStringContainsString('event: update', $chunks->current());
        $server->runtime->database->execute(
            'UPDATE mastodon_oauth_token SET revoked_at = :revoked_at WHERE access_hash = :access_hash',
            [
                'revoked_at' => Time::format($server->runtime->clock->now()),
                'access_hash' => hash('sha256', $token),
            ],
        );
        $chunks->next();
        self::assertFalse($chunks->valid());
    }

    public function testLastEventIdResumesAfterTheCursorWithoutReplayingIt(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $firstPost = $server->runtime->app->posts->create(new CreatePost('alice', 'first'));
        $broker = $server->runtime->app->eventBroker;
        self::assertNotNull($broker);
        $firstEvent = $broker->read(new StreamSubscription('alice', ['public']), null, 10)[0];
        $secondPost = $server->runtime->app->posts->create(new CreatePost('alice', 'second'));
        $token = $this->issueToken($server, 'alice');
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'public'],
            headers: [
                'Authorization' => 'Bearer ' . $token,
                'Last-Event-ID' => $firstEvent->id,
            ],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );

        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        $frames = iterator_to_array($response->chunks(), false);
        $updates = array_values(array_filter($frames, static fn (string $frame): bool => str_contains($frame, 'event: update')));
        self::assertCount(1, $updates);
        self::assertStringContainsString('"id":"' . $secondPost->id . '"', $updates[0]);
        self::assertStringNotContainsString('"id":"' . $firstPost->id . '"', $updates[0]);
    }

    public function testHomeStreamRendersRemoteStatusesOnlyWhileTheFollowIsAccepted(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $actorId = 'https://peer.example/users/bob';
        $objectId = 'https://peer.example/posts/remote-1';
        $now = $server->runtime->clock->now();
        $server->runtime->database->execute(
            'INSERT INTO social_following (id, local_user_id, actor_id, inbox_url, followed_at, state)'
            . ' VALUES (:id, :local_user_id, :actor_id, :inbox_url, :followed_at, :state)',
            [
                'id' => 'follow-remote-bob',
                'local_user_id' => 'alice',
                'actor_id' => $actorId,
                'inbox_url' => 'https://peer.example/inbox',
                'followed_at' => Time::format($now),
                'state' => 'accepted',
            ],
        );
        (new PdoRemotePostRepository($server->runtime->database))->insert(new RemotePost(
            activityId: 'https://peer.example/activities/remote-1',
            objectId: $objectId,
            actorId: $actorId,
            kind: RemotePostKind::Post,
            content: 'A remote status',
            contentWarning: null,
            publishedAt: $now,
            objectAuthorId: $actorId,
        ));
        $broker = $server->runtime->app->eventBroker;
        self::assertNotNull($broker);
        $broker->publish(new StreamEventDraft(
            name: 'status.created',
            audience: StreamAudience::Followers,
            actorId: $actorId,
            recipientIds: ['alice'],
            payload: ['status_id' => $objectId],
            occurredAt: Time::format($now),
        ));
        $token = $this->issueToken($server, 'alice');
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'home'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );

        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        $frames = iterator_to_array($response->chunks(), false);
        self::assertStringContainsString('event: update', implode('', $frames));
        self::assertStringContainsString($objectId, implode('', $frames));

        $createdEvent = $broker->read(new StreamSubscription('alice', ['home']), null, 10)[0];
        $updatedAt = $now->modify('+1 minute');
        (new PdoRemotePostRepository($server->runtime->database))->updatePostContent(
            $objectId,
            $actorId,
            'An edited remote status',
            null,
            $updatedAt,
        );
        $broker->publish(new StreamEventDraft(
            name: 'status.updated',
            audience: StreamAudience::Followers,
            actorId: $actorId,
            recipientIds: ['alice'],
            payload: ['status_id' => $objectId],
            occurredAt: Time::format($updatedAt),
        ));
        $updateRequest = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'home'],
            headers: [
                'Authorization' => 'Bearer ' . $token,
                'Last-Event-ID' => $createdEvent->id,
            ],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );
        $updateResponse = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($updateRequest);
        self::assertNotNull($updateResponse);
        $updateFrames = iterator_to_array($updateResponse->chunks(), false);
        self::assertCount(1, $updateFrames);
        self::assertStringContainsString('event: update', $updateFrames[0]);
        self::assertStringContainsString('An edited remote status', $updateFrames[0]);

        $server->runtime->database->execute('DELETE FROM social_following WHERE local_user_id = :user_id', ['user_id' => 'alice']);
        $responseAfterUnfollow = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($responseAfterUnfollow);
        $framesAfterUnfollow = iterator_to_array($responseAfterUnfollow->chunks(), false);
        self::assertStringNotContainsString('event: update', implode('', $framesAfterUnfollow));
    }

    public function testNotificationStreamEmitsOnlyWithNotificationScope(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $server->createAccount('bob');
        $server->runtime->app->notifications?->recordFollow('alice', 'bob');
        $token = $this->issueToken($server, 'alice', ['read:notifications']);
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'user:notification'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:notifications'],
        );
        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        $chunks = iterator_to_array($response->chunks(), false);
        self::assertNotEmpty($chunks);
        self::assertStringContainsString("event: notification\ndata: {\"id\":", $chunks[0]);
    }

    public function testUserStreamIncludesNotificationsWhenGrantedNotificationScope(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $server->createAccount('bob');
        $server->runtime->app->notifications?->recordFollow('alice', 'bob');
        $token = $this->issueToken($server, 'alice', ['read:statuses', 'read:notifications']);
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'user'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses', 'read:notifications'],
        );

        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        $chunks = iterator_to_array($response->chunks(), false);
        self::assertNotEmpty($chunks);
        self::assertStringContainsString("event: notification\ndata: {\"id\":", implode('', $chunks));
    }

    public function testNotificationStreamReplaysItemsOlderThanTheLatestFortyNotifications(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $server->createAccount('bob');
        for ($index = 0; $index < 45; $index++) {
            $post = $server->runtime->app->posts->create(new CreatePost('bob', 'Mention target ' . $index));
            $server->runtime->app->notifications?->recordMention('alice', 'bob', $post->id);
        }

        $oldest = $server->runtime->database->fetchAll(
            'SELECT id FROM social_notification WHERE recipient_id = :recipient ORDER BY created_at, id LIMIT 5',
            ['recipient' => 'alice'],
        );
        foreach ($oldest as $row) {
            $server->runtime->database->execute(
                'UPDATE social_notification SET created_at = :created_at WHERE id = :id',
                ['created_at' => '2026-11-01T00:00:00Z', 'id' => $row['id']],
            );
        }

        $token = $this->issueToken($server, 'alice', ['read:notifications']);
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'user:notification'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:notifications'],
        );
        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        $frames = iterator_to_array($response->chunks(), false);

        self::assertSame(45, substr_count(implode('', $frames), 'event: notification'));
    }

    public function testListStreamIsOwnerScopedAndShowsOnlyVisibleMemberStatuses(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $server->createAccount('bob');
        $server->createAccount('carol');
        $this->createList($server, 'alice', 'list-alice', ['bob']);

        $listed = $server->runtime->app->posts->create(new CreatePost('bob', 'Listed public status'));
        $server->runtime->app->posts->create(new CreatePost('carol', 'Unlisted author status'));
        $server->runtime->app->posts->create(new CreatePost(
            'bob',
            'Followers-only status',
            Visibility::Followers,
        ));
        $server->runtime->app->posts->create(new CreatePost(
            'bob',
            'Direct status',
            Visibility::Direct,
            recipientIds: ['alice'],
        ));

        $token = $this->issueToken($server, 'alice', ['read:lists']);
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'list', 'list' => 'list-alice'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:lists'],
        );

        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        $body = implode('', iterator_to_array($response->chunks(), false));
        self::assertStringContainsString($listed->id, $body);
        self::assertStringContainsString('Listed public status', $body);
        self::assertStringNotContainsString('Unlisted author status', $body);
        self::assertStringNotContainsString('Followers-only status', $body);
        self::assertStringNotContainsString('Direct status', $body);

        $otherToken = $this->issueToken($server, 'carol', ['read:lists']);
        $notOwned = (new MastodonStreamingController($server->runtime))->handle(new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'list', 'list' => 'list-alice'],
            headers: ['Authorization' => 'Bearer ' . $otherToken],
            attributes: ['mastodon_oauth' => true],
            userId: 'carol',
            scopes: ['read:lists'],
        ));
        self::assertNotNull($notOwned);
        self::assertSame(404, $notOwned->status);
    }

    public function testListStreamRechecksMembershipBeforeWritingFrames(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $server->createAccount('bob');
        $this->createList($server, 'alice', 'list-alice', ['bob']);
        $server->runtime->app->posts->create(new CreatePost('bob', 'No longer visible after removal'));
        $token = $this->issueToken($server, 'alice', ['read:lists']);
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'list', 'list' => 'list-alice'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:lists'],
        );

        $response = (new MastodonStreamingController($server->runtime, 1, 10_000))->handle($request);
        self::assertNotNull($response);
        $server->runtime->database->execute(
            'DELETE FROM social_account_list_member WHERE list_id = :list_id AND account_id = :account_id',
            ['list_id' => 'list-alice', 'account_id' => 'bob'],
        );

        $body = implode('', iterator_to_array($response->chunks(), false));
        self::assertStringNotContainsString('event: update', $body);
        self::assertStringNotContainsString('No longer visible after removal', $body);
    }

    public function testListStreamRequiresItsSpecificScopeAndAnOwnedList(): void
    {
        $server = new TestServer(['mastodon_api' => true]);
        $server->createAccount('alice');
        $this->createList($server, 'alice', 'list-alice', []);

        $listToken = $this->issueToken($server, 'alice', ['read:lists']);
        $missingList = (new MastodonStreamingController($server->runtime))->handle(new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'list'],
            headers: ['Authorization' => 'Bearer ' . $listToken],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:lists'],
        ));
        self::assertNotNull($missingList);
        self::assertSame(400, $missingList->status);

        $token = $this->issueToken($server, 'alice', ['read:statuses']);
        $request = new Request(
            'GET',
            '/api/mastodon/v1/streaming',
            query: ['stream' => 'list', 'list' => 'list-alice'],
            headers: ['Authorization' => 'Bearer ' . $token],
            attributes: ['mastodon_oauth' => true],
            userId: 'alice',
            scopes: ['read:statuses'],
        );

        $response = (new MastodonStreamingController($server->runtime))->handle($request);
        self::assertNotNull($response);
        self::assertSame(403, $response->status);
    }

    /** @param list<string> $members */
    private function createList(TestServer $server, string $ownerId, string $listId, array $members): void
    {
        $now = Time::format($server->runtime->clock->now());
        $server->runtime->database->execute(
            'INSERT INTO social_account_list'
            . ' (id, user_id, title, replies_policy, exclusive, created_at, updated_at)'
            . " VALUES (:id, :user, 'Test list', 'none', 0, :created, :updated)",
            ['id' => $listId, 'user' => $ownerId, 'created' => $now, 'updated' => $now],
        );
        foreach ($members as $member) {
            $server->runtime->database->execute(
                'INSERT INTO social_account_list_member (list_id, account_id, created_at)'
                . ' VALUES (:list, :account, :created)',
                ['list' => $listId, 'account' => $member, 'created' => $now],
            );
        }
    }

    private function issueToken(TestServer $server, string $accountId, array $scopes = ['read:statuses']): string
    {
        $token = bin2hex(random_bytes(32));
        $now = $server->runtime->clock->now();
        (new MastodonOAuthRepository($server->runtime->database))->createToken(
            hash('sha256', $token),
            hash('sha256', bin2hex(random_bytes(32))),
            str_repeat('c', 64),
            $accountId,
            $scopes,
            Time::format($now),
            Time::format($now->modify('+1 hour')),
            Time::format($now->modify('+2 hours')),
        );

        return $token;
    }
}
