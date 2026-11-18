<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Atproto\AtprotoAccountNotConnectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoNotConfiguredException;
use AlohaSocial\Core\Service\Atproto\AtprotoPostRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Community\CommunityException;
use AlohaSocial\Server\Community\Exports;
use AlohaSocial\Server\Federation\BlueskyException;
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
                    'collection' => $this->runtime->collections->addItem($userId === null ? '' : rawurldecode($matches[1]), $userId, $postId),
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

                try {
                    return Response::json([
                        'publication' => $service->publish($userId, $postId)->toArray(),
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

                $preferences = $this->runtime->preferences->update($userId, $this->jsonBody($request));
                try {
                    $this->runtime->app->profileUpdates?->publish($userId);
                } catch (\Throwable) {
                    // Keep the local preference even when a peer update cannot
                    // be queued; the next edit publishes the current document.
                }

                return Response::json(['preferences' => $preferences]);
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

                return Response::text(
                    Exports::toJson($this->runtime->exports->build($account)),
                    200,
                    [
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ],
                );
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

            if ($method === 'GET' && $path === '/api/v1/host/trending') {
                $this->limit($request, $ip, $session);

                return Response::json($this->runtime->forYou->trending(
                    $this->intParam($request, 'limit', 20, 1, 40),
                    $this->intParam($request, 'offset', 0, 0, 10_000),
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
