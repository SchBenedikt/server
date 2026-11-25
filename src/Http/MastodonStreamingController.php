<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\PdoFollowerRepository;
use AlohaSocial\Core\Domain\Federation\PdoMuteRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Events\EventBroker;
use AlohaSocial\Core\Platform\Events\StreamEvent;
use AlohaSocial\Core\Platform\Events\StreamSubscription;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Auth\MastodonOAuthRepository;
use AlohaSocial\Server\Bootstrap\Runtime;
use Throwable;

/** Authenticated, bounded SSE transport for the supported Mastodon channels. */
final class MastodonStreamingController
{
    private const STREAMS = ['home', 'public', 'public:local', 'user', 'user:notification', 'direct'];

    private readonly MastodonOAuthRepository $oauth;

    private readonly PdoFollowerRepository $followers;

    private readonly PdoMuteRepository $mutes;

    private readonly ActivityFactory $activities;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly int $maxDurationSeconds = 25,
        private readonly int $pollIntervalMicroseconds = 1_000_000,
    ) {
        $this->oauth = new MastodonOAuthRepository($runtime->database);
        $this->followers = new PdoFollowerRepository($runtime->database);
        $this->mutes = new PdoMuteRepository($runtime->database);
        $this->activities = new ActivityFactory($runtime->config->coreConfig());
    }

    public function handle(Request $request): ?Response
    {
        if (!$this->matches($request->path)) {
            return null;
        }
        if ($request->method !== 'GET') {
            return $this->error(405, 'method_not_allowed', 'Use GET to open an event stream.');
        }
        if (!$this->runtime->config->mastodonApi) {
            return $this->error(503, 'mastodon_api_disabled', 'Mastodon-compatible streaming is disabled.');
        }

        $userId = $request->userId;
        if ($userId === null || ($request->attribute('mastodon_oauth') ?? false) !== true) {
            return $this->error(401, 'unauthorized', 'A valid Mastodon OAuth bearer token is required.');
        }
        $stream = $request->queryParam('stream', 'user');
        if (!in_array($stream, self::STREAMS, true)) {
            return $this->error(422, 'unsupported_stream', 'This Mastodon streaming channel is not supported.');
        }
        $notificationStream = $stream === 'user:notification';
        $requiredScope = $notificationStream ? 'read:notifications' : 'read:statuses';
        if (!in_array('read', $request->scopes, true) && !in_array($requiredScope, $request->scopes, true)) {
            return $this->error(403, 'insufficient_scope', 'The access token needs ' . $requiredScope . '.');
        }

        $broker = $this->runtime->app->eventBroker;
        if (!$broker instanceof EventBroker) {
            return $this->error(503, 'stream_unavailable', 'The durable event broker is not configured.');
        }
        $cursor = trim((string) ($request->header('last-event-id') ?? $request->queryParam('last_event_id', '')));
        if ($cursor !== '' && preg_match('/^[a-f0-9]{24}$/D', $cursor) !== 1) {
            return $this->error(400, 'invalid_cursor', 'The Last-Event-ID cursor is invalid.');
        }

        try {
            $channels = [$stream];
            if ($stream === 'user'
                && (in_array('read', $request->scopes, true) || in_array('read:notifications', $request->scopes, true))) {
                $channels[] = 'user:notification';
            }
            $subscription = new StreamSubscription($userId, $channels);
            $initial = $broker->read($subscription, $cursor === '' ? null : $cursor, 100);
        } catch (\InvalidArgumentException) {
            return $this->error(410, 'cursor_expired', 'The stream cursor is no longer available; resync over REST.');
        } catch (Throwable) {
            return $this->error(503, 'stream_unavailable', 'The event broker could not open this stream.');
        }

        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->error(401, 'unauthorized', 'A valid Mastodon OAuth bearer token is required.');
        }

        return Response::stream(
            fn (): iterable => $this->frames($broker, $subscription, $initial, $cursor, $token, $userId, $stream),
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /** @param list<StreamEvent> $initial @return iterable<string> */
    private function frames(
        EventBroker $broker,
        StreamSubscription $subscription,
        array $initial,
        string $cursor,
        string $token,
        string $userId,
        string $stream,
    ): iterable {
        $deadline = hrtime(true) + max(1, $this->maxDurationSeconds) * 1_000_000_000;
        $lastHeartbeat = hrtime(true);
        $pending = $initial;

        while (!connection_aborted() && hrtime(true) < $deadline) {
            if (!$this->tokenIsLive($token, $userId, $stream)) {
                return;
            }

            try {
                $events = $pending !== [] ? $pending : $broker->read(
                    $subscription,
                    $cursor === '' ? null : $cursor,
                    100,
                );
            } catch (Throwable) {
                return;
            }
            $pending = [];

            foreach ($events as $event) {
                $cursor = $event->id;
                if ($event->name === 'notification.created') {
                    if ($stream === 'user:notification' || ($stream === 'user' && $this->tokenHasScope($token, 'read:notifications'))) {
                        $notificationId = $event->payload['notification_id'] ?? null;
                        $notification = is_string($notificationId)
                            ? $this->notificationFor($userId, $notificationId)
                            : null;
                        if (is_array($notification)) {
                            try {
                                $json = json_encode($notification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                                yield 'id: ' . $this->sseField($event->id) . "\nevent: notification\ndata: " . $json . "\n\n";
                                continue;
                            } catch (Throwable) {
                            }
                        }
                    }
                    yield 'id: ' . $this->sseField($event->id) . "\n\n";
                    continue;
                }
                $statusId = $event->payload['status_id'] ?? null;
                if (!is_string($statusId) || !$this->eligibleForStream($event, $userId, $stream)) {
                    yield 'id: ' . $this->sseField($event->id) . "\n\n";
                    continue;
                }

                if ($event->name === 'status.deleted') {
                    try {
                        $json = json_encode($statusId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        yield 'id: ' . $this->sseField($event->id) . "\nevent: delete\ndata: " . $json . "\n\n";
                    } catch (Throwable) {
                        yield 'id: ' . $this->sseField($event->id) . "\n\n";
                    }
                    continue;
                }

                try {
                    $status = $this->runtime->app->mastodon?->status($statusId, $userId);
                } catch (PostNotFoundException|MastodonRequestException) {
                    $status = null;
                }
                if (!is_array($status)) {
                    yield 'id: ' . $this->sseField($event->id) . "\n\n";
                    continue;
                }

                try {
                    $json = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    yield 'id: ' . $this->sseField($event->id) . "\n\n";
                    continue;
                }
                yield 'id: ' . $this->sseField($event->id) . "\nevent: update\ndata: " . $json . "\n\n";
            }

            if ($events !== []) {
                continue;
            }
            if (hrtime(true) - $lastHeartbeat >= 10_000_000_000) {
                $lastHeartbeat = hrtime(true);
                yield ": keepalive\n\n";
            }
            usleep(max(10_000, $this->pollIntervalMicroseconds));
        }
    }

    private function eligibleForStream(StreamEvent $event, string $userId, string $stream): bool
    {
        if ($stream === 'home' && $event->actorId !== $userId) {
            if (!in_array($userId, $event->recipientIds, true)) {
                return false;
            }

            $remoteActor = str_starts_with($event->actorId, 'https://')
                || str_starts_with($event->actorId, 'http://');
            if ($remoteActor) {
                if ($this->runtime->app->following->find($userId, $event->actorId)?->state !== FollowState::Accepted) {
                    return false;
                }
                $authorActorId = $event->actorId;
            } else {
                $authorActorId = $this->activities->actorId($event->actorId);
                $followerActorId = $this->activities->actorId($userId);
                if ($this->followers->findByActor($event->actorId, $followerActorId)?->local !== true) {
                    return false;
                }
            }
            if ($this->runtime->app->blocks->isBlocked($userId, $authorActorId)) {
                return false;
            }
            $mute = $this->mutes->find($userId, $authorActorId);

            return $mute === null
                || ($mute->expiresAt !== null && $mute->expiresAt <= $this->runtime->clock->now());
        }
        if ($stream === 'user') {
            return $event->actorId === $userId;
        }
        if ($stream === 'direct') {
            return $event->audience->value === 'direct' && in_array($userId, $event->recipientIds, true);
        }

        return $stream === 'public' || $stream === 'public:local'
            ? $event->audience->value === 'public'
            : $event->actorId === $userId || in_array($userId, $event->recipientIds, true);
    }

    private function tokenIsLive(string $token, string $userId, string $stream): bool
    {
        $grant = $this->oauth->accountForAccess(
            hash('sha256', $token),
            Time::format($this->runtime->clock->now()),
        );
        if ($grant === null || $grant['account_id'] !== $userId) {
            return false;
        }
        $account = $this->runtime->accounts->find($userId);

        return $account !== null
            && !$account->isDisabled()
            && $this->runtime->app->accountData?->deletionStatus($userId)->blockedStatus() === null
            && (in_array('read', $grant['scopes'], true)
                || in_array('read:statuses', $grant['scopes'], true)
                || ($stream === 'user:notification' && in_array('read:notifications', $grant['scopes'], true)));
    }

    private function tokenHasScope(string $token, string $scope): bool
    {
        $grant = $this->oauth->accountForAccess(
            hash('sha256', $token),
            Time::format($this->runtime->clock->now()),
        );

        return $grant !== null
            && (in_array('read', $grant['scopes'], true) || in_array($scope, $grant['scopes'], true));
    }

    /** @return array<string, mixed>|null */
    private function notificationFor(string $userId, string $notificationId): ?array
    {
        foreach ($this->runtime->app->mastodon?->notifications($userId, 40, null, null, null) ?? [] as $notification) {
            if (($notification['id'] ?? null) === $notificationId) {
                return $notification;
            }
        }

        return null;
    }

    private function matches(string $path): bool
    {
        return $path === rtrim($this->runtime->config->mastodonPrefix, '/') . '/streaming'
            || $path === '/api/v1/streaming';
    }

    private function bearerToken(Request $request): ?string
    {
        $authorization = trim((string) $request->header('authorization'));

        return preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,128})$/i', $authorization, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function sseField(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function error(int $status, string $code, string $message): Response
    {
        return Response::json(['error' => $message, 'error_code' => $code], $status);
    }
}
