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
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRecord;
use AlohaSocial\Core\Service\Atproto\AtprotoAccountNotConnectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoNotConfiguredException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Server\Interactions\AtprotoRemoteInteractions;
use AlohaSocial\Server\Accounts\RegistrationException;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Auth\PasswordResetException;
use AlohaSocial\Server\Auth\SessionIssue;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Federation\RemoteDirectoryException;
use AlohaSocial\Server\Federation\RemoteReplyException;
use AlohaSocial\Server\Mail\MailException;
use AlohaSocial\Server\Setup\SetupException;
use InvalidArgumentException;
use Throwable;

use function array_keys;
use function array_unique;
use function array_values;

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

    private readonly AtprotoOAuthController $atprotoOAuth;

    private readonly MastodonOAuthController $mastodonOAuth;

    private readonly MastodonStreamingController $mastodonStreaming;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
        $this->adminApi = new AdminApiController($runtime);
        $this->communityApi = new CommunityApiController($runtime);
        $this->federation = new FederationController($runtime);
        $this->atprotoIdentity = new AtprotoIdentityController($runtime);
        $this->atprotoSession = new AtprotoSessionController($runtime);
        $this->atprotoOAuth = new AtprotoOAuthController($runtime);
        $this->mastodonOAuth = new MastodonOAuthController($runtime);
        $this->mastodonStreaming = new MastodonStreamingController($runtime);
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
        $sessionUserId = $session?->userId();

        $request = $this->mastodonOAuth->authenticate($request);

        $streamingResponse = $this->mastodonStreaming->handle($request);
        if ($streamingResponse !== null) {
            return $streamingResponse;
        }

        // The native API owns this path for first-party clients, but a
        // Mastodon OAuth client expects the Mastodon status-list response.
        // Keep native sessions on the neutral contract and route bearer tokens
        // through the configured compatibility handler.
        if ($sessionUserId === null
            && ($request->attributes['mastodon_oauth'] ?? false) === true
            && $request->path === '/api/v1/timelines/home'
        ) {
            $request = new Request(
                method: $request->method,
                path: rtrim($this->runtime->config->mastodonPrefix, '/') . '/timelines/home',
                query: $request->query,
                headers: $request->headers,
                body: $request->body,
                attributes: $request->attributes,
                remoteAddress: $request->remoteAddress,
                userId: $request->userId,
                scopes: $request->scopes,
            );
        }

        $atprotoSessionResponse = $this->atprotoSession->handle($request);
        if ($atprotoSessionResponse !== null) {
            return $atprotoSessionResponse;
        }

        $request = $this->atprotoSession->authenticate($request);

        $deletionResponse = $this->rejectAccountDeletionAccess($request, $session);
        if ($deletionResponse !== null) {
            return $deletionResponse;
        }

        if ($request->method === 'GET' && $request->path === '/oauth/atproto/client-metadata.json') {
            return $this->atprotoOAuth->handle($request, $session) ?? Response::json([]);
        }

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

        if ($request->method === 'GET' && $request->path === '/api/v1/host/updates') {
            // This route is handled before the shared viewer assignment, so it
            // must resolve the signed-in account itself. Reading `$userId`
            // here used to emit an undefined-variable warning and answer 401
            // even for a valid session, which disabled live updates entirely.
            $viewerId = $session?->userId() ?? $request->userId;
            if ($viewerId === null) {
                return $this->apiError(401, 'not_authenticated', 'Sign in to follow live updates.');
            }

            $retryAfter = $this->runtime->rateLimiter->attempt('host.updates', $viewerId, 240, 60);
            if ($retryAfter !== null) {
                return Response::json(
                    [
                        'error' => [
                            'code' => 'rate_limited',
                            'message' => 'Too many update checks. Please wait a moment.',
                        ],
                    ],
                    429,
                    ['Retry-After' => (string) $retryAfter],
                );
            }

            $since = $request->queryParam('since');

            return Response::json($this->runtime->liveUpdates->changes(
                $viewerId,
                is_string($since) ? $since : null,
            ));
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
                $thread = $this->runtime->remoteDirectory->remotePost($id);

                // Keep the origin's public counters so feed cards can show the
                // real totals without fetching the thread again.
                $objectId = is_array($thread['post'] ?? null) && is_string($thread['post']['id'] ?? null)
                    ? (string) $thread['post']['id']
                    : $id;
                $thread = $this->withLocalReplies($thread, $id, $objectId, $session?->userId());
                $this->runtime->remoteEngagement->remember($objectId, $thread);

                return Response::json($thread);
            } catch (RemoteDirectoryException $exception) {
                // The store already has the post, but its origin is unreachable
                // (DNS failures are reported as 403 by the remote fetcher). A
                // cached card must still open instead of becoming a dead end in
                // the feed or in a notification. An authoritative 404 stays a
                // 404 so a deleted post is not resurrected from the cache.
                if ($exception->status !== 404) {
                    $cached = $this->presentRemotePost($id);
                    if ($cached !== null) {
                        return Response::json([
                            'network' => 'activitypub',
                            'post' => $cached,
                            'parent' => null,
                            'replies' => [],
                            'profile' => null,
                        ]);
                    }
                }

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

                if (AtprotoRemoteInteractions::supports($id)) {
                    if ($viewerId !== null && $this->runtime->atprotoRemoteInteractions !== null) {
                        try {
                            $remote[$id] = $this->runtime->atprotoRemoteInteractions->forUser($viewerId, $id);
                        } catch (AtprotoInteractionRejectedException) {
                            // Only currently followed, live and unblocked AT records are visible here.
                        }
                    }
                    continue;
                }

                if ($this->isRemoteObjectTarget($id)) {
                    $reactions = $this->runtime->app->reactions;
                    if ($viewerId !== null && mb_strlen($id) <= 2048 && $reactions !== null) {
                        try {
                            // The card state must exist for every cached post,
                            // including one whose author is not followed; the
                            // like action itself is no longer gated.
                            $remote[$id] = $this->remoteInteraction(
                                $this->runtime->remoteReactions->snapshotFor($viewerId, $id),
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

            // Every time a card is displayed it asks for its interaction
            // state. Use that request to refresh counters that are older than
            // the view TTL, bounded so a large page cannot fan out.
            if ($remote !== []) {
                $stale = $this->runtime->remoteEngagement->staleObjects(array_keys($remote), 60);
                if ($stale !== []) {
                    $this->runtime->remoteEngagementRefresher->refreshIds($stale, 4);
                }
            }

            return Response::json([
                'interactions' => array_replace(
                    $this->runtime->interactions->forPosts($visible, $viewerId),
                    $this->withRemoteEngagement($remote),
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

        // The federated timeline is the third public view a Nextcloud Social
        // reader expects next to the local and following feeds. It reads the
        // cached remote-post store, so paging it costs one bounded query and
        // never waits on a peer. An optional `tag` narrows it to posts that
        // mention a hashtag, which is what a federated hashtag page shows.
        if ($request->method === 'GET' && $request->path === '/api/v1/host/federated') {
            $before = $request->queryParam('before');
            $beforeId = $request->queryParam('before_id');
            $tag = $request->queryParam('tag');

            return Response::json($this->runtime->federatedTimeline->page(
                $request->queryInt('limit', 20, 1, 40),
                is_string($before) && $before !== '' ? $before : null,
                is_string($beforeId) && $beforeId !== '' ? $beforeId : null,
                is_string($tag) && $tag !== '' ? $tag : null,
            ));
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

        $userId = $sessionUserId ?? $request->userId;

        $protocolEndpoint = in_array($request->path, ['/api/v1/apps', '/oauth/token', '/oauth/revoke'], true);
        if (!$protocolEndpoint && $sessionUserId !== null && in_array($request->method, self::MUTATING_METHODS, true)) {
            $token = $this->csrfToken($request);
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

        $request = $this->linkDirectConversation($request, $userId);

        // The core validates a reply target against its local post table. A
        // remote inReplyTo therefore needs the host path, which stores the
        // post first and retargets its already queued Create atomically.
        if ($request->method === 'POST' && $request->path === '/api/v1/posts') {
            $replyBody = json_decode($request->body, true);
            if (is_array($replyBody)
                && is_string($replyBody['in_reply_to_id'] ?? null)
                && preg_match('#^https?://#i', trim($replyBody['in_reply_to_id'])) === 1
            ) {
                return $this->remoteReply($request, $userId);
            }
        }

        $mastodonOAuthResponse = $this->mastodonOAuth->handle($request, $session);
        if ($mastodonOAuthResponse !== null) {
            return $mastodonOAuthResponse;
        }

        $oauthResponse = $this->atprotoOAuth->handle($request, $session);
        if ($oauthResponse !== null) return $oauthResponse;

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

        if (preg_match('#^/api/v1/posts/([^/]+)/boost$#', $request->path, $matches) === 1
            && in_array($request->method, ['POST', 'DELETE'], true)
        ) {
            if ($userId === null) {
                return $this->apiError(401, 'not_authenticated', 'You must be signed in for this action.');
            }

            $rateLimited = $this->enforceIdentityRateLimit('host.post_boost', $userId, 60, 60);
            if ($rateLimited !== null) {
                return $rateLimited;
            }

            $postId = rawurldecode($matches[1]);
            $interactionBody = json_decode($request->body, true);
            $accountDid = is_array($interactionBody) && is_string($interactionBody['account_did'] ?? null)
                ? trim($interactionBody['account_did'])
                : null;
            if ($accountDid === '') {
                $accountDid = null;
            }
            $accountDid ??= $this->runtime->preferences->forAccount($userId)['mirror_atproto_account_did'];
            if (AtprotoRemoteInteractions::supports($postId)) {
                if ($this->runtime->atprotoRemoteInteractions === null) {
                    return $this->apiError(503, 'atproto_interactions_not_configured', 'AT Protocol interactions are not available.');
                }

                try {
                    $this->runtime->atprotoRemoteInteractions->change(
                        $userId,
                        $postId,
                        AtprotoInteractionRecord::REPOST,
                        $request->method === 'POST',
                        $accountDid,
                    );

                    return Response::json(['ok' => true]);
                } catch (AtprotoInteractionRejectedException $exception) {
                    return $this->atprotoInteractionError($exception);
                } catch (AtprotoAccountNotConnectedException) {
                    return $this->atprotoInteractionAccountError();
                } catch (AtprotoPublishDisabledException | AtprotoNotConfiguredException) {
                    return $this->apiError(503, 'atproto_interactions_not_configured', 'AT Protocol interactions are not available.');
                } catch (Throwable) {
                    return $this->apiError(502, 'atproto_interaction_failed', 'The AT Protocol interaction could not be published.');
                }
            }

            if ($this->isRemoteObjectTarget($postId)) {
                $reactions = $this->runtime->app->reactions;
                if ($reactions === null) {
                    return $this->apiError(503, 'remote_reactions_not_configured', 'Remote post interactions are not available.');
                }

                try {
                    // A public post may be boosted from discovery, even when
                    // its author is not followed. The host service keeps the
                    // signed Announce and resolves the inbox itself.
                    $objectId = $this->remoteObjectId($postId);
                    $interaction = $request->method === 'POST'
                        ? $this->runtime->remoteReactions->boost($userId, $objectId)
                        : $this->runtime->remoteReactions->unboost($userId, $objectId);

                    return Response::json([
                        'interaction' => $this->remoteInteractionWithCounts($interaction, $objectId),
                    ]);
                } catch (ReactionException $exception) {
                    [$status, $code, $message] = match ($exception->reason) {
                        'remote_post_not_found', 'remote_post_not_followed' => [404, 'not_found', 'Status not found.'],
                        'blocked_actor' => [403, 'blocked_actor', $exception->getMessage()],
                        'authorization_unavailable' => [503, 'authorization_unavailable', 'Remote interaction authorization is temporarily unavailable.'],
                        'remote_delivery_unreachable', 'remote_post_unreachable' => [502, $exception->reason, $exception->getMessage()],
                        default => [422, $exception->reason, $exception->getMessage()],
                    };

                    return $this->apiError($status, $code, $message);
                }
            }

            $boosts = $this->runtime->app->boosts;
            if ($boosts === null) {
                return $this->apiError(503, 'boosts_not_configured', 'Post boosts are not available.');
            }

            try {
                $atprotoMirror = null;
                if ($request->method === 'POST') {
                    $boosts->boost($userId, $postId);
                    $atprotoMirror = $this->runtime->interactions->mirrorBoost($userId, $postId, true, $accountDid);
                } else {
                    $boosts->unboost($userId, $postId);
                    $atprotoMirror = $this->runtime->interactions->mirrorBoost($userId, $postId, false, $accountDid);
                }
            } catch (PostNotFoundException) {
                return $this->apiError(404, 'post_not_found', 'This post is not available.');
            } catch (ValidationException $exception) {
                return $this->apiError(422, 'validation_failed', $exception->getMessage());
            }

            return Response::json($atprotoMirror === null
                ? ['ok' => true]
                : ['ok' => true, 'atproto_mirror' => $atprotoMirror]);
        }

        if (preg_match('#^/api/v1/posts/([^/]+)/like$#', $request->path, $matches) === 1
            && in_array($request->method, ['POST', 'DELETE'], true)
        ) {
            if ($userId === null) {
                return $this->apiError(401, 'not_authenticated', 'You must be signed in for this action.');
            }

            $rateLimited = $this->enforceIdentityRateLimit('host.post_like', $userId, 60, 60);
            if ($rateLimited !== null) {
                return $rateLimited;
            }

            $postId = rawurldecode($matches[1]);
            $interactionBody = json_decode($request->body, true);
            $accountDid = is_array($interactionBody) && is_string($interactionBody['account_did'] ?? null)
                ? trim($interactionBody['account_did'])
                : null;
            if ($accountDid === '') {
                $accountDid = null;
            }
            $accountDid ??= $this->runtime->preferences->forAccount($userId)['mirror_atproto_account_did'];
            if (AtprotoRemoteInteractions::supports($postId)) {
                if ($this->runtime->atprotoRemoteInteractions === null) {
                    return $this->apiError(503, 'atproto_interactions_not_configured', 'AT Protocol interactions are not available.');
                }

                try {
                    $result = $this->runtime->atprotoRemoteInteractions->change(
                        $userId,
                        $postId,
                        AtprotoInteractionRecord::LIKE,
                        $request->method === 'POST',
                        $accountDid,
                    );

                    return Response::json($result);
                } catch (AtprotoInteractionRejectedException $exception) {
                    return $this->atprotoInteractionError($exception);
                } catch (AtprotoAccountNotConnectedException) {
                    return $this->atprotoInteractionAccountError();
                } catch (AtprotoPublishDisabledException | AtprotoNotConfiguredException) {
                    return $this->apiError(503, 'atproto_interactions_not_configured', 'AT Protocol interactions are not available.');
                } catch (Throwable) {
                    return $this->apiError(502, 'atproto_interaction_failed', 'The AT Protocol interaction could not be published.');
                }
            }

            if ($this->isRemoteObjectTarget($postId)) {
                $reactions = $this->runtime->app->reactions;
                if ($reactions === null) {
                    return $this->apiError(503, 'remote_reactions_not_configured', 'Remote post reactions are not available.');
                }

                try {
                    $objectId = $this->remoteObjectId($postId);
                    $interaction = $request->method === 'POST'
                        ? $this->runtime->remoteReactions->like($userId, $objectId)
                        : $this->runtime->remoteReactions->unlike($userId, $objectId);

                    return Response::json([
                        'interaction' => $this->remoteInteractionWithCounts($interaction, $objectId),
                    ]);
                } catch (ReactionException $exception) {
                    [$status, $code, $message] = match ($exception->reason) {
                        'remote_post_not_found', 'remote_post_not_followed' => [404, 'not_found', 'Status not found.'],
                        'blocked_actor' => [403, 'blocked_actor', $exception->getMessage()],
                        'authorization_unavailable' => [503, 'authorization_unavailable', 'Remote interaction authorization is temporarily unavailable.'],
                        'remote_delivery_unreachable', 'remote_post_unreachable' => [502, $exception->reason, $exception->getMessage()],
                        default => [422, $exception->reason, $exception->getMessage()],
                    };

                    return $this->apiError($status, $code, $message);
                }
            }

            try {
                $interaction = $request->method === 'POST'
                    ? $this->runtime->interactions->like($userId, $postId, $accountDid)
                    : $this->runtime->interactions->unlike($userId, $postId, $accountDid);
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
        $authenticated = $sessionUserId !== null
            ? $request->withUserId($sessionUserId)->withScopes(['*'])
            : $request;

        $response = $this->federation->decorate(
            $request,
            $this->runtime->app->handle($authenticated),
        );
        $response = $this->withMastodonNotificationTargets($request, $response, $userId);

        // The core home timeline also returns the reader's own direct posts.
        // The first-party feed is public timeline content; private messages
        // belong to the direct timeline and its inbox. Strip them at the API
        // boundary so following/local cards can never show a DM.
        if ($request->method === 'GET'
            && $request->path === '/api/v1/timelines/home'
            && $sessionUserId !== null
        ) {
            $response = $this->withoutDirectMessages($response);
        }

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
     * Adds local replies to a remote conversation.
     *
     * The remote origin only knows the replies it received. A reply written
     * here is stored with the remote post URL as its parent, so without this
     * merge it is visible in the author's profile but disappears from the
     * thread as soon as the page is reloaded. Only replies the viewer may read
     * are included, and a failed query leaves the remote thread untouched.
     *
     * @param array<string, mixed> $thread
     *
     * @return array<string, mixed>
     */
    private function withLocalReplies(array $thread, string $requestedId, string $objectId, ?string $viewerId): array
    {
        if (($thread['network'] ?? '') !== 'activitypub' || !is_array($thread['post'] ?? null)) {
            return $thread;
        }

        try {
            $rows = $this->runtime->database->fetchAll(
                'SELECT id, visibility FROM social_post'
                . ' WHERE in_reply_to_id IN (:requested, :object) AND deleted_at IS NULL'
                . " AND (visibility IN ('public', 'unlisted') OR author_id = :viewer)"
                . ' ORDER BY published_at ASC, id ASC LIMIT 50',
                ['requested' => $requestedId, 'object' => $objectId, 'viewer' => $viewerId ?? ''],
            );
        } catch (Throwable) {
            return $thread;
        }

        $replies = is_array($thread['replies'] ?? null) ? array_values($thread['replies']) : [];
        $seen = [];
        foreach ($replies as $reply) {
            if (is_array($reply) && is_string($reply['id'] ?? null)) {
                $seen[$reply['id']] = true;
            }
        }

        $remoteCount = count($replies);
        $addedPublic = 0;
        foreach ($rows as $row) {
            $postId = is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($postId === '') {
                continue;
            }

            // A peer that processed our Create may already list the note as a
            // descendant. Prefer the remote representation instead of showing
            // the same reply twice.
            try {
                $noteUrl = $this->runtime->remoteReplies->noteUrlFor($postId);
            } catch (Throwable) {
                $noteUrl = '';
            }
            if ((isset($seen[$postId]) || ($noteUrl !== '' && isset($seen[$noteUrl])))) {
                continue;
            }

            try {
                $post = $this->runtime->app->posts->getVisible($postId, $viewerId);
            } catch (Throwable) {
                continue;
            }

            $replies[] = $this->runtime->app->media?->presentPost($post) ?? $post->toArray();
            $seen[$postId] = true;
            if ($noteUrl !== '') {
                $seen[$noteUrl] = true;
            }
            if (($row['visibility'] ?? null) === 'public' || ($row['visibility'] ?? null) === 'unlisted') {
                // Followers-only replies stay visible to their author, but
                // must not inflate the public counter cached for everyone.
                $addedPublic++;
            }
        }

        usort($replies, static function (mixed $left, mixed $right): int {
            $leftAt = is_array($left) && is_string($left['published_at'] ?? null) ? $left['published_at'] : '';
            $rightAt = is_array($right) && is_string($right['published_at'] ?? null) ? $right['published_at'] : '';

            return strcmp($leftAt, $rightAt);
        });

        $thread['replies'] = $replies;
        $reported = is_numeric($thread['post']['replies_count'] ?? null)
            ? (int) $thread['post']['replies_count']
            : $remoteCount;
        // The origin's total already counts its own replies; local public
        // replies are new to it and are added once.
        $thread['post']['replies_count'] = max($reported, $remoteCount) + $addedPublic;

        return $thread;
    }

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
        // Feed cards link to the object id, while the store is keyed by the
        // activity id. Resolve both so a cached post keeps opening through the
        // same URL the card and a notification use.
        $post = str_starts_with($id, 'at://')
            ? $repository->findPostByObjectId($id)
            : ($repository->findByActivityId($id) ?? $repository->findPostByObjectId($id));
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

        // A federated post carries its origin's public totals. Without them a
        // feed card can only show zero likes and zero replies, because the
        // local tables know nothing about reactions on another server.
        $engagement = $this->runtime->remoteEngagement->counts([$id, $post->objectId]);
        $counts = $engagement[$id] ?? $engagement[$post->objectId] ?? null;
        if ($counts !== null) {
            $presented['likes_count'] = $counts['likes'];
            $presented['boosts_count'] = $counts['boosts'];
            $presented['replies_count'] = $counts['replies'];
        }

        return $presented;
    }

    /**
     * Timelines address a cached remote post by its activity id, while the
     * core reaction service looks it up by object id. Both are the same post
     * to the reader, so resolve the alias before reacting.
     */
    private function remoteObjectId(string $id): string
    {
        if (str_starts_with($id, 'at://')
            || (!str_starts_with($id, 'http://') && !str_starts_with($id, 'https://'))
        ) {
            return $id;
        }

        $post = (new PdoRemotePostRepository($this->runtime->database))->findByActivityId($id);
        $objectId = $post?->objectId ?? '';

        return $objectId !== '' ? $objectId : $id;
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
     * A Bluesky like is a record in the member's own PDS repository. Without
     * a connected identity there is nowhere to publish it, so the card needs
     * an actionable answer instead of a generic 502.
     */
    private function atprotoInteractionAccountError(): Response
    {
        return $this->apiError(
            409,
            'atproto_account_not_connected',
            'Connect a Bluesky account in Settings to like or boost Bluesky posts.',
        );
    }

    private function atprotoInteractionError(AtprotoInteractionRejectedException $exception): Response
    {
        if ($exception->reason === AtprotoInteractionRejectedException::BLOCKED_ACTOR) {
            return $this->apiError(403, 'blocked_actor', $exception->getMessage());
        }

        return $this->apiError(404, 'atproto_post_unavailable', 'This AT Protocol post is not available.');
    }

    /**
     * @param array<string, mixed> $status
     * @return array{favourites_count: int, favourited: bool, reblogs_count: int, reblogged: bool, replies_count: int, emoji_reactions: list<array{emoji: string, count: int}>}
     */
    private function remoteInteraction(array $status): array
    {
        $viewer = is_array($status['viewer'] ?? null) ? $status['viewer'] : [];
        $favourited = ($viewer['favourited'] ?? false) === true;
        $reblogged = ($viewer['reblogged'] ?? false) === true;

        // The viewer's own reaction is a fact even when the origin total is
        // unknown or stale. Showing 0 likes on a card the viewer just liked
        // contradicts the pressed button; the export is never below one then.
        return [
            'favourites_count' => $favourited ? 1 : 0,
            'favourited' => $favourited,
            'reblogs_count' => $reblogged ? 1 : 0,
            'reblogged' => $reblogged,
            'replies_count' => 0,
            'emoji_reactions' => [],
        ];
    }

    /**
     * The action response a remote like or boost returns: viewer state plus
     * the cached origin totals, so the card does not fall back to zero.
     *
     * @param array<string, mixed> $interaction
     *
     * @return array<string, mixed>
     */
    private function remoteInteractionWithCounts(array $interaction, string $objectId): array
    {
        $result = $this->remoteInteraction($interaction);
        $cached = $this->runtime->remoteEngagement->counts([$objectId])[$objectId] ?? null;
        if ($cached !== null) {
            $result['favourites_count'] = max((int) $cached['likes'], $result['favourited'] ? 1 : 0);
            $result['reblogs_count'] = max((int) $cached['boosts'], $result['reblogged'] ? 1 : 0);
            $result['replies_count'] = $cached['replies'];

            return $result;
        }

        $result['favourites_count'] = (int) ($interaction['favourites_count'] ?? 0);
        $result['reblogs_count'] = (int) ($interaction['reblogs_count'] ?? 0);

        return $result;
    }

    /**
     * Add the origin's public totals to the viewer's remote interaction
     * snapshot. The core only knows whether the viewer liked or boosted the
     * post, so without the cache a card shows "1" for a post with 400 likes.
     *
     * @param array<string, array<string, mixed>> $remote
     *
     * @return array<string, array<string, mixed>>
     */
    private function withRemoteEngagement(array $remote): array
    {
        if ($remote === []) {
            return $remote;
        }

        // Cards address a post by its activity id, the cache stores the
        // object id, and AT records use the same uri for both.
        $aliases = [];
        $lookup = [];
        foreach (array_keys($remote) as $id) {
            $aliases[$id] = $this->remoteObjectId($id);
            $lookup[] = $id;
            $lookup[] = $aliases[$id];
        }

        $counts = $this->runtime->remoteEngagement->counts(array_values(array_unique($lookup)));
        foreach ($remote as $id => $interaction) {
            $cached = $counts[$id] ?? $counts[$aliases[$id]] ?? null;
            if ($cached === null) {
                continue;
            }

            $remote[$id]['favourites_count'] = max(
                (int) $cached['likes'],
                ($remote[$id]['favourited'] ?? false) === true ? 1 : 0,
            );
            $remote[$id]['reblogs_count'] = max(
                (int) $cached['boosts'],
                ($remote[$id]['reblogged'] ?? false) === true ? 1 : 0,
            );
            $remote[$id]['replies_count'] = $cached['replies'];
        }

        return $remote;
    }

    /**
     * Keeps a new direct message inside the existing conversation.
     *
     * The core gives every post without a parent its own conversation root.
     * The composer now supplies the latest message, but an API client could
     * still create a second root for the same participants. Linking the new
     * message before the core stores it makes the one-inbox-row invariant a
     * server rule rather than a property of one UI path.
     */
    private function linkDirectConversation(Request $request, ?string $userId): Request
    {
        if ($userId === null
            || $request->method !== 'POST'
            || $request->path !== '/api/v1/posts'
        ) {
            return $request;
        }

        $body = json_decode($request->body, true);
        if (!is_array($body)
            || ($body['visibility'] ?? null) !== 'direct'
            || (is_string($body['in_reply_to_id'] ?? null) && trim($body['in_reply_to_id']) !== '')
        ) {
            return $request;
        }

        $rawRecipients = $body['recipient_ids'] ?? null;
        if (!is_array($rawRecipients) || $rawRecipients === []) {
            return $request;
        }
        $recipients = [];
        foreach ($rawRecipients as $recipient) {
            if (is_string($recipient) && trim($recipient) !== '') {
                $recipients[] = trim($recipient);
            }
        }
        if ($recipients === []) {
            return $request;
        }

        $participants = array_values(array_unique(array_merge([$userId], $recipients)));
        sort($participants, SORT_STRING);

        $rows = $this->runtime->database->fetchAll(
            'SELECT post.id, post.author_id, recipient.recipient_id'
            . ' FROM social_post post'
            . ' LEFT JOIN social_post_recipient recipient ON recipient.post_id = post.id'
            . ' WHERE post.visibility = :visibility AND post.deleted_at IS NULL'
            . ' AND (post.author_id = :author_view OR EXISTS ('
            . ' SELECT 1 FROM social_post_recipient visible'
            . ' WHERE visible.post_id = post.id AND visible.recipient_id = :recipient_view))'
            . ' ORDER BY post.published_at DESC, post.id DESC',
            [
                'visibility' => 'direct',
                'author_view' => $userId,
                'recipient_view' => $userId,
            ],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $postId = (string) ($row['id'] ?? '');
            $authorId = (string) ($row['author_id'] ?? '');
            if ($postId === '' || $authorId === '') {
                continue;
            }
            $grouped[$postId]['author'] = $authorId;
            $recipientId = $row['recipient_id'] ?? null;
            if (is_string($recipientId) && $recipientId !== '') {
                $grouped[$postId]['recipients'][] = $recipientId;
            }
        }

        $latest = null;
        foreach ($grouped as $postId => $conversation) {
            $members = array_values(array_unique(array_merge(
                [(string) $conversation['author']],
                $conversation['recipients'] ?? [],
            )));
            sort($members, SORT_STRING);
            if ($members === $participants) {
                $latest = $postId;
                break;
            }
        }
        if ($latest === null) {
            return $request;
        }

        $body['in_reply_to_id'] = $latest;

        return new Request(
            method: $request->method,
            path: $request->path,
            query: $request->query,
            headers: $request->headers,
            body: json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            attributes: $request->attributes,
            remoteAddress: $request->remoteAddress,
            userId: $request->userId,
            scopes: $request->scopes,
        );
    }

    /**
     * Keeps the target id on a Mastodon notification when the status itself
     * cannot be projected.
     *
     * The compatibility presenter omits `status` for a remote notification
     * whose origin is unreachable, which left the first-party UI without any
     * link to the post. `post_id` is the durable target the core notification
     * already stores; adding it here keeps the notification actionable without
     * inventing a status shape.
     */
    private function withMastodonNotificationTargets(Request $request, Response $response, ?string $userId): Response
    {
        if ($userId === null
            || $request->method !== 'GET'
            || $request->path !== rtrim($this->runtime->config->mastodonPrefix, '/') . '/notifications'
            || $response->status !== 200
            || $response->body === ''
        ) {
            return $response;
        }

        $rows = json_decode($response->body, true);
        if (!is_array($rows) || $rows === []) {
            return $response;
        }

        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '') {
                $ids[] = $row['id'];
            }
        }
        if ($ids === []) {
            return $response;
        }

        $parameters = ['recipient' => $userId];
        $placeholders = [];
        foreach (array_values(array_unique($ids)) as $index => $id) {
            $name = 'id_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }
        $stored = $this->runtime->database->fetchAll(
            'SELECT id, post_id FROM social_notification'
            . ' WHERE recipient_id = :recipient AND id IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        );
        $targets = [];
        foreach ($stored as $row) {
            $id = (string) ($row['id'] ?? '');
            $postId = $row['post_id'] ?? null;
            if ($id !== '' && is_string($postId) && $postId !== '') {
                $targets[$id] = $postId;
            }
        }

        $changed = false;
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) {
                continue;
            }
            $postId = $targets[$row['id']] ?? null;
            if ($postId === null) {
                continue;
            }
            $rows[$index]['post_id'] = $postId;
            $changed = true;
        }

        return $changed
            ? new Response(
                $response->status,
                $response->headers,
                json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            )
            : $response;
    }

    /**
     * Removes direct posts from a timeline response without touching the
     * private direct timeline. Pagination is retargeted to the last visible
     * post so a filtered page cannot make the reader skip older entries.
     */
    private function withoutDirectMessages(Response $response): Response
    {
        if ($response->status !== 200 || $response->body === '') {
            return $response;
        }

        $data = json_decode($response->body, true);
        if (!is_array($data) || !is_array($data['posts'] ?? null)) {
            return $response;
        }

        $posts = array_values(array_filter(
            $data['posts'],
            static fn (mixed $post): bool => !is_array($post) || ($post['visibility'] ?? null) !== 'direct',
        ));
        if (count($posts) === count($data['posts'])) {
            return $response;
        }

        $data['posts'] = $posts;
        $last = $posts === [] ? null : $posts[array_key_last($posts)];
        if (is_array($last)) {
            $data['next_before'] = $last['published_at'] ?? ($data['next_before'] ?? null);
            $data['next_before_id'] = $last['id'] ?? ($data['next_before_id'] ?? null);
        }

        return Response::json($data, 200, $response->headers);
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

        return $this->enforceIdentityRateLimit($match->route->name, $identity, $limit, $window);
    }

    private function rejectAccountDeletionAccess(Request $request, ?AuthenticatedSession $session): ?Response
    {
        if ($request->path === '/logout'
            || ($request->method === 'DELETE' && $request->path === '/api/v1/account/data')
        ) {
            return null;
        }

        $userId = $session?->userId() ?? $request->userId;
        if ($userId === null || $this->runtime->app->accountData === null) {
            return null;
        }

        $deletion = $this->runtime->app->accountData->deletionStatus($userId);
        $status = $deletion->blockedStatus();
        if ($status === null) {
            return null;
        }

        $deleted = $status === 410;
        return Response::json([
            'error' => [
                'code' => $deleted ? 'account_deleted' : 'account_deletion_in_progress',
                'message' => $deleted
                    ? 'This account has been deleted; further access is blocked.'
                    : 'This account is being deleted; access is temporarily blocked.',
            ],
        ], $status);
    }

    /**
     * A reply whose parent lives on another server.
     *
     * The input contract mirrors the core's POST /api/v1/posts, so the UI and
     * native clients keep sending the same body; only the parent resolution
     * differs. Nothing is written when the remote author's inbox cannot be
     * resolved, which keeps a comment from existing locally without reaching
     * the conversation it belongs to.
     */
    private function remoteReply(Request $request, ?string $userId): Response
    {
        if ($userId === null) {
            return $this->apiError(401, 'not_authenticated', 'You must be signed in for this action.');
        }

        $limited = $this->enforceIdentityRateLimit('host.remote_reply', $userId, 30, 60);
        if ($limited !== null) {
            return $limited;
        }

        $body = json_decode($request->body, true);
        if (!is_array($body)) {
            return $this->apiError(422, 'validation_failed', 'The request body must be a JSON object.');
        }

        $target = $body['in_reply_to_id'] ?? null;
        if (!is_string($target) || trim($target) === '') {
            return $this->apiError(422, 'validation_failed', 'in_reply_to_id must be a remote post URL.', [
                'in_reply_to_id' => 'Please provide the post you are replying to.',
            ]);
        }

        $content = $body['content'] ?? '';
        if (!is_string($content)) {
            return $this->apiError(422, 'validation_failed', 'content must be a string.', [
                'content' => 'content must be a string.',
            ]);
        }

        $contentWarning = $body['content_warning'] ?? null;
        if ($contentWarning !== null && !is_string($contentWarning)) {
            return $this->apiError(422, 'validation_failed', 'content_warning must be a string or null.', [
                'content_warning' => 'content_warning must be a string or null.',
            ]);
        }

        $language = $body['language'] ?? null;
        if ($language !== null && !is_string($language)) {
            return $this->apiError(422, 'validation_failed', 'language must be a string or null.', [
                'language' => 'language must be a string or null.',
            ]);
        }

        $rawAttachmentIds = $body['attachment_ids'] ?? [];
        if (!is_array($rawAttachmentIds) || !array_is_list($rawAttachmentIds)) {
            return $this->apiError(422, 'validation_failed', 'attachment_ids must be a list of strings.', [
                'attachment_ids' => 'attachment_ids must be a list of strings.',
            ]);
        }

        $attachmentIds = [];
        foreach ($rawAttachmentIds as $attachmentId) {
            if (!is_string($attachmentId) || trim($attachmentId) === '') {
                return $this->apiError(422, 'validation_failed', 'attachment_ids must be a list of strings.', [
                    'attachment_ids' => 'attachment_ids must be a list of strings.',
                ]);
            }
            $attachmentIds[] = $attachmentId;
        }

        $rawVisibility = $body['visibility'] ?? 'public';
        if (!is_string($rawVisibility)) {
            return $this->apiError(422, 'validation_failed', 'visibility must be a string.', [
                'visibility' => 'visibility must be a string.',
            ]);
        }

        try {
            $visibility = Visibility::fromString($rawVisibility);
        } catch (InvalidArgumentException $exception) {
            return $this->apiError(422, 'validation_failed', $exception->getMessage(), [
                'visibility' => $exception->getMessage(),
            ]);
        }

        if ($visibility === Visibility::Direct) {
            return $this->apiError(422, 'validation_failed', 'A reply to a remote post cannot be a direct message.', [
                'visibility' => 'Choose public, unlisted or followers-only.',
            ]);
        }

        $quotedPostId = $body['quoted_post_id'] ?? null;
        if ($quotedPostId !== null && !is_string($quotedPostId)) {
            return $this->apiError(422, 'validation_failed', 'quoted_post_id must be a string or null.', [
                'quoted_post_id' => 'quoted_post_id must be a string or null.',
            ]);
        }

        try {
            $post = $this->runtime->remoteReplies->create(
                $userId,
                $target,
                $content,
                $visibility,
                $contentWarning === null ? null : trim($contentWarning),
                $attachmentIds,
                $quotedPostId === null ? null : trim($quotedPostId),
                $language === null ? null : trim($language),
            );
        } catch (ValidationException $exception) {
            return $this->apiError(422, 'validation_failed', $exception->getMessage(), $exception->fields());
        } catch (RemoteReplyException $exception) {
            return $this->apiError($exception->status, $exception->errorCode, $exception->getMessage());
        } catch (Throwable) {
            return $this->apiError(502, 'remote_reply_failed', 'The reply could not be delivered to the remote server.');
        }

        $shape = $this->runtime->app->media?->presentPost($post) ?? $post->toArray();

        return Response::json(['post' => $shape], 201, [
            'Location' => '/api/v1/posts/' . rawurlencode($post->id),
        ]);
    }

    /**
     * The core declares a budget per route and leaves enforcement to the
     * host. The identity is the signed-in account, or the client address for
     * anonymous requests.
     */
    private function enforceIdentityRateLimit(string $route, string $identity, int $limit, int $window): ?Response
    {
        $retryAfter = $this->runtime->rateLimiter->attempt($route, $identity, $limit, $window);

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

        return $this->page('signup', [
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
        $deletionStatus = $account === null
            ? null
            : $this->runtime->app->accountData?->deletionStatus($account->id)->blockedStatus();

        $valid = $account !== null
            && !$account->isDisabled()
            && $deletionStatus === null
            && $this->runtime->passwordHasher->verify($password, $account->passwordHash);

        if (!$valid) {
            $this->runtime->throttle->recordFailure($handle, $ip);

            return $this->loginResponse($session, 'Handle or password is incorrect.', 401, null, null, $returnTo);
        }

        if ($this->runtime->passwordHasher->needsRehash($account->passwordHash)) {
            $this->runtime->accounts->rehashPasswordHash($account->id, $this->runtime->passwordHasher->hash($password));
        }

        $this->runtime->throttle->clear($handle, $ip);

        // Session fixation: the anonymous session is replaced, not upgraded.
        $issue = $this->runtime->sessions->issue($account, $rawCookie, $userAgent, $ip);

        // A signed-in member expects the interface, not the technical
        // overview page. Keep an explicit `next` target for deep links.
        return $this->redirect($returnTo !== '' ? $returnTo : '/app', $issue);
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
            // A configured publisher is not enough: only advertise this
            // action when this signed-in account has an active Bluesky link.
            // A native PDS alone does not make external publishing available.
            'atproto' => $account !== null
                && $this->runtime->config->atprotoPublish
                && ($this->runtime->blueskyAccounts->status($account->id)['connected'] ?? false) === true,
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
