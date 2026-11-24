<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Domain\Atproto\AllowAllAtprotoContent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventKind;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordOperation;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollow;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInboundRepository;
use AlohaSocial\Core\Service\Atproto\AtprotoSyncService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Federation\Atproto\JetstreamEventSource;
use AlohaSocial\Server\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

use function fclose;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function strrpos;
use function substr;
use function usleep;

/**
 * The Jetstream transport against a local relay fixture.
 *
 * A real public relay would make the test slow and flaky, so the fixture
 * implements the same WebSocket handshake and sends the same message shape.
 * That still covers the socket, the frame parser and the event normalizer.
 */
final class JetstreamEventSourceTest extends TestCase
{
    public function testPullReadsFilteredRelayMessagesAndAdvancesTheCursor(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $alice = $server->createAccount('alice');
        $now = Time::format($server->runtime->clock->now());

        $database->execute(
            'INSERT INTO social_following'
            . ' (id, local_user_id, actor_id, inbox_url, followed_at, state, follow_activity_id)'
            . " VALUES ('follow-1', :local, 'did:plc:ap-followed', 'https://remote.example/inbox', :now, 'accepted', NULL)",
            ['local' => $alice->id, 'now' => $now],
        );
        (new PdoAtprotoFollowRepository($database))->save(new AtprotoFollow(
            localUserId: $alice->id,
            accountDid: 'did:plc:localalice',
            subjectDid: 'did:plc:followed',
            rkey: '3kfollow',
            uri: 'at://did:plc:localalice/app.bsky.graph.follow/3kfollow',
            cid: 'bafyfollow',
            state: AtprotoFollow::ACTIVE,
            followedAt: $server->runtime->clock->now(),
        ));
        $database->execute(
            'INSERT INTO social_atproto_publication (local_user_id, account_did, post_id, rkey, uri, published_at)'
            . ' VALUES (:local, :did, :post, :rkey, :uri, :now)',
            [
                'local' => $alice->id,
                'did' => 'did:plc:local',
                'post' => 'post-1',
                'rkey' => '3klocal',
                'uri' => 'at://did:plc:local/app.bsky.feed.post/3klocal',
                'now' => $now,
            ],
        );

        $port = $this->freePort();
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/jetstream-server.php', (string) $port, '3', 'v2', '903'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            // Give the fixture a moment to bind its port.
            usleep(350_000);

            $source = new JetstreamEventSource(
                'ws://127.0.0.1:' . $port . '/subscribe',
                $database,
                $server->runtime->clock,
            );
            $page = $source->pull(null, 10);

            self::assertCount(3, $page->events);
            self::assertSame('903', $page->cursor);

            $post = null;
            $like = null;
            $identity = null;
            foreach ($page->events as $event) {
                if (($event->record['text'] ?? null) === 'Hello from Jetstream') {
                    $post = $event;
                }
                if ($event->collection === 'app.bsky.feed.like') {
                    $like = $event;
                }
                if ($event->kind === AtprotoEventKind::Account) {
                    $identity = $event;
                }
            }

            self::assertNotNull($post, 'The followed post was not delivered.');
            self::assertSame('did:plc:followed', $post->did);
            self::assertSame(900, $post->sequence);
            self::assertSame(intdiv(1_790_000_000_000_001, 1_000_000), $post->receivedAt->getTimestamp());
            self::assertSame(AtprotoRecordOperation::Create, $post->operation);
            self::assertSame('bafypost', $post->cid);
            self::assertSame('app.bsky.feed.post', $post->collection);

            self::assertNotNull($like, 'The like on a local record was not delivered.');
            self::assertSame(901, $like->sequence);
            self::assertSame('at://did:plc:local/app.bsky.feed.post/3klocal', $like->record['subject']['uri'] ?? null);

            self::assertNotNull($identity, 'The identity event was not delivered.');
            self::assertSame(903, $identity->sequence);
            self::assertSame('followed.test', $identity->handle);

            foreach ($page->events as $event) {
                self::assertNotSame('Spam from a stranger', $event->record['text'] ?? null);
            }

            // A replay after a reconnect must not create a second row and must
            // advance the same durable cursor instead of restarting.
            $sync = new AtprotoSyncService(
                $source,
                new PdoAtprotoInboundRepository($database),
                new AllowAllAtprotoContent(),
                $server->runtime->clock,
            );
            $first = $sync->sync(10);
            self::assertSame(3, $first->applied);
            self::assertFalse($first->reconcileRequired(), 'Filtered relay events must not look like missing source events.');

            $second = $sync->sync(10);
            self::assertLessThanOrEqual(1, $second->applied);
            self::assertGreaterThanOrEqual(2, $second->skipped);
            self::assertSame($first->checkpoint->cursor, $second->checkpoint->cursor);

            self::assertSame(1, (int) $database->fetchValue(
                'SELECT COUNT(*) FROM social_atproto_remote_record WHERE uri = :uri',
                ['uri' => 'at://did:plc:followed/app.bsky.feed.post/3kpost'],
            ));
        } finally {
            proc_terminate($process);
            @proc_close($process);
        }
    }

    public function testOutdatedCursorInfoMarksThePageForReconciliation(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $alice = $server->createAccount('alice');
        (new PdoAtprotoFollowRepository($database))->save(new AtprotoFollow(
            localUserId: $alice->id,
            accountDid: 'did:plc:localalice',
            subjectDid: 'did:plc:followed',
            rkey: '3kfollow',
            uri: 'at://did:plc:localalice/app.bsky.graph.follow/3kfollow',
            cid: 'bafyfollow',
            state: AtprotoFollow::ACTIVE,
            followedAt: $server->runtime->clock->now(),
        ));

        $port = $this->freePort();
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/jetstream-server.php', (string) $port, '1', 'info'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            usleep(350_000);
            $source = new JetstreamEventSource(
                'ws://127.0.0.1:' . $port . '/subscribe',
                $database,
                $server->runtime->clock,
            );
            $page = $source->pull(null, 10);

            self::assertTrue($page->gapDetected);
            self::assertSame('903', $page->cursor);
        } finally {
            proc_terminate($process);
            @proc_close($process);
        }
    }

    public function testPullFallsBackToLegacyTimestampWhenSequenceCursorIsMissing(): void
    {
        $server = new TestServer();
        $database = $server->runtime->database;
        $alice = $server->createAccount('alice');
        $now = Time::format($server->runtime->clock->now());
        $database->execute(
            'INSERT INTO social_following'
            . ' (id, local_user_id, actor_id, inbox_url, followed_at, state, follow_activity_id)'
            . " VALUES ('follow-1', :local, 'did:plc:ap-followed', 'https://remote.example/inbox', :now, 'accepted', NULL)",
            ['local' => $alice->id, 'now' => $now],
        );
        (new PdoAtprotoFollowRepository($database))->save(new AtprotoFollow(
            localUserId: $alice->id,
            accountDid: 'did:plc:localalice',
            subjectDid: 'did:plc:followed',
            rkey: '3kfollow',
            uri: 'at://did:plc:localalice/app.bsky.graph.follow/3kfollow',
            cid: 'bafyfollow',
            state: AtprotoFollow::ACTIVE,
            followedAt: $server->runtime->clock->now(),
        ));

        $port = $this->freePort();
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/jetstream-server.php', (string) $port, '1', 'legacy'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            usleep(350_000);
            $source = new JetstreamEventSource(
                'ws://127.0.0.1:' . $port . '/subscribe',
                $database,
                $server->runtime->clock,
            );
            $page = $source->pull(null, 10);

            self::assertSame('1700000000000004', $page->cursor);
            self::assertCount(2, $page->events);
            self::assertNull($page->events[0]->sequence);
            self::assertSame(1_700_000_000, $page->events[0]->receivedAt->getTimestamp());
        } finally {
            proc_terminate($process);
            @proc_close($process);
        }
    }

    private function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket);

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
