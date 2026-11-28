<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Service\Atproto\AtprotoFollowException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Atproto\AtprotoAccountNotConnectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoNotConfiguredException;
use AlohaSocial\Core\Service\Atproto\AtprotoPostRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Accounts\AccountErasureCleanup;
use AlohaSocial\Server\Community\CommunityException;
use AlohaSocial\Server\Community\Exports;
use AlohaSocial\Server\Federation\BlueskyException;
use AlohaSocial\Server\Federation\Atproto\AtprotoPublicationDeliveryStatus;
use AlohaSocial\Server\Federation\ActivityPubDeliveryStatus;
use Throwable;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function preg_match;
use function rawurldecode;
use function trim;

/**
 * First-party community routes: announcements, featured tags, trends,
 * memories, stories, collections and post revisions.
 *
 * GET routes are public unless they read the viewer's own data. Mutations are
 * authenticated and pass through the front controller's CSRF check before
 * this class runs, exactly like the admin API.
 */
final class CommunityApiController
{
    public function __construct(
        private readonly Runtime $runtime,
    ) {
    }

    public function handle(Request $request, ?AuthenticatedSession $session, ?string $ip = null): ?Response
    {
        $path = $request->path;
        $method = $request->method;
        $userId = $session?->userId();
        $isAdmin = $session?->account?->isAdmin() === true;

        try {
            if (preg_match('#^/api/v1/host/posts/([^/]+)/activitypub-delivery(?:/(retry))?$#', $path, $matches) === 1
                && in_array($method, ['GET', 'POST'], true)
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to view delivery status.');
                }
                $retryAfter = $this->runtime->rateLimiter->attempt(
                    'host.activitypub_delivery.' . strtolower($method),
                    $userId,
                    $method === 'POST' ? 10 : 60,
                    60,
                );
                if ($retryAfter !== null) {
                    return Response::json([
                        'error' => ['code' => 'rate_limited', 'message' => 'Wait before checking or retrying delivery again.'],
                    ], 429, ['Retry-After' => (string) $retryAfter]);
                }
                $postId = rawurldecode($matches[1]);
                try {
                    $post = $this->runtime->app->posts->get($postId);
                } catch (PostNotFoundException) {
                    return $this->error(404, 'not_found', 'This post is not available.');
                }
                if ($post->authorId !== $userId) {
                    return $this->error(404, 'not_found', 'This post is not available.');
                }
                $delivery = new ActivityPubDeliveryStatus(
                    $this->runtime->database,
                    new ActivityFactory($this->runtime->config->coreConfig()),
                    $this->runtime->clock,
                );
                if ($method === 'POST' && ($matches[2] ?? '') === 'retry') {
                    $retried = $delivery->retry($userId, $postId);

                    return Response::json(['retried' => $retried] + $delivery->forPost($userId, $postId));
                }
                if ($method === 'GET' && ($matches[2] ?? '') === '') {
                    return Response::json($delivery->forPost($userId, $postId));
                }
            }

            if ($method === 'GET' && $path === '/api/v1/host/kokua') {
                return Response::json($this->runtime->kokua->page(
                    $userId,
                    trim((string) ($request->queryParam('kind') ?? 'all')),
                    trim((string) ($request->queryParam('category') ?? 'all')),
                    trim((string) ($request->queryParam('state') ?? 'open')),
                    $this->intParam($request, 'limit', 30, 1, 50),
                    $this->intParam($request, 'offset', 0, 0, 10_000),
                    $isAdmin,
                ));
            }

            if ($method === 'POST' && $path === '/api/v1/host/kokua') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in to ask for or offer help.');
                }

