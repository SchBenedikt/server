<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Tests;

use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Service\Federation\DeliveryFailedException;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Server\Federation\ActivityPubRequestSigner;
use AlohaSocial\Server\Federation\FederationHttpDiagnostics;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Server\Federation\RemoteDirectory;
use AlohaSocial\Server\Federation\RemoteDirectoryException;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Server\Tests\Support\FakeBoundedHttpClient;
use AlohaSocial\Server\Tests\Support\StrictActivityPubPeer;
use AlohaSocial\Server\Tests\Support\TestServer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

use function array_change_key_case;
use function array_map;

use const CASE_LOWER;

final class SignedFederationDeliveryTest extends TestCase
{
    public function testSignedGetIsValidAndCarriesTheActorInbox(): void
    {
        $key = RsaKeyPair::generate();
        $activities = new ActivityFactory(new ArrayConfig([
            'social' => ['instance.url' => 'https://app.test'],
        ]));
        $signer = new ActivityPubRequestSigner($key, $activities, new SystemClock(), 'alice');

        $http = new class () implements BoundedHttpClient {
            /** @var array<string, string> */
            public array $headers = [];

            public function request(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
            ): HttpResponse {
                return $this->requestBounded($method, $url, $headers, $body, $timeoutSeconds);
            }

            public function requestBounded(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
                int $maxBytes = 1_048_576,
                array $resolvedIps = [],
            ): HttpResponse {
                $this->headers = $headers;

                return new HttpResponse(200, ['content-type' => 'application/json'], json_encode([
                    'id' => 'https://peer.test/users/carol',
                    'inbox' => 'https://peer.test/users/carol/inbox',
                ], JSON_THROW_ON_ERROR));
            }
        };

        $directory = new RemoteDirectory($http, new FederationTargetGuard(), 'mastodon.social', $signer);

        self::assertSame('https://peer.test/users/carol/inbox', $directory->resolveActorInbox('https://peer.test/users/carol'));
        self::assertArrayHasKey('Signature', $http->headers);
        self::assertArrayHasKey('Host', $http->headers);
        self::assertArrayHasKey('Date', $http->headers);

        $lower = array_change_key_case($http->headers, CASE_LOWER);
        HttpSignature::verify(
            $key->publicKeyPem(),
            'GET',
            '/users/carol',
            $lower,
            '',
            300,
            new DateTimeImmutable('now'),
        );
    }

    public function testDefaultPortIsNotPartOfTheSignedHost(): void
    {
        $key = RsaKeyPair::generate();
        $activities = new ActivityFactory(new ArrayConfig([
            'social' => ['instance.url' => 'https://app.test'],
        ]));
        $signer = new ActivityPubRequestSigner($key, $activities, new SystemClock(), 'alice');

        $headers = $signer('GET', 'https://peer.test:443/users/carol');
        self::assertSame('peer.test', $headers['Host']);

        HttpSignature::verify(
            $key->publicKeyPem(),
            'GET',
            '/users/carol',
            array_change_key_case($headers, CASE_LOWER),
            '',
            300,
            new DateTimeImmutable('now'),
        );
    }

    public function testAuthorizedFetchRefusalIsVisibleInsteadOfSilentlySkipped(): void
    {
        $key = RsaKeyPair::generate();
        $activities = new ActivityFactory(new ArrayConfig([
            'social' => ['instance.url' => 'https://app.test'],
        ]));
        $signer = new ActivityPubRequestSigner($key, $activities, new SystemClock(), 'alice');

        $http = new FakeBoundedHttpClient();
        $http->json('https://peer.test/users/carol', [
            'error' => 'authorized_fetch_required',
            'message' => 'This resource requires an HTTP signature.',
        ], 401);

        $directory = new RemoteDirectory($http, new FederationTargetGuard(), 'mastodon.social', $signer);

        $this->expectException(RemoteDirectoryException::class);
        $this->expectExceptionMessage('HTTP 401 (authorized_fetch_required: This resource requires an HTTP signature.).');
        $directory->resolveActorInbox('https://peer.test/users/carol');
    }

    public function testProviderSignsWithTheFirstExistingAccount(): void
    {
        $key = RsaKeyPair::generate();
        $activities = new ActivityFactory(new ArrayConfig([
            'social' => ['instance.url' => 'https://app.test'],
        ]));
        $signer = new ActivityPubRequestSigner(
            $key,
            $activities,
            new SystemClock(),
            static fn (): ?string => 'later-account',
        );

        $http = new class () implements BoundedHttpClient {
            /** @var array<string, string> */
            public array $headers = [];

            public function request(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
            ): HttpResponse {
                return $this->requestBounded($method, $url, $headers, $body, $timeoutSeconds);
            }

            public function requestBounded(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
                int $maxBytes = 1_048_576,
                array $resolvedIps = [],
            ): HttpResponse {
                $this->headers = $headers;

                return new HttpResponse(200, ['content-type' => 'application/json'], '{"inbox":"https://peer.test/inbox"}');
            }
        };

        $directory = new RemoteDirectory($http, new FederationTargetGuard(), 'mastodon.social', $signer);
        $directory->resolveActorInbox('https://peer.test/users/carol');

        self::assertStringContainsString(
            'keyId="https://app.test/@later-account#main-key"',
            $http->headers['Signature'],
        );
    }

