<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Server\Accounts\RegistrationException;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Auth\PasswordResetException;
use AlohaSocial\Server\Auth\SessionIssue;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Federation\RemoteDirectoryException;
use AlohaSocial\Server\Mail\MailException;
use AlohaSocial\Server\Setup\SetupException;
use Throwable;

/**
 * The server's only HTTP entry point.
 *
 * Host pages (`/`, `/login`, `/logout`) are handled here; everything else is
 * the core's route manifest, served through `$app->kernel`. The controller
 * authenticates the caller, passes the resulting local id as `Request::$userId`
 * and enforces CSRF for state-changing requests that are authenticated by a
 * session cookie. Bearer-token requests (the compatibility prefix) are not
 * affected: they do not carry a session.
 */
final class FrontController
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private readonly AdminApiController $adminApi;

    private readonly CommunityApiController $communityApi;

    private readonly FederationController $federation;

    private readonly AtprotoIdentityController $atprotoIdentity;

    private readonly AtprotoSessionController $atprotoSession;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
        $this->adminApi = new AdminApiController($runtime);
        $this->communityApi = new CommunityApiController($runtime);
        $this->federation = new FederationController($runtime);
        $this->atprotoIdentity = new AtprotoIdentityController($runtime);
        $this->atprotoSession = new AtprotoSessionController($runtime);
    }

    /**
     * @param array<string, string> $cookies the request's cookies
     */
    public function handle(Request $request, array $cookies = [], ?string $userAgent = null, ?string $ip = null): Response
    {
        if (($request->attributes['request_body_too_large'] ?? false) === true) {
            return $this->withDynamicCachePolicy(Response::json(
                ['error' => ['code' => 'payload_too_large', 'message' => 'The request body exceeds the 64 MiB limit.']],
                413,
            ));
        }

        return $this->withDynamicCachePolicy(
            $this->dispatch($request, $cookies, $userAgent, $ip),
        );
    }

    /**
     * Every response from this controller is generated per request: profiles,
     * counts, relationship lists and ActivityPub documents change as soon as
     * somebody follows or posts. A CDN in front of the instance must not keep
     * them, or a profile can show a previous domain and an empty following
     * list for hours. The explicit Cloudflare header has the highest
     * precedence for zones that cache API paths by page rule.
     */
    private function withDynamicCachePolicy(Response $response): Response
    {
        if ($response->header('Cache-Control') === null) {
            $response = $response->withHeader('Cache-Control', 'no-store, max-age=0');
        }

        return $response
            ->withHeader('CDN-Cache-Control', 'no-store')
            ->withHeader('Cloudflare-CDN-Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $cookies the request's cookies
     */
    private function dispatch(Request $request, array $cookies = [], ?string $userAgent = null, ?string $ip = null): Response
    {
        $cookieName = $this->runtime->config->sessionCookieName;
        $rawCookie = $cookies[$cookieName] ?? null;
        $rawCookie = is_string($rawCookie) && $rawCookie !== '' ? $rawCookie : null;

        $session = $this->runtime->sessions->resolve($rawCookie);

        $atprotoSessionResponse = $this->atprotoSession->handle($request);
        if ($atprotoSessionResponse !== null) {
            return $atprotoSessionResponse;
        }

        $request = $this->atprotoSession->authenticate($request);

        // Public ActivityPub/NodeInfo documents the core does not route. This
        // must run before the compatibility API so a post URL can negotiate
        // between its internal JSON shape and its Note document.
        $federationResponse = $this->federation->handle($request);
        if ($federationResponse !== null) {
            return $federationResponse;
        }

        if ($request->method === 'GET' && $request->path === '/readyz') {
            $readiness = $this->runtime->admin->readiness();

            return Response::json($readiness, ($readiness['ready'] ?? false) ? 200 : 503);
        }

        if ($request->path === '/setup' && in_array($request->method, ['GET', 'POST'], true)) {
            return $request->method === 'GET'
                ? $this->setupForm($request, $session, $rawCookie, $userAgent, $ip)
                : $this->setupSubmit($request, $session, $rawCookie, $userAgent, $ip);
        }

        if ($request->method === 'GET' && $request->path === '/login') {
            $notice = null;
            if ($request->queryParam('registered') === '1') {
                $notice = 'Your account is ready. You can sign in now.';
            } elseif ($request->queryParam('pending') === '1') {
                $notice = 'Your account was created. An administrator still has to approve it.';
            } elseif ($request->queryParam('reset') === '1') {
                $notice = 'Your password was changed. Please sign in again.';
            } elseif ($request->queryParam('setup') === '1') {
                $notice = 'The instance is configured. Sign in with the administrator account you created.';
            }

            return $this->loginForm(
                $session,
                $rawCookie,
                $userAgent,
                $ip,
                $notice,
                $this->safeReturnPath($request->queryParam('next')),
            );
        }

        if ($request->method === 'POST' && $request->path === '/login') {
            return $this->login($request, $session, $rawCookie, $userAgent, $ip);
        }

        if ($request->path === '/signup' && in_array($request->method, ['GET', 'POST'], true)) {
            if (!$this->runtime->registration->enabled()) {
                return $this->redirect('/login');
            }

            return $request->method === 'GET'
                ? $this->signupForm($session, $rawCookie, $userAgent, $ip)
                : $this->signup($request, $session, $rawCookie, $userAgent, $ip);
        }

        if ($request->method === 'POST' && $request->path === '/logout') {
            return $this->logout($request, $session, $rawCookie);
        }

        if ($request->method === 'GET' && $request->path === '/') {
            return $this->home($session);
        }

        if ($request->method === 'GET' && $request->path === '/forgot-password') {
            return $this->forgotPasswordForm($session, $rawCookie, $userAgent, $ip);
        }

        if ($request->method === 'POST' && $request->path === '/forgot-password') {
            return $this->forgotPassword($request, $session, $rawCookie, $userAgent, $ip);
        }

        if ($request->method === 'GET' && $request->path === '/reset-password') {
            return $this->resetPasswordForm($request, $session, $rawCookie, $userAgent, $ip);
        }

        if ($request->method === 'POST' && $request->path === '/reset-password') {
            return $this->resetPassword($request, $session, $rawCookie, $userAgent, $ip);
        }

        if ($request->method === 'GET' && $request->path === '/app') {
            return $this->appPage($session);
        }

        if ($request->method === 'GET' && $request->path === '/admin') {
            if ($session?->account?->isAdmin() !== true) {
                // An anonymous session cookie is still a session object. It
                // must be sent to the login page, not shown a misleading 403
                // after the instance moves to a real domain and its old
                // cookie can no longer identify the administrator.
                return $session?->account === null ? $this->redirect('/login?next=%2Fadmin') : $this->page('error', [
                    'message' => 'This page is reserved for the administration.',
                ], 'No access', 403);
            }

            // The standalone HTML console remains the primary admin UI.
            // The SPA admin stays available at /app#/admin.
            return $this->adminConsole($session);
        }

        if ($request->method === 'GET' && $request->path === '/favicon.ico') {
            return Response::empty(204);
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/profiles') {
            return Response::json([
                'profiles' => $this->runtime->accounts->displayNames(),
            ]);
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/lookup') {
            $identity = $session?->userId() ?? $ip ?? 'anonymous';
            $retryAfter = $this->runtime->rateLimiter->attempt('host.remote_lookup', $identity, 30, 60);
            if ($retryAfter !== null) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'rate_limited',
                            'message' => 'Too many profile lookups. Please wait a moment.',
                        ],
                    ],
                    429,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            $handle = trim((string) ($request->queryParam('handle') ?? ''));
            $cursor = $request->queryParam('cursor');
            $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

            try {
                return Response::json($this->runtime->remoteDirectory->lookup($handle, $cursor));
            } catch (RemoteDirectoryException $exception) {
                return $this->apiError($exception->status, $exception->errorCode, $exception->getMessage());
            } catch (Throwable) {
                return $this->apiError(502, 'remote_lookup_failed', 'The remote profile could not be loaded.');
            }
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/remote-post') {
            $identity = $session?->userId() ?? $ip ?? 'anonymous';
            $retryAfter = $this->runtime->rateLimiter->attempt('host.remote_post', $identity, 60, 60);
            if ($retryAfter !== null) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'rate_limited',
                            'message' => 'Too many post lookups. Please wait a moment.',
                        ],
                    ],
                    429,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            $id = trim((string) ($request->queryParam('id') ?? ''));
            if ($id === '') {
                return $this->apiError(422, 'remote_post_missing', 'Please provide a post.');
            }

            try {
                return Response::json($this->runtime->remoteDirectory->remotePost($id));
            } catch (RemoteDirectoryException $exception) {
                return $this->apiError($exception->status, $exception->errorCode, $exception->getMessage());
            } catch (Throwable) {
                return $this->apiError(502, 'remote_post_failed', 'The remote post could not be loaded.');
            }
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/remote-connections') {
            $identity = $session?->userId() ?? $ip ?? 'anonymous';
            $retryAfter = $this->runtime->rateLimiter->attempt('host.remote_connections', $identity, 30, 60);
            if ($retryAfter !== null) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'rate_limited',
                            'message' => 'Too many connection lookups. Please wait a moment.',
                        ],
                    ],
                    429,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            $handle = trim((string) ($request->queryParam('handle') ?? ''));
            $type = trim((string) ($request->queryParam('type') ?? ''));
            $cursor = $request->queryParam('cursor');
            $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

            try {
                return Response::json($this->runtime->remoteDirectory->remoteConnections($handle, $type, $cursor));
            } catch (RemoteDirectoryException $exception) {
                return $this->apiError($exception->status, $exception->errorCode, $exception->getMessage());
            } catch (Throwable) {
                return $this->apiError(502, 'remote_connections_failed', 'Connections could not be loaded.');
            }
        }

        // Cross-network discovery. Bluesky is read from the public AppView, the
        // Fediverse from the configured discovery instance (see
        // HostConfig::$discoveryInstance). Nothing is stored locally.
        if ($request->method === 'GET' && $request->path === '/api/v1/host/search') {
            $identity = $session?->userId() ?? $ip ?? 'anonymous';
            $retryAfter = $this->runtime->rateLimiter->attempt('host.remote_search', $identity, 40, 60);
            if ($retryAfter !== null) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'rate_limited',
                            'message' => 'Too many searches. Please wait a moment.',
                        ],
                    ],
                    429,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            $query = trim((string) ($request->queryParam('q') ?? ''));
            $type = trim((string) ($request->queryParam('type') ?? 'all'));
            $network = trim((string) ($request->queryParam('network') ?? 'all'));
            $cursor = $request->queryParam('cursor');
            $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

            try {
                return Response::json($this->runtime->remoteDirectory->search($query, $type, $network, $cursor));
            } catch (RemoteDirectoryException $exception) {
                return $this->apiError($exception->status, $exception->errorCode, $exception->getMessage());
            } catch (Throwable) {
                return $this->apiError(502, 'remote_search_failed', 'The search could not be completed.');
            }
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/remote-engagement') {
            $identity = $session?->userId() ?? $ip ?? 'anonymous';
            $retryAfter = $this->runtime->rateLimiter->attempt('host.remote_engagement', $identity, 60, 60);
            if ($retryAfter !== null) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'rate_limited',
                            'message' => 'Too many reaction lookups. Please wait a moment.',
                        ],
                    ],
                    429,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            $id = trim((string) ($request->queryParam('id') ?? ''));
            $type = trim((string) ($request->queryParam('type') ?? 'likes'));
            $cursor = $request->queryParam('cursor');
            $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

            try {
                return Response::json($this->runtime->remoteDirectory->remoteEngagement($id, $type, $cursor));
            } catch (RemoteDirectoryException $exception) {
                return $this->apiError($exception->status, $exception->errorCode, $exception->getMessage());
            } catch (Throwable) {
                return $this->apiError(502, 'remote_engagement_failed', 'Reactions could not be loaded.');
            }
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/interactions') {
            $ids = array_slice(explode(',', (string) ($request->queryParam('ids') ?? '')), 0, 80);
            $visible = [];
            $remote = [];
            $viewerId = $session?->userId();
            foreach ($ids as $id) {
                $id = trim($id);
                if ($id === '') {
                    continue;
                }

                if ($this->isRemoteObjectTarget($id)) {
                    $reactions = $this->runtime->app->reactions;
                    if ($viewerId !== null && mb_strlen($id) <= 2048 && $reactions !== null) {
                        try {
                            $post = $reactions->remotePostFor($viewerId, $id);
                            $remote[$id] = $this->remoteInteraction(
                                $reactions->remoteInteractionsFor($viewerId, $post),
                            );
                        } catch (ReactionException) {
                            // Core applies follow, visibility and block policy; unavailable items are omitted.
                        }
                    }
                    continue;
                }

                try {
                    // The interaction route must not reveal that a private post
                    // exists, and it must not return counts for a post the
                    // caller cannot read.
                    $this->runtime->app->posts->getVisible($id, $session?->userId());
                    $visible[] = $id;
                } catch (PostNotFoundException) {
                    continue;
                }
            }

            return Response::json([
                'interactions' => array_replace(
                    $this->runtime->interactions->forPosts($visible, $viewerId),
                    $remote,
                ),
            ]);
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/posts') {
            $ids = array_slice(explode(',', (string) ($request->queryParam('ids') ?? '')), 0, 80);
            $posts = [];
            foreach ($ids as $id) {
                $id = trim($id);
                if ($id === '' || mb_strlen($id) > 512) {
                    continue;
                }

                try {
                    $post = $this->runtime->app->posts->getVisible($id, $session?->userId());
                    $posts[$id] = $this->runtime->app->media?->presentPost($post) ?? $post->toArray();
                } catch (PostNotFoundException) {
                    $remote = $this->presentRemotePost($id);
                    if ($remote !== null) {
                        $posts[$id] = $remote;
                    }
                }
            }

            return Response::json(['posts' => $posts]);
        }

        // A quote stores the quoted object id, which is the ActivityPub note
        // URL for a local post (see ActivityFactory::noteIdFor). The UI needs
        // the summary back to draw the embedded card; resolve local notes from
        // the post table and remote notes through the same guarded fetcher the
        // remote views use. Unavailable or pending targets are simply absent.
        if ($request->method === 'GET' && $request->path === '/api/v1/host/quoted') {
            $identity = $session?->userId() ?? $ip ?? 'anonymous';
            $retryAfter = $this->runtime->rateLimiter->attempt('host.quoted', $identity, 120, 60);
            if ($retryAfter !== null) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'rate_limited',
                            'message' => 'Too many quote lookups. Please wait a moment.',
                        ],
                    ],
                    429,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            $ids = array_slice(explode(',', (string) ($request->queryParam('ids') ?? '')), 0, 40);
            $prefix = rtrim($this->runtime->config->instanceUrl, '/') . '/api/v1/posts/';
            $quoted = [];
            foreach ($ids as $objectId) {
                $objectId = trim($objectId);
                if ($objectId === '' || mb_strlen($objectId) > 2048) {
                    continue;
                }

                if (str_starts_with($objectId, $prefix)) {
                    $localId = rawurldecode(substr($objectId, strlen($prefix)));
                    if ($localId === '' || str_contains($localId, '/')) {
                        continue;
                    }

                    try {
                        $post = $this->runtime->app->posts->getVisible($localId, $session?->userId());
                    } catch (PostNotFoundException) {
                        continue;
                    }

                    $quoted[$objectId] = $this->runtime->app->media?->presentPost($post) ?? $post->toArray();
                    continue;
                }

                if (!preg_match('#^https?://#i', $objectId)) {
                    continue;
                }

                try {
                    $remote = $this->runtime->remoteDirectory->remotePost($objectId);
                } catch (Throwable) {
                    // A removed, private or not-yet-fetched quote target stays
                    // an empty card rather than failing the whole feed.
                    continue;
                }

                if (isset($remote['post']) && is_array($remote['post'])) {
                    $quoted[$objectId] = $remote['post'];
                }
            }

            return Response::json(['quoted' => $quoted]);
        }

        $sessionUserId = $session?->userId();
        $userId = $sessionUserId ?? $request->userId;

        if ($sessionUserId !== null && in_array($request->method, self::MUTATING_METHODS, true)) {
            $token = (string) ($request->header('x-csrf-token') ?? '');
            if (!$this->runtime->sessions->csrfMatches($session, $token)) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'csrf_token_invalid',
                            'message' => 'The CSRF token is missing or invalid.',
                        ],
                    ],
                    403,
                );
            }
        }

        $atprotoResponse = $this->atprotoIdentity->handle($request, $session);
        if ($atprotoResponse !== null) {
            return $atprotoResponse;
        }

        $adminResponse = $this->adminApi->handle($request, $session);
        if ($adminResponse !== null) {
            return $adminResponse;
        }

        $communityResponse = $this->communityApi->handle($request, $session, $ip);
        if ($communityResponse !== null) {
            return $communityResponse;
        }

        if (preg_match('#^/api/v1/accounts/([^/]+)/follow$#', $request->path, $matches) === 1
            && in_array($request->method, ['POST', 'DELETE'], true)
        ) {
            if ($userId === null) {
                return $this->apiError(401, 'not_authenticated', 'You must be signed in for this action.');
            }

            $targetId = rawurldecode($matches[1]);
            $target = $this->runtime->accounts->find($targetId)
                ?? $this->runtime->accounts->findByHandle($targetId);

            if ($target !== null) {
                if ($request->method === 'DELETE') {
                    // Withdrawing a request must also clear the pending row; the
                    // core unfollow below handles an already accepted edge.
                    $this->runtime->followRequests->withdraw($target->id, $userId);
                } elseif ($this->runtime->preferences->forAccount($target->id)['locked'] === true) {
                    $request = $this->runtime->followRequests->requestFromLocal($target->id, $userId);

                    return Response::json([
                        'requested' => true,
                        'request' => $request,
                        'relationship' => [
                            'id' => $target->id,
                            'following' => false,
                            'followed_by' => false,
                            'blocking' => false,
                            'muting' => false,
                            'requested' => true,
                        ],
                    ]);
                }
            }
        }

        if ($request->method === 'PATCH' && $request->path === '/api/v1/host/profile') {
            if ($userId === null) {
                return $this->apiError(401, 'not_authenticated', 'You must be signed in for this action.');
            }

            $body = json_decode($request->body, true);
            if (!is_array($body) || !array_key_exists('display_name', $body) || !is_string($body['display_name'])) {
                return $this->apiError(422, 'invalid_profile', 'The display name is missing.', ['display_name' => 'Please enter a display name.']);
            }

            $displayName = trim($body['display_name']);
            if (mb_strlen($displayName) > 80) {
                return $this->apiError(422, 'invalid_profile', 'The display name is too long.', ['display_name' => 'At most 80 characters.']);
            }

            $this->runtime->accounts->updateDisplayName($userId, $displayName === '' ? null : $displayName);
            try {
                $this->runtime->app->profileUpdates?->publish($userId);
            } catch (Throwable) {
                // The local edit stays; a peer update is retried on the next
                // profile change instead of failing the member's request.
            }
            $account = $this->runtime->accounts->find($userId);

            return Response::json([
                'account' => [
                    'handle' => $userId,
                    'display_name' => $account?->name() ?? $userId,
                ],
            ]);
        }

        if (preg_match('#^/api/v1/posts/([^/]+)/like$#', $request->path, $matches) === 1
            && in_array($request->method, ['POST', 'DELETE'], true)
        ) {
            if ($userId === null) {
                return $this->apiError(401, 'not_authenticated', 'You must be signed in for this action.');
            }

            $postId = rawurldecode($matches[1]);
            if ($this->isRemoteObjectTarget($postId)) {
                $reactions = $this->runtime->app->reactions;
                if ($reactions === null) {
                    return $this->apiError(503, 'remote_reactions_not_configured', 'Remote post reactions are not available.');
                }

                try {
                    $interaction = $request->method === 'POST'
                        ? $reactions->likeRemote($userId, $postId)
                        : $reactions->unlikeRemote($userId, $postId);

                    return Response::json(['interaction' => $this->remoteInteraction($interaction)]);
                } catch (ReactionException $exception) {
                    [$status, $code, $message] = match ($exception->reason) {
                        'remote_post_not_found', 'remote_post_not_followed' => [404, 'not_found', 'Status not found.'],
                        'blocked_actor' => [403, 'blocked_actor', $exception->getMessage()],
                        default => [422, $exception->reason, $exception->getMessage()],
                    };

                    return $this->apiError($status, $code, $message);
                }
            }

            try {
                $interaction = $request->method === 'POST'
                    ? $this->runtime->interactions->like($userId, $postId)
                    : $this->runtime->interactions->unlike($userId, $postId);
            } catch (PostNotFoundException) {
                return $this->apiError(404, 'post_not_found', 'This post is not available.');
            }

            return Response::json(['interaction' => $interaction]);
        }

        $rateLimited = $this->enforceRateLimit($request, $userId);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        // A first-party session is full access, so the compatibility routes'
        // scope middleware sees every scope. Bearer-token requests keep the
        // scopes their authenticator supplied.
        $authenticated = $userId === null
            ? $request->withUserId(null)
            : $request->withUserId($userId)->withScopes(['*']);

        $response = $this->federation->decorate(
            $request,
            $this->runtime->app->handle($authenticated),
        );

        // The core accepted an inbox POST, but a remote Create has no local
        // reply row and its RemotePost record does not keep `inReplyTo`. Index
        // the reply only after signature, audience and replay checks passed.
        if ($request->method === 'POST'
            && str_ends_with($request->path, '/inbox')
            && $response->status >= 200
            && $response->status < 300
        ) {
            $this->runtime->interactions->recordInboundActivity($request->body);
        }

        return $response;
    }

    /**
     * The core declares a budget per route and leaves enforcement to the
     * host. The identity is the signed-in account, or the client address for
     * anonymous requests.
     */
    /**
     * Hydrate a cached federated post for the shared card without a network
     * call. The home timeline uses the same entry shape, so a For You card and
     * a Following card render from one representation.
     *
     * @return array<string, mixed>|null
     */
    private function presentRemotePost(string $id): ?array
    {
        $repository = new PdoRemotePostRepository($this->runtime->database);
        $post = str_starts_with($id, 'at://')
            ? $repository->findPostByObjectId($id)
            : $repository->findByActivityId($id);
        if (!$post instanceof RemotePost || $post->deletedAt !== null) {
            return null;
        }

        $entry = new TimelineEntry(
            id: $post->activityId,
            authorId: $post->actorId,
            objectId: $post->objectId,
            objectAuthorId: $post->objectAuthorId,
            content: $post->content,
            contentWarning: $post->contentWarning,
            publishedAt: $post->publishedAt,
            boostedBy: $post->kind === RemotePostKind::Boost ? $post->actorId : null,
        );
        $presented = $this->runtime->app->media?->presentTimelineEntries([$entry])[0]
            ?? $entry->toArray();

        // The caller asked for this key. A boost is hydrated by its object id
        // when a card fetches the original, so keep the requested id stable.
        $presented['id'] = $id;
        $presented['is_remote'] = true;

        return $presented;
    }

    private function isRemoteObjectTarget(string $id): bool
    {
        if ($id === '' || mb_strlen($id) > 2048) {
            return false;
        }

        $parts = parse_url($id);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host']);
    }

    /**
     * @param array<string, mixed> $status
     * @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int, emoji_reactions: list<array{emoji: string, count: int}>}
     */
    private function remoteInteraction(array $status): array
    {
        $viewer = is_array($status['viewer'] ?? null) ? $status['viewer'] : [];

        return [
            'favourites_count' => 0,
            'favourited' => ($viewer['favourited'] ?? false) === true,
            'reblogs_count' => 0,
            'reblogged' => false,
            'replies_count' => 0,
            'emoji_reactions' => [],
        ];
    }

    private function enforceRateLimit(Request $request, ?string $userId): ?Response
    {
        try {
            $match = $this->runtime->router->match($request->method, $request->path);
        } catch (HttpException) {
            // Unknown path or wrong method: the kernel owns that answer.
            return null;
        }

        $spec = $match->route->rateLimit;
        if ($spec === null) {
            return null;
        }

        $parts = explode('/', $spec, 2);
        $limit = (int) ($parts[0] ?? 0);
        $window = (int) ($parts[1] ?? 0);
        if ($limit < 1 || $window < 1) {
            return null;
        }

        $identity = $userId ?? $request->remoteAddress ?? 'unknown';
        $retryAfter = $this->runtime->rateLimiter->attempt($match->route->name, $identity, $limit, $window);

        if ($retryAfter === null) {
            return null;
        }

        return Response::json(
            [
                'error' => [
                    'code' => 'rate_limited',
                    'message' => 'Too many requests; retry in ' . $retryAfter . ' seconds.',
                ],
            ],
            429,
            ['Retry-After' => (string) $retryAfter, 'Cache-Control' => 'no-store'],
        );
    }

    private function setupForm(
        Request $request,
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        if (!$this->runtime->setup->enabled()) {
            return $this->page('error', [
                'message' => 'Web setup is closed. Reset the instance from the terminal to open it again.',
            ], 'Setup closed', 404);
        }

        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);
        $token = (string) ($request->queryParam('token') ?? '');
        $valid = $this->runtime->setup->verifyToken($token);

        return $this->page(
            'setup',
            $this->setupData($session, $token, $valid),
            'Set up your instance',
            $valid ? 200 : 403,
            $issue,
        );
    }

    private function setupSubmit(
        Request $request,
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);
        $params = $this->formParams($request);
        $token = (string) ($params['token'] ?? '');
        $valid = $this->runtime->setup->verifyToken($token);

        if (!$this->runtime->sessions->csrfMatches($session, $this->csrfToken($request))) {
            return $this->page(
                'setup',
                $this->setupData($session, $token, $valid, 'Your session has expired. Please try again.', $params),
                'Set up your instance',
                403,
                $issue,
            );
        }

        try {
            $result = $this->runtime->setup->install($params);
        } catch (SetupException $exception) {
            return $this->page(
                'setup',
                $this->setupData($session, $token, $valid, $exception->getMessage(), $params, $exception->fields),
                'Set up your instance',
                $valid ? 422 : 403,
                $issue,
            );
        }

        return $this->redirect($result['url'] . '/login?setup=1');
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function setupData(
        ?AuthenticatedSession $session,
        string $token,
        bool $tokenValid,
        ?string $error = null,
        array $values = [],
        array $errors = [],
    ): array {
        $defaults = [
            'url' => $this->runtime->config->instanceUrl,
            'name' => $this->runtime->config->instanceName,
            'description' => $this->runtime->config->instanceDescription,
            'contact' => $this->runtime->config->contactEmail,
            'handle' => '',
            'email' => '',
            'display_name' => '',
        ];

        foreach ($defaults as $key => $default) {
            $value = $values[$key] ?? null;
            $defaults[$key] = is_string($value) ? $value : $default;
        }

        return [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session?->session->csrfToken ?? '',
            'token' => $token,
            'tokenValid' => $tokenValid,
            'error' => $error,
            'errors' => $errors,
            'values' => $defaults,
        ];
    }

    private function signupForm(
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
        array $errors = [],
        array $values = [],
        int $status = 200,
    ): Response {
        if ($session?->isSignedIn() === true) {
            return $this->redirect('/');
        }

        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);

        return $this->runtime->view->page('signup', [
            'session' => $session,
            'csrf' => $session?->session->csrfToken ?? '',
            'errors' => $errors,
            'values' => $values,
            'approval' => $this->runtime->registration->approvalRequired(),
        ], 'Create account', $status, $issue);
    }

    private function signup(
        Request $request,
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        $params = $this->formParams($request);
        $values = [
            'handle' => trim((string) ($params['handle'] ?? '')),
            'email' => trim((string) ($params['email'] ?? '')),
            'display_name' => trim((string) ($params['display_name'] ?? '')),
        ];

        if (!$this->runtime->sessions->csrfMatches($session, (string) ($params['_csrf'] ?? ''))) {
            return $this->signupForm($session, $rawCookie, $userAgent, $ip, [
                '_form' => 'Your session has expired. Please try again.',
            ], $values, 403);
        }

        if ($ip !== null && $this->runtime->rateLimiter->attempt('host.signup', $ip, 5, 3600) !== null) {
            return $this->signupForm($session, $rawCookie, $userAgent, $ip, [
                '_form' => 'Too many registration attempts. Please try again later.',
            ], $values, 429);
        }

        try {
            $this->runtime->registration->register(
                $values['handle'],
                $values['email'],
                (string) ($params['password'] ?? ''),
                $values['display_name'] === '' ? null : $values['display_name'],
            );
        } catch (RegistrationException $exception) {
            $errors = $exception->fields;
            if ($errors === []) {
                $errors['_form'] = $exception->getMessage();
            }

            return $this->signupForm($session, $rawCookie, $userAgent, $ip, $errors, $values, $exception->status);
        }

        return $this->redirect($this->runtime->registration->approvalRequired()
            ? '/login?pending=1'
            : '/login?registered=1');
    }

    private function loginForm(
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
        ?string $notice = null,
        string $returnTo = '',
    ): Response {
        if ($session?->isSignedIn() === true) {
            return $this->redirect('/');
        }

        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);

        return $this->loginResponse($session, null, 200, $issue, $notice, $returnTo);
    }

    private function login(
        Request $request,
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        $params = $this->formParams($request);
        $csrf = (string) ($params['_csrf'] ?? '');
        $handle = trim((string) ($params['handle'] ?? ''));
        $password = (string) ($params['password'] ?? '');
        $returnTo = $this->safeReturnPath($params['return_to'] ?? null);

        if (!$this->runtime->sessions->csrfMatches($session, $csrf)) {
            $issue = null;
            if ($session === null) {
                $issue = $this->runtime->sessions->issue(null, $rawCookie, $userAgent, $ip);
                $session = new AuthenticatedSession($issue->session, null);
            }

            return $this->loginResponse($session, 'Your session has expired. Please try again.', 403, $issue, null, $returnTo);
        }

        if ($handle === '' || $password === '') {
            return $this->loginResponse($session, 'Handle and password are required.', 422, null, null, $returnTo);
        }

        if ($this->runtime->throttle->isLimited($handle, $ip)) {
            return $this->loginResponse($session, 'Too many failed attempts. Please try again in 15 minutes.', 429, null, null, $returnTo);
        }

        $account = $this->runtime->accounts->findByHandle($handle)
            ?? $this->runtime->accounts->findByEmail($handle);

        $valid = $account !== null
            && !$account->isDisabled()
            && $this->runtime->passwordHasher->verify($password, $account->passwordHash);

        if (!$valid) {
            $this->runtime->throttle->recordFailure($handle, $ip);

            return $this->loginResponse($session, 'Handle or password is incorrect.', 401, null, null, $returnTo);
        }

        if ($this->runtime->passwordHasher->needsRehash($account->passwordHash)) {
            $this->runtime->accounts->updatePasswordHash($account->id, $this->runtime->passwordHasher->hash($password));
        }

        $this->runtime->throttle->clear($handle, $ip);

        // Session fixation: the anonymous session is replaced, not upgraded.
        $issue = $this->runtime->sessions->issue($account, $rawCookie, $userAgent, $ip);

        return $this->redirect($returnTo !== '' ? $returnTo : '/', $issue);
    }

    private function logout(Request $request, ?AuthenticatedSession $session, ?string $rawCookie): Response
    {
        if (!$this->runtime->sessions->csrfMatches($session, $this->csrfToken($request))) {
            return $this->page('error', ['message' => 'The CSRF token is invalid.'], 'Sign out', 403);
        }

        $this->runtime->sessions->logout($rawCookie);

        return $this->redirect('/login', null, true);
    }

    private function home(?AuthenticatedSession $session): Response
    {
        if ($session?->account === null) {
            return $this->redirect('/login');
        }

        $account = $session->account;

        return $this->page('home', [
            'instance' => $this->runtime->config->instanceName,
            'instanceUrl' => $this->runtime->config->instanceUrl,
            'handle' => $account->handle,
            'name' => $account->name(),
            'isAdmin' => $account->isAdmin(),
            'actorUrl' => $this->runtime->config->instanceUrl . '/@' . rawurlencode($account->id),
            'csrf' => $session->session->csrfToken,
            'version' => \AlohaSocial\Server\Support\CoreVersion::current(),
        ], 'Overview');
    }

    /**
     * Routes outside the core need a session only for CSRF. A visitor without
     * a cookie gets an anonymous one, exactly like the login form does.
     *
     * @return array{0: AuthenticatedSession, 1: ?SessionIssue}
     */
    private function anonymousSession(
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): array {
        if ($session !== null) {
            return [$session, null];
        }

        $issue = $this->runtime->sessions->issue(null, $rawCookie, $userAgent, $ip);

        return [new AuthenticatedSession($issue->session, null), $issue];
    }

    private function forgotPasswordForm(
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);

        return $this->page('forgot-password', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session->session->csrfToken,
            'sent' => false,
            'error' => null,
            'value' => '',
        ], 'Reset password', 200, $issue);
    }

    private function forgotPassword(
        Request $request,
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);
        $params = $this->formParams($request);
        $value = trim((string) ($params['identifier'] ?? ''));

        if (!$this->runtime->sessions->csrfMatches($session, $this->csrfToken($request))) {
            return $this->page('forgot-password', [
                'instance' => $this->runtime->config->instanceName,
                'csrf' => $session->session->csrfToken,
                'sent' => false,
                'error' => 'Your session has expired. Please try again.',
                'value' => $value,
            ], 'Reset password', 403, $issue);
        }

        $retryAfter = $this->runtime->rateLimiter->attempt(
            'host.password_reset',
            $ip ?? $request->remoteAddress ?? 'unknown',
            5,
            900,
        );

        if ($retryAfter !== null) {
            return $this->page('forgot-password', [
                'instance' => $this->runtime->config->instanceName,
                'csrf' => $session->session->csrfToken,
                'sent' => false,
                'error' => 'Too many requests. Please try again in ' . $retryAfter . ' seconds.',
                'value' => $value,
            ], 'Reset password', 429, $issue);
        }

        $error = null;
        try {
            $this->runtime->passwordReset->request($value);
        } catch (MailException) {
            $error = 'The email could not be sent. Please try again later.';
        }

        return $this->page('forgot-password', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session->session->csrfToken,
            'sent' => $error === null,
            'error' => $error,
            'value' => $value,
        ], 'Reset password', $error === null ? 200 : 503, $issue);
    }

    private function resetPasswordForm(
        Request $request,
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);
        $token = (string) ($request->queryParam('token') ?? '');
        $valid = $token !== '' && $this->runtime->passwordReset->isValid($token);

        return $this->page('reset-password', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session->session->csrfToken,
            'token' => $token,
            'valid' => $valid,
            'error' => $valid ? null : 'The link is invalid or expired. Please request a new one.',
        ], 'New password', $valid ? 200 : 400, $issue);
    }

    private function resetPassword(
        Request $request,
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
    ): Response {
        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);
        $params = $this->formParams($request);
        $token = (string) ($params['token'] ?? '');
        $password = (string) ($params['password'] ?? '');
        $confirm = (string) ($params['password_confirm'] ?? '');

        $render = function (string $error, int $status) use ($session, $issue, $token): Response {
            return $this->page('reset-password', [
                'instance' => $this->runtime->config->instanceName,
                'csrf' => $session->session->csrfToken,
                'token' => $token,
                'valid' => false,
                'error' => $error,
            ], 'New password', $status, $issue);
        };

        if (!$this->runtime->sessions->csrfMatches($session, $this->csrfToken($request))) {
            return $render('Your session has expired. Please try again.', 403);
        }

        if ($password !== $confirm) {
            return $render('The passwords do not match.', 422);
        }

        try {
            $this->runtime->passwordReset->reset($token, $password);
        } catch (PasswordResetException $exception) {
            return $render($exception->getMessage(), 422);
        }

        return $this->redirect('/login?reset=1');
    }

    private function adminConsole(AuthenticatedSession $session): Response
    {
        $body = $this->runtime->view->render('admin', [
            'csrf' => $session->session->csrfToken,
            'handle' => $session->account?->handle ?? '',
            'instance' => $this->runtime->config->instanceName,
        ]);

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ], $body);
    }

    private function appPage(?AuthenticatedSession $session): Response
    {
        $account = $session?->account;

        return $this->page('app', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session?->session->csrfToken ?? '',
            'handle' => $account?->handle ?? '',
            'displayName' => $account?->name() ?? '',
            'isAdmin' => $account?->isAdmin() === true,
            'media' => $this->runtime->app->media !== null,
            // This UI capability currently gates the manual Bluesky publish
            // action, whose API is controlled by atprotoPublish. A native
            // PDS alone does not make that outbound action available.
            'atproto' => $this->runtime->config->atprotoPublish,
            'appShell' => true,
        ], 'App', 200, null, [
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' blob: https:; connect-src 'self'; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    private function loginResponse(
        ?AuthenticatedSession $session,
        ?string $error,
        int $status,
        ?SessionIssue $issue = null,
        ?string $notice = null,
        string $returnTo = '',
    ): Response {
        return $this->page('login', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session?->session->csrfToken ?? '',
            'error' => $error,
            'notice' => $notice,
            'handle' => '',
            'return_to' => $returnTo,
            'registrations' => $this->runtime->registration->enabled(),
        ], 'Sign in', $status, $issue);
    }

    private function safeReturnPath(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//') || str_contains($value, '\\')) {
            return '';
        }

        if (preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(
        string $template,
        array $data,
        string $title,
        int $status = 200,
        ?SessionIssue $issue = null,
        array $overrides = [],
    ): Response {
        $data['title'] = $title;
        $data['instance'] = $data['instance'] ?? $this->runtime->config->instanceName;
        $data['content'] = $this->runtime->view->render($template, $data);

        $body = $this->runtime->view->render('layout', $data);

        $headers = array_merge([
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ], $overrides);

        if ($issue !== null) {
            $headers['Set-Cookie'] = $this->cookie($issue);
        }

        return new Response($status, $headers, $body);
    }

    /**
     * @param array<string, string> $fields
     */
    private function apiError(int $status, string $code, string $message, array $fields = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return Response::json(['error' => $error], $status);
    }

    private function redirect(string $location, ?SessionIssue $issue = null, bool $clearCookie = false): Response
    {
        $headers = [
            'Location' => $location,
            'Cache-Control' => 'no-store',
        ];

        if ($issue !== null) {
            $headers['Set-Cookie'] = $this->cookie($issue);
        }

        if ($clearCookie) {
            $headers['Set-Cookie'] = $this->expiredCookie();
        }

        return new Response(303, $headers);
    }

    private function cookie(SessionIssue $issue): string
    {
        $parts = [
            $this->runtime->config->sessionCookieName . '=' . $issue->rawId,
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Max-Age=' . max(0, $issue->expiresAt - time()),
        ];

        if ($this->runtime->config->secureCookies) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function expiredCookie(): string
    {
        $parts = [
            $this->runtime->config->sessionCookieName . '=',
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Max-Age=0',
        ];

        if ($this->runtime->config->secureCookies) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function csrfToken(Request $request): string
    {
        $header = $request->header('x-csrf-token');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $value = $this->formParams($request)['_csrf'] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function formParams(Request $request): array
    {
        $contentType = strtolower((string) $request->header('content-type'));

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($request->body, $params);

            return is_array($params) ? $params : [];
        }

        return [];
    }
}