                return Response::json([
                    'kokua' => $this->runtime->kokua->create($userId, $this->jsonBody($request)),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/kokua/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
                $entry = $this->runtime->kokua->find(rawurldecode($matches[1]), $userId, $isAdmin);
                if ($entry === null) {
                    return $this->error(404, 'not_found', 'This Kokua does not exist.');
                }

                return Response::json(['kokua' => $entry]);
            }

            if (preg_match('#^/api/v1/host/kokua/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'kokua' => $this->runtime->kokua->setStatus(
                        rawurldecode($matches[1]),
                        $userId,
                        $body['status'] ?? null,
                    ),
                ]);
            }

            if (preg_match('#^/api/v1/host/kokua/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $this->runtime->kokua->delete(rawurldecode($matches[1]), $userId);

                return Response::json(['deleted' => true]);
            }

            if (preg_match('#^/api/v1/host/kokua/([^/]+)/help$#', $path, $matches) === 1 && $method === 'POST') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in to offer help.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'kokua' => $this->runtime->kokua->help(
                        rawurldecode($matches[1]),
                        $userId,
                        $body['message'] ?? null,
                    ),
                ]);
            }

            if (preg_match('#^/api/v1/host/kokua/([^/]+)/help$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'kokua' => $this->runtime->kokua->withdrawHelp(rawurldecode($matches[1]), $userId),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/announcements') {
                if ($request->queryParam('all') === '1') {
                    if (!$isAdmin) {
                        return $this->error(403, 'admin_required', 'This list is reserved for the administration.');
                    }

                    return Response::json(['announcements' => $this->runtime->announcements->all()]);
                }

                return Response::json(['announcements' => $this->runtime->announcements->active()]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/announcements') {
                if (!$isAdmin) {
                    return $this->error(403, 'admin_required', 'Only the administration can write announcements.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'announcement' => $this->runtime->announcements->create(
                        (string) $userId,
                        $body['content'] ?? null,
                        $body['expires_at'] ?? null,
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/announcements/([^/]+)$#', $path, $matches) === 1
                && in_array($method, ['PATCH', 'DELETE'], true)
            ) {
                if (!$isAdmin) {
                    return $this->error(403, 'admin_required', 'Only the administration can change announcements.');
                }

                $id = rawurldecode($matches[1]);
                if ($method === 'DELETE') {
                    $this->runtime->announcements->delete($id);

                    return Response::json(['deleted' => true]);
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'announcement' => $this->runtime->announcements->update(
                        $id,
                        $body['content'] ?? null,
                        $body['active'] ?? null,
                        $body['expires_at'] ?? null,
                    ),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/pages') {
                return Response::json(['pages' => $this->runtime->instancePages->published()]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/push/vapid-key') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to use push notifications.');
                }

                return Response::json(['key' => $this->runtime->pushSubscriptions->vapidPublicKey()]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/push/subscriptions') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to manage push subscriptions.');
                }

                return Response::json(['subscriptions' => $this->runtime->pushSubscriptions->subscriptionsFor($userId)]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/push/subscriptions') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to use push notifications.');
                }

                $body = $this->jsonBody($request);
                $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];

                return Response::json([
                    'subscription' => $this->runtime->pushSubscriptions->subscribe(
                        $userId,
                        $body['endpoint'] ?? null,
                        $keys['p256dh'] ?? null,
                        $keys['auth'] ?? null,
                    ),
                ], 201);
            }

            if ($method === 'DELETE' && $path === '/api/v1/host/push/subscriptions') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to manage push subscriptions.');
                }

                $body = $this->jsonBody($request);
                $removed = $this->runtime->pushSubscriptions->unsubscribe(
                    $userId,
                    $body['endpoint'] ?? null,
                );

                return Response::json(['removed' => $removed]);
            }

            if ($method === 'GET' && preg_match('#^/api/v1/host/pages/([^/]+)$#', $path, $matches) === 1) {
                $page = $this->runtime->instancePages->find(rawurldecode($matches[1]));
                if ($page === null) {
                    return $this->error(404, 'not_found', 'This page does not exist.');
                }

                return Response::json(['page' => $page]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/featured-tags') {
                $account = $this->account($request, $session, 'account');
                if ($account === null) {
                    return $this->error(404, 'account_not_found', 'This profile does not exist.');
                }

                return Response::json(['tags' => $this->runtime->featuredTags->forAccount($account->id)]);
            }

            if ($method === 'PUT' && $path === '/api/v1/host/featured-tags') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);
                $tags = $body['tags'] ?? [];
                if (!is_array($tags)) {
                    return $this->error(422, 'validation_failed', 'Hashtags must be a list.');
                }

                return Response::json([
                    'tags' => $this->runtime->featuredTags->replace($userId, $tags),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/trends') {
                $this->limit($request, $ip, $session);

                return Response::json($this->runtime->trends->trends(
                    $this->intParam($request, 'days', 7, 1, 30),
                    $this->intParam($request, 'limit', 10, 1, 25),
                ));
            }

            if ($method === 'GET' && $path === '/api/v1/host/memories') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your memories.');
                }

                return Response::json($this->runtime->memories->forAccount($userId));
            }

            if ($method === 'GET' && $path === '/api/v1/host/following') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see the accounts you follow.');
                }

                $rows = $this->runtime->database->fetchAll(
                    'SELECT id, actor_id, inbox_url, followed_at, state FROM social_following'
                    . ' WHERE local_user_id = :local_user_id ORDER BY followed_at DESC, id DESC',
                    ['local_user_id' => $userId],
                );

                return Response::json([
                    'following' => array_map(
                        static fn (array $row): array => [
                            'id' => (string) $row['id'],
                            'actor' => (string) $row['actor_id'],
                            'inbox' => (string) $row['inbox_url'],
                            'followed_at' => (string) $row['followed_at'],
                            'state' => (string) $row['state'],
                        ],
                        $rows,
                    ),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/stories') {
                $this->limit($request, $ip, $session);
                if ($userId === null) {
                    return Response::json(['stories' => [], 'groups' => []]);
                }

                return Response::json($this->runtime->stories->active($userId));
            }

            if ($method === 'POST' && $path === '/api/v1/host/stories') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'story' => $this->runtime->stories->create(
                        $userId,
                        $body['content'] ?? null,
                        $body['background'] ?? null,
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/stories/([^/]+)/background$#', $path, $matches) === 1 && $method === 'GET') {
                $story = $this->runtime->stories->find(rawurldecode($matches[1]));
                if ($story === null) {
                    return $this->error(404, 'not_found', 'This story no longer exists.');
                }

                return new Response(200, [
                    'Content-Type' => 'image/svg+xml; charset=utf-8',
                    'Cache-Control' => 'private, max-age=3600',
                ], $this->runtime->stories->backgroundSvg((string) $story['background']));
            }

            if (preg_match('#^/api/v1/host/stories/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $this->runtime->stories->delete(rawurldecode($matches[1]), $userId);

                return Response::json(['deleted' => true]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/stories/seen') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);
                $ids = is_array($body['ids'] ?? null)
                    ? array_values(array_filter($body['ids'], 'is_string'))
                    : [];

                return Response::json([
                    'seen' => $this->runtime->stories->markSeen($userId, $ids),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/starter-packs') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see starter packs.');
                }

                return Response::json([
                    'mine' => $this->runtime->starterPacks->forAccount($userId),
                    'discover' => $this->runtime->starterPacks->discover($userId),
                ]);
            }

            if (preg_match('#^/api/v1/host/starter-packs/([^/]+)/members/([^/]+)$#', $path, $matches) === 1
                && $method === 'DELETE'
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'starter_pack' => $this->runtime->starterPacks->removeMember(
                        rawurldecode($matches[1]),
                        $userId,
                        rawurldecode($matches[2]),
                    ),
                ]);
            }

            if (preg_match('#^/api/v1/host/starter-packs/([^/]+)/members$#', $path, $matches) === 1
                && $method === 'POST'
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $this->limit($request, $ip, $session);
                $body = $this->jsonBody($request);

                return Response::json([
                    'starter_pack' => $this->runtime->starterPacks->addMember(
                        rawurldecode($matches[1]),
                        $userId,
                        $body['member'] ?? null,
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/starter-packs/([^/]+)$#', $path, $matches) === 1) {
                $packId = rawurldecode($matches[1]);
                if ($method === 'GET') {
                    $pack = $this->runtime->starterPacks->find($packId);
                    if ($pack === null) {
                        return $this->error(404, 'not_found', 'This starter pack does not exist.');
                    }

                    return Response::json(['starter_pack' => $pack]);
                }

                if (in_array($method, ['PATCH', 'DELETE'], true)) {
                    if ($userId === null) {
                        return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                    }
                    if ($method === 'DELETE') {
                        $this->runtime->starterPacks->delete($packId, $userId);

                        return Response::json(['deleted' => true]);
                    }

                    $body = $this->jsonBody($request);

                    return Response::json([
                        'starter_pack' => $this->runtime->starterPacks->update(
                            $packId,
                            $userId,
                            $body['title'] ?? null,
                            $body['description'] ?? null,
                        ),
                    ]);
                }
            }

            if ($method === 'POST' && $path === '/api/v1/host/starter-packs') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $this->limit($request, $ip, $session);
                $body = $this->jsonBody($request);

                return Response::json([
                    'starter_pack' => $this->runtime->starterPacks->create(
                        $userId,
                        $body['title'] ?? null,
                        $body['description'] ?? '',
                    ),
                ], 201);
            }

            if ($method === 'GET' && $path === '/api/v1/host/collections') {
                $account = $this->account($request, $session, 'account');
                if ($account === null) {
                    return $this->error(404, 'account_not_found', 'This profile does not exist.');
                }

                $owner = $account->id === $userId;

                return Response::json([
                    'collections' => $this->runtime->collections->forAccount($account->id, $owner, $userId),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/collection') {
                $id = trim((string) ($request->queryParam('id') ?? ''));
                if ($id === '') {
                    return $this->error(422, 'validation_failed', 'The collection ID is missing.');
                }

                $collection = $this->runtime->collections->find($id, $userId);
                if ($collection === null) {
                    return $this->error(404, 'not_found', 'This collection does not exist.');
                }

                return Response::json(['collection' => $collection]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/collections') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'collection' => $this->runtime->collections->create(
                        $userId,
                        $body['title'] ?? null,
                        $body['description'] ?? '',
                        $body['is_public'] ?? true,
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/collections/([^/]+)$#', $path, $matches) === 1
                && in_array($method, ['PATCH', 'DELETE'], true)
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $id = rawurldecode($matches[1]);
                if ($method === 'DELETE') {
                    $this->runtime->collections->delete($id, $userId);

                    return Response::json(['deleted' => true]);
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'collection' => $this->runtime->collections->update(
                        $id,
                        $userId,
                        $body['title'] ?? null,
                        $body['description'] ?? null,
                        array_key_exists('is_public', $body) ? $body['is_public'] : null,
                    ),
                ]);
            }

            if (preg_match('#^/api/v1/host/collections/([^/]+)/items$#', $path, $matches) === 1 && $method === 'POST') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);
                $postId = is_string($body['post_id'] ?? null) ? trim($body['post_id']) : '';
                if ($postId === '') {
                    return $this->error(422, 'validation_failed', 'The post is missing.', ['post_id' => 'Please choose a post.']);
                }

                return Response::json([
                    'collection' => $this->runtime->collections->addItem(rawurldecode($matches[1]), $userId, $postId),
                ]);
            }

            if (preg_match('#^/api/v1/host/collections/([^/]+)/items/([^/]+)$#', $path, $matches) === 1
                && $method === 'DELETE'
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'collection' => $this->runtime->collections->removeItem(
                        rawurldecode($matches[1]),
                        $userId,
                        rawurldecode($matches[2]),
                    ),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/post-revisions') {
                $postId = trim((string) ($request->queryParam('id') ?? ''));
                if ($postId === '') {
                    return $this->error(422, 'validation_failed', 'The post ID is missing.');
                }

                try {
                    $post = $this->runtime->app->posts->getVisible($postId, $userId);
                } catch (PostNotFoundException) {
                    return $this->error(404, 'post_not_found', 'This post is not available.');
                }

                return Response::json([
                    'post_id' => $post->id,
                    'current_revision' => $post->revision,
                    'current' => [
                        'content' => $post->content,
                        'content_warning' => $post->contentWarning,
                        'visibility' => $post->visibility->value,
                        'edited_at' => $post->editedAt === null ? null : \AlohaSocial\Core\Support\Time::format($post->editedAt),
                    ],
                    'revisions' => array_map(
                        static fn ($revision): array => $revision->toArray(),
                        $this->runtime->postRevisions->forPost($post->id),
                    ),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/drafts') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your drafts.');
                }

                return Response::json(['drafts' => $this->runtime->drafts->forAccount($userId)]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/drafts') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'draft' => $this->runtime->drafts->save($userId, $this->jsonBody($request)),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/drafts/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'draft' => $this->runtime->drafts->delete(rawurldecode($matches[1]), $userId),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/archived') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your archive.');
                }

                return Response::json(['archived' => $this->runtime->archives->forAccount($userId)]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/archived') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);
                $postId = is_string($body['post_id'] ?? null) ? $body['post_id'] : '';

                return Response::json([
                    'archived' => $this->runtime->archives->archive($userId, $postId),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/archived/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'archived' => $this->runtime->archives->restore($userId, rawurldecode($matches[1])),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/follow-requests') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see follow requests.');
                }

                return Response::json([
                    'requests' => $this->runtime->followRequests->forAccount($userId),
                    'locked' => (bool) $this->runtime->preferences->forAccount($userId)['locked'],
                ]);
            }

            if (preg_match('#^/api/v1/host/follow-requests/([^/]+)/(accept|reject)$#', $path, $matches) === 1
                && $method === 'POST'
            ) {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $requestId = rawurldecode($matches[1]);
                $request = $matches[2] === 'accept'
                    ? $this->runtime->followRequests->accept($requestId, $userId)
                    : $this->runtime->followRequests->reject($requestId, $userId);

                return Response::json(['request' => $request]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/pinned') {
                $account = $this->account($request, $session, 'account');
                if ($account === null) {
                    return $this->error(404, 'not_found', 'This account does not exist.');
                }

                return Response::json([
                    'account_id' => $account->id,
                    'pins' => $this->runtime->pinnedPosts->idsFor($account->id),
                ]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/pinned') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'pin' => $this->runtime->pinnedPosts->pin(
                        $userId,
                        is_string($body['post_id'] ?? null) ? $body['post_id'] : '',
                    ),
                ], 201);
            }

            if (preg_match('#^/api/v1/host/pinned/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'pin' => $this->runtime->pinnedPosts->unpin($userId, rawurldecode($matches[1])),
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/bluesky') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your Bluesky connection.');
                }

                return Response::json([
                    'account' => $this->runtime->blueskyAccounts->status($userId),
                    'publish_enabled' => $this->runtime->config->atprotoPublish,
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/bluesky/publication-status') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your Bluesky publication status.');
                }

                $postId = trim((string) ($request->queryParam('post_id') ?? ''));
                $accountDid = trim((string) ($request->queryParam('account_did') ?? ''));
                if ($postId === '' || preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $accountDid) !== 1) {
                    return $this->error(422, 'invalid_request', 'A post ID and linked Bluesky identity are required.');
                }

                $identity = $this->runtime->pdsRepository->identityForUser($userId);
                $external = $this->runtime->blueskyAccounts->status($userId);
                $allowedDids = array_values(array_filter([
                    $identity?->did,
                    ($external['connected'] ?? false) === true ? ($external['did'] ?? null) : null,
                ], 'is_string'));
                if (!in_array($accountDid, $allowedDids, true)) {
                    return $this->error(404, 'atproto_account_not_connected', 'This Bluesky identity is not connected.');
                }

                $service = $this->runtime->app->atproto;
                if ($service === null) {
                    return $this->error(503, 'atproto_not_configured', 'Bluesky publishing is not configured.');
                }

                try {
                    $publication = $service->publicationFor($userId, $postId, $accountDid);
                } catch (PostNotFoundException) {
                    return $this->error(404, 'not_found', 'This post does not exist.');
                }

                $defaultDid = $identity?->did
                    ?? (($external['connected'] ?? false) === true && is_string($external['did'] ?? null)
                        ? $external['did']
                        : null);

                return Response::json((new AtprotoPublicationDeliveryStatus($this->runtime->database))->forPost(
                    $userId,
                    $postId,
                    $accountDid,
                    $defaultDid,
                    $publication,
                ));
            }

            if ($method === 'GET' && $path === '/api/v1/host/bluesky/follows') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your Bluesky follows.');
                }

                $subjectDid = trim((string) ($request->queryParam('did') ?? ''));
                $service = $this->runtime->app->atprotoFollows;
                if ($service === null) {
                    return $this->error(503, 'atproto_not_configured', 'Bluesky follows are not configured.');
                }

                $follow = $subjectDid === '' ? null : $service->find($userId, $subjectDid);
                return Response::json([
                    'follow' => $follow?->toArray(),
                    'available' => $service->isAvailable($userId),
                ]);
            }

            if (in_array($method, ['POST', 'DELETE'], true) && $path === '/api/v1/host/bluesky/follows') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to change Bluesky follows.');
                }

                $service = $this->runtime->app->atprotoFollows;
                if ($service === null) {
                    return $this->error(503, 'atproto_not_configured', 'Bluesky follows are not configured.');
                }

                $body = $this->jsonBody($request);
                $subjectDid = is_string($body['subject_did'] ?? null) ? trim($body['subject_did']) : '';
                try {
                    if ($method === 'POST') {
                        return Response::json(['follow' => $service->follow($userId, $subjectDid)->toArray()], 201);
                    }

                    $service->unfollow($userId, $subjectDid);
                    return Response::json(['unfollowed' => true]);
                } catch (AtprotoFollowException $exception) {
                    $status = match ($exception->reason) {
                        AtprotoFollowException::SUBJECT_INVALID => 422,
                        AtprotoFollowException::ACCOUNT_CHANGED => 409,
                        default => 502,
                    };
                    return $this->error($status, $exception->reason, $exception->getMessage());
                } catch (AtprotoPublishDisabledException) {
                    return $this->error(503, 'atproto_publish_disabled', 'Bluesky publishing is disabled.');
                } catch (AtprotoNotConfiguredException) {
                    return $this->error(503, 'atproto_not_configured', 'Bluesky is not configured.');
                } catch (AtprotoAccountNotConnectedException) {
                    return $this->error(409, 'atproto_account_not_connected', 'Connect your Bluesky account first.');
                } catch (AtprotoPublishFailedException) {
                    return $this->error(502, 'atproto_publish_failed', 'The Bluesky follow action could not be completed. Retry the action.');
                }
            }

            if ($method === 'POST' && $path === '/api/v1/host/bluesky/connect') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'account' => $this->runtime->blueskyAccounts->connect(
                        $userId,
                        is_string($body['handle'] ?? null) ? $body['handle'] : '',
                        is_string($body['app_password'] ?? null) ? $body['app_password'] : '',
                    ),
                ], 201);
            }

            if ($method === 'DELETE' && $path === '/api/v1/host/bluesky') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                return Response::json([
                    'disconnected' => $this->runtime->blueskyAccounts->disconnect($userId),
                ]);
            }

            if ($method === 'POST' && $path === '/api/v1/host/bluesky/publish') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $service = $this->runtime->app->atproto;
                if ($service === null) {
                    return $this->error(503, 'atproto_not_configured', 'Bluesky is not configured on this instance.');
                }

                $body = $this->jsonBody($request);
                $postId = is_string($body['post_id'] ?? null) ? $body['post_id'] : '';
                $accountDid = is_string($body['account_did'] ?? null) ? $body['account_did'] : null;

                try {
                    return Response::json([
                        'publication' => $service->publish($userId, $postId, accountDid: $accountDid)->toArray(),
                    ], 201);
                } catch (PostNotFoundException) {
                    return $this->error(404, 'not_found', 'This post does not exist.');
                } catch (AtprotoPublishDisabledException) {
                    return $this->error(503, 'atproto_publish_disabled', 'Mirroring to Bluesky is disabled.');
                } catch (AtprotoNotConfiguredException) {
                    return $this->error(503, 'atproto_not_configured', 'Bluesky is not configured on this instance.');
                } catch (AtprotoAccountNotConnectedException) {
                    return $this->error(409, 'atproto_account_not_connected', 'Connect your Bluesky account in settings first.');
                } catch (AtprotoPostRejectedException $exception) {
                    return $this->error(
                        422,
                        'atproto_post_unsupported',
                        $exception->getMessage(),
                        ['reason' => $exception->reason],
                    );
                } catch (AtprotoPublishFailedException) {
                    return $this->error(502, 'atproto_publish_failed', 'Bluesky did not accept the post.');
                }
            }

            if ($method === 'GET' && $path === '/api/v1/host/preferences') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your settings.');
                }

                return Response::json(['preferences' => $this->runtime->preferences->forAccount($userId)]);
            }

            if (in_array($method, ['PATCH', 'PUT'], true) && $path === '/api/v1/host/preferences') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $payload = $this->jsonBody($request);
                $cleanupPending = [];
                $preferences = $this->runtime->preferences->update($userId, $payload);
                foreach ([
                    'mirror_likes_to_atproto' => 'like',
                    'mirror_boosts_to_atproto' => 'repost',
                ] as $setting => $kind) {
                    if (($payload[$setting] ?? null) !== false) {
                        continue;
                    }
                    try {
                        $this->runtime->app->atprotoInteractions?->removeAllForUser($userId, $kind);
                        $cleanupPending[$setting] = false;
                    } catch (Throwable) {
                        // Disabling mirroring takes effect immediately. Keep
                        // the ledger row so another settings save can retry.
                        $cleanupPending[$setting] = true;
                    }
                }
                try {
                    $this->runtime->app->profileUpdates?->publish($userId);
                } catch (\Throwable) {
                    // Keep the local preference even when a peer update cannot
                    // be queued; the next edit publishes the current document.
                }

                return Response::json([
                    'preferences' => $preferences,
                    'mirror_cleanup_pending' => $cleanupPending,
                ]);
            }

            if ($method === 'GET' && $path === '/api/v1/host/export') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to export your data.');
                }

                $account = $this->runtime->accounts->find($userId);
                if ($account === null) {
                    return $this->error(404, 'not_found', 'This account no longer exists.');
                }

                $stamp = $this->runtime->clock->now()->format('Ymd-His');
                $filename = 'aloha-export-' . preg_replace('/[^a-z0-9_-]+/i', '-', $account->handle) . '-' . $stamp . '.json';

                $export = $this->runtime->exports->build($account);
                $export['core_archive'] = $this->runtime->app->accountData?->export($userId);
                $export['linked_bluesky_account'] = $this->runtime->blueskyAccounts->status($userId);

                return Response::text(
                    Exports::toJson($export),
                    200,
                    [
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ],
                );
            }

            if ($method === 'POST' && $path === '/api/v1/host/import/preview') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to preview an import.');
                }

                return Response::json([
                    'preview' => $this->runtime->imports->preview($this->jsonBody($request)),
                ]);
            }

            if ($method === 'DELETE' && $path === '/api/v1/account/data') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to delete your account.');
                }

                $account = $this->runtime->accounts->find($userId);
                if ($account === null) {
                    return $this->error(404, 'not_found', 'This account no longer exists.');
                }

                if ($account->isAdmin() && $this->runtime->accounts->activeAdminCount() <= 1) {
                    return $this->error(409, 'last_active_administrator', 'Promote another administrator before deleting this account.');
                }

                if ($this->runtime->app->accountData === null) {
                    return $this->error(503, 'account_data_unavailable', 'Account deletion is unavailable on this instance.');
                }

                $report = $this->runtime->app->accountData->erase($userId);
                $hostDataDeleted = $report->inProgress()
                    ? []
                    : $this->runtime->accountErasureCleanup->erase($userId);
                // Core freezes access first. Keep the host session available
                // only for retrying a frozen deletion; the access guard blocks
                // every other request until the durable Core erase completes.
                // Final host cleanup and credential retirement are atomic.

                return Response::json([
                    'deletion' => $report->toArray(),
                    'host_data_deleted' => $hostDataDeleted,
                    'host_data_retained' => AccountErasureCleanup::RETAINED_CATEGORIES,
                    'account_disabled' => true,
                    'remote_deletion' => 'ActivityPub and Bluesky data at remote servers may persist.',
                ], $report->inProgress() ? 202 : 200, ['Cache-Control' => 'private, no-store']);
            }

            if ($method === 'POST' && $path === '/api/v1/host/for-you/feedback') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $body = $this->jsonBody($request);

                return Response::json([
                    'feedback' => $this->runtime->forYou->setFeedback(
                        $userId,
                        is_string($body['post_id'] ?? null) ? $body['post_id'] : '',
                        is_string($body['kind'] ?? null) ? $body['kind'] : '',
                    ),
                ], 201);
            }

            if ($method === 'POST' && $path === '/api/v1/host/for-you/impressions') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'You must be signed in for this action.');
                }

                $this->limit($request, $ip, $session);
                $body = $this->jsonBody($request);
                $impressions = is_array($body['impressions'] ?? null) ? $body['impressions'] : [];

                return Response::json([
                    'recorded' => $this->runtime->forYou->recordImpressions(
                        $userId,
                        array_values($impressions),
                    ),
                ], 202);
            }

            if ($method === 'GET' && $path === '/api/v1/host/trending') {
                $this->limit($request, $ip, $session);

                return Response::json($this->runtime->forYou->trending(
                    $this->intParam($request, 'limit', 20, 1, 40),
                    $this->intParam($request, 'offset', 0, 0, 10_000),
                    $this->cursorParam($request),
                ));
            }

            if ($method === 'GET' && $path === '/api/v1/host/for-you') {
                if ($userId === null) {
                    return $this->error(401, 'not_authenticated', 'Sign in to see your personal timeline.');
                }

                return Response::json(
                    $this->runtime->forYou->page(
                        $userId,
                        $this->intParam($request, 'limit', 20, 1, 40),
                        $this->intParam($request, 'offset', 0, 0, 10_000),
                        $this->cursorParam($request),
                    ),
                );
            }
        } catch (CommunityException $exception) {
            return $this->error($exception->status, $exception->errorCode, $exception->getMessage(), $exception->fields);
        } catch (BlueskyException $exception) {
            return $this->error($exception->status, $exception->errorCode, $exception->getMessage());
        } catch (Throwable) {
            return $this->error(500, 'community_failed', 'The request could not be processed.');
        }

        return null;
    }

    private function account(Request $request, ?AuthenticatedSession $session, string $parameter): ?Account
    {
        $handle = trim((string) ($request->queryParam($parameter) ?? ''));
        if ($handle !== '') {
            $account = $this->runtime->accounts->findByHandle($handle);

            return $account ?? $this->runtime->accounts->find($handle);
        }

        $userId = $session?->userId();

        return $userId === null ? null : $this->runtime->accounts->find($userId);
    }

    private function limit(Request $request, ?string $ip, ?AuthenticatedSession $session): void
    {
        $identity = $session?->userId() ?? $ip ?? 'anonymous';
        $this->runtime->rateLimiter->attempt('host.community', $identity, 120, 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function intParam(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->queryParam($key);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function cursorParam(Request $request): ?string
    {
        $cursor = $request->queryParam('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * @param array<string, string> $fields
     */
    private function error(int $status, string $code, string $message, array $fields = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return Response::json(['error' => $error], $status);
    }
}