    public function testDeliveryHandlerSignsThePostAndReportsAPeerRefusal(): void
    {
        $key = RsaKeyPair::generate();
        $peer = new class ($key->publicKeyPem()) implements HttpClient {
            /** @var array<string, string> */
            public array $headers = [];

            public string $body = '';

            public function __construct(private readonly string $publicKeyPem)
            {
            }

            public function request(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
            ): HttpResponse {
                $this->headers = $headers;
                $this->body = $body;
                $lower = array_change_key_case($headers, CASE_LOWER);

                try {
                    HttpSignature::verify(
                        $this->publicKeyPem,
                        'POST',
                        '/users/carol/inbox',
                        $lower,
                        $body,
                        300,
                        new DateTimeImmutable('now'),
                    );
                } catch (\Throwable $exception) {
                    return new HttpResponse(401, [], json_encode([
                        'error' => 'invalid_signature',
                        'message' => $exception->getMessage(),
                    ], JSON_THROW_ON_ERROR));
                }

                return new HttpResponse(202);
            }
        };

        $handler = new FederationDeliveryHandler($peer, $key, new SystemClock());
        $job = new Job(
            'job-1',
            'federation.deliver',
            [
                'inbox' => 'https://peer.test/users/carol/inbox',
                'key_id' => 'https://app.test/@alice#main-key',
                'activity' => [
                    '@context' => 'https://www.w3.org/ns/activitystreams',
                    'id' => 'https://app.test/@alice#likes/1',
                    'type' => 'Like',
                    'actor' => 'https://app.test/@alice',
                    'object' => 'https://peer.test/users/carol/statuses/2',
                ],
            ],
            new DateTimeImmutable('now'),
        );

        $handler->handle($job);

        self::assertSame('application/activity+json', $peer->headers['Content-Type']);
        self::assertStringContainsString('"type":"Like"', $peer->body);
        self::assertArrayHasKey('Signature', $peer->headers);
        self::assertArrayHasKey('Digest', $peer->headers);

        $refusingPeer = new class () implements HttpClient {
            public function request(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
            ): HttpResponse {
                return new HttpResponse(422, [], '{"error":"invalid_object","message":"The Like does not target the note."}');
            }
        };

        try {
            (new FederationDeliveryHandler($refusingPeer, $key, new SystemClock()))->handle($job);
            self::fail('A non-2xx delivery must fail the job.');
        } catch (DeliveryFailedException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('HTTP 422', $exception->getMessage());
        }
    }

    public function testAFailedDeliveryLogsThePeersOwnMessage(): void
    {
        $inner = new class () implements BoundedHttpClient {
            public function request(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
            ): HttpResponse {
                return $this->requestBounded($method, $url, $headers, $body, $timeoutSeconds);
            }

            public function requestBounded(
                string $method,
                string $url,
                array $headers = [],
                string $body = '',
                int $timeoutSeconds = 10,
                int $maxBytes = 1_048_576,
                array $resolvedIps = [],
            ): HttpResponse {
                return new HttpResponse(422, [], json_encode([
                    'error' => 'invalid_object',
                    'message' => 'The Like does not target the note.',
                ], JSON_THROW_ON_ERROR));
            }
        };
        $logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        (new FederationHttpDiagnostics($inner, $logger))->request(
            'POST',
            'https://peer.test/users/carol/inbox',
            [],
            '{"type":"Like"}',
        );

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(422, $logger->records[0]['context']['status']);
        self::assertStringContainsString('invalid_object: The Like does not target the note.', $logger->records[0]['context']['peer_message']);
    }

    public function testLikeAndReplyReachAStrictPeerThroughTheRealWorker(): void
    {
        $peer = new StrictActivityPubPeer();
        $resolver = new class () implements HostResolver {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $server = new TestServer([], $peer, $resolver);
        $server->createAccount('alice');
        $alice = $server->signIn('alice');

        $actorDocument = $server->send('GET', '/@alice', [
            'headers' => ['Accept' => 'application/activity+json'],
        ]);
        self::assertSame(200, $actorDocument->status);
        /** @var array{publicKey: array{publicKeyPem: string}} $actor */
        $actor = json_decode($actorDocument->body, true, 512, JSON_THROW_ON_ERROR);
        $peer->setPublicKeyPem($actor['publicKey']['publicKeyPem']);

        $objectId = StrictActivityPubPeer::NOTE;
        $like = $server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($objectId) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $like->status, $like->body);

        // A retried like is idempotent and must not enqueue a second delivery.
        $duplicate = $server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode($objectId) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );
        self::assertSame(200, $duplicate->status, $duplicate->body);
        self::assertSame(1, (int) $server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_job WHERE type = 'federation.deliver' AND dead_at IS NULL",
        ));

        $interactions = $server->send('GET', '/api/v1/host/interactions', [
            'cookies' => $alice['cookies'],
            'query' => ['ids' => $objectId],
        ]);
        self::assertSame(200, $interactions->status, $interactions->body);
        /** @var array{interactions: array<string, array{favourites_count: int, favourited: bool}>} $interactionBody */
        $interactionBody = json_decode($interactions->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($interactionBody['interactions'][$objectId]['favourited']);
        self::assertSame(1, $interactionBody['interactions'][$objectId]['favourites_count']);

        $reply = $server->json('POST', '/api/v1/posts', [
            'content' => 'A signed reply to the remote post.',
            'visibility' => 'public',
            'in_reply_to_id' => $objectId,
        ], $alice['cookies'], $alice['csrf']);
        self::assertSame(201, $reply->status, $reply->body);
        /** @var array{post: array{id: string}} $replyBody */
        $replyBody = json_decode($reply->body, true, 512, JSON_THROW_ON_ERROR);
        $replyId = $replyBody['post']['id'];

        // The remote origin does not know this reply. It must still be part of
        // the conversation after a reload, not only in the author's profile.
        $thread = $server->send('GET', '/api/v1/host/remote-post', [
            'cookies' => $alice['cookies'],
            'query' => ['id' => $objectId],
        ]);
        self::assertSame(200, $thread->status, $thread->body);
        /** @var array{post: array{replies_count: int}, replies: list<array{id: string, content: string}>} $threadBody */
        $threadBody = json_decode($thread->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertContains($replyId, array_column($threadBody['replies'], 'id'));
        self::assertGreaterThanOrEqual(1, $threadBody['post']['replies_count']);

        $handled = $server->runtime->app->worker->runOnce(50);
        self::assertGreaterThanOrEqual(2, $handled);

        $types = array_map(
            static fn (array $activity): string => (string) $activity['type'],
            $peer->accepted,
        );
        self::assertContains('Like', $types);
        self::assertContains('Create', $types);

        $create = null;
        foreach ($peer->accepted as $activity) {
            if (($activity['type'] ?? null) === 'Create') {
                $create = $activity;
            }
        }
        self::assertIsArray($create);
        self::assertSame($objectId, $create['object']['inReplyTo'] ?? null);
        self::assertStringContainsString('A signed reply to the remote post.', (string) ($create['object']['content'] ?? ''));

        $deadDeliveries = (int) $server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_job WHERE type = 'federation.deliver' AND dead_at IS NOT NULL",
        );
        $openDeliveries = (int) $server->runtime->database->fetchValue(
            "SELECT COUNT(*) FROM social_job WHERE type = 'federation.deliver' AND dead_at IS NULL",
        );
        self::assertSame(0, $deadDeliveries);
        self::assertSame(0, $openDeliveries);

        $signedGets = 0;
        foreach ($peer->requests as $request) {
            if ($request['method'] === 'GET' && $request['status'] < 400) {
                self::assertArrayHasKey('signature', $request['headers']);
                $signedGets++;
            }
        }
        self::assertGreaterThanOrEqual(2, $signedGets);
    }

    public function testAnUnreadableRemotePostReportsThePeerRefusal(): void
    {
        $http = new FakeBoundedHttpClient();
        $http->json(StrictActivityPubPeer::NOTE, [
            'error' => 'authorized_fetch_required',
            'message' => 'This resource requires an HTTP signature.',
        ], 401);

        $resolver = new class () implements HostResolver {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $server = new TestServer([], $http, $resolver);
        $server->createAccount('alice');
        $alice = $server->signIn('alice');

        $like = $server->json(
            'POST',
            '/api/v1/posts/' . rawurlencode(StrictActivityPubPeer::NOTE) . '/like',
            [],
            $alice['cookies'],
            $alice['csrf'],
        );

        self::assertSame(502, $like->status, $like->body);
        /** @var array{error: array{code: string, message: string}} $body */
        $body = json_decode($like->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('remote_post_unreachable', $body['error']['code']);
        self::assertStringContainsString('authorized_fetch_required', $body['error']['message']);
    }
}
