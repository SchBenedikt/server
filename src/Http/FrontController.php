<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Auth\PasswordResetException;
use AlohaSocial\Server\Auth\SessionIssue;
use AlohaSocial\Server\Bootstrap\Runtime;
use AlohaSocial\Server\Mail\MailException;

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

    public function __construct(
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @param array<string, string> $cookies the request's cookies
     */
    public function handle(Request $request, array $cookies = [], ?string $userAgent = null, ?string $ip = null): Response
    {
        $cookieName = $this->runtime->config->sessionCookieName;
        $rawCookie = $cookies[$cookieName] ?? null;
        $rawCookie = is_string($rawCookie) && $rawCookie !== '' ? $rawCookie : null;

        $session = $this->runtime->sessions->resolve($rawCookie);

        if ($request->method === 'GET' && $request->path === '/login') {
            return $this->loginForm(
                $session,
                $rawCookie,
                $userAgent,
                $ip,
                $request->queryParam('reset') === '1'
                    ? 'Dein Passwort wurde geändert. Bitte melde dich neu an.'
                    : null,
            );
        }

        if ($request->method === 'POST' && $request->path === '/login') {
            return $this->login($request, $session, $rawCookie, $userAgent, $ip);
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

        if ($request->method === 'GET' && $request->path === '/favicon.ico') {
            return Response::empty(204);
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/profiles') {
            return Response::json([
                'profiles' => $this->runtime->accounts->displayNames(),
            ]);
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/interactions') {
            $ids = array_slice(explode(',', (string) ($request->queryParam('ids') ?? '')), 0, 80);

            return Response::json([
                'interactions' => $this->runtime->interactions->forPosts($ids, $session?->userId()),
            ]);
        }

        if ($request->method === 'GET' && $request->path === '/api/v1/host/posts') {
            $ids = array_slice(explode(',', (string) ($request->queryParam('ids') ?? '')), 0, 80);
            $posts = [];
            foreach ($ids as $id) {
                $id = trim($id);
                if ($id === '' || mb_strlen($id) > 64) {
                    continue;
                }

                try {
                    $post = $this->runtime->app->posts->getVisible($id, $session?->userId());
                } catch (PostNotFoundException) {
                    continue;
                }

                $posts[$id] = $this->runtime->app->media?->presentPost($post) ?? $post->toArray();
            }

            return Response::json(['posts' => $posts]);
        }

        $userId = $session?->userId();

        if ($userId !== null && in_array($request->method, self::MUTATING_METHODS, true)) {
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

        if ($request->method === 'PATCH' && $request->path === '/api/v1/host/profile') {
            if ($userId === null) {
                return $this->apiError(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
            }

            $body = json_decode($request->body, true);
            if (!is_array($body) || !array_key_exists('display_name', $body) || !is_string($body['display_name'])) {
                return $this->apiError(422, 'invalid_profile', 'Der Anzeigename fehlt.', ['display_name' => 'Bitte gib einen Anzeigenamen ein.']);
            }

            $displayName = trim($body['display_name']);
            if (mb_strlen($displayName) > 80) {
                return $this->apiError(422, 'invalid_profile', 'Der Anzeigename ist zu lang.', ['display_name' => 'Höchstens 80 Zeichen.']);
            }

            $this->runtime->accounts->updateDisplayName($userId, $displayName === '' ? null : $displayName);
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
                return $this->apiError(401, 'not_authenticated', 'Für diese Aktion musst du angemeldet sein.');
            }

            $postId = rawurldecode($matches[1]);
            try {
                $interaction = $request->method === 'POST'
                    ? $this->runtime->interactions->like($userId, $postId)
                    : $this->runtime->interactions->unlike($userId, $postId);
            } catch (PostNotFoundException) {
                return $this->apiError(404, 'post_not_found', 'Dieser Beitrag ist nicht verfügbar.');
            }

            return Response::json(['interaction' => $interaction]);
        }

        $rateLimited = $this->enforceRateLimit($request, $userId);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        return $this->runtime->app->handle($request->withUserId($userId));
    }

    /**
     * The core declares a budget per route and leaves enforcement to the
     * host. The identity is the signed-in account, or the client address for
     * anonymous requests.
     */
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

    private function loginForm(
        ?AuthenticatedSession $session,
        ?string $rawCookie,
        ?string $userAgent,
        ?string $ip,
        ?string $notice = null,
    ): Response {
        if ($session?->isSignedIn() === true) {
            return $this->redirect('/');
        }

        [$session, $issue] = $this->anonymousSession($session, $rawCookie, $userAgent, $ip);

        return $this->loginResponse($session, null, 200, $issue, $notice);
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

        if (!$this->runtime->sessions->csrfMatches($session, $csrf)) {
            $issue = null;
            if ($session === null) {
                $issue = $this->runtime->sessions->issue(null, $rawCookie, $userAgent, $ip);
                $session = new AuthenticatedSession($issue->session, null);
            }

            return $this->loginResponse($session, 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.', 403, $issue);
        }

        if ($handle === '' || $password === '') {
            return $this->loginResponse($session, 'Handle und Passwort sind erforderlich.', 422);
        }

        if ($this->runtime->throttle->isLimited($handle, $ip)) {
            return $this->loginResponse($session, 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.', 429);
        }

        $account = $this->runtime->accounts->findByHandle($handle)
            ?? $this->runtime->accounts->findByEmail($handle);

        $valid = $account !== null
            && !$account->isDisabled()
            && $this->runtime->passwordHasher->verify($password, $account->passwordHash);

        if (!$valid) {
            $this->runtime->throttle->recordFailure($handle, $ip);

            return $this->loginResponse($session, 'Handle oder Passwort ist falsch.', 401);
        }

        if ($this->runtime->passwordHasher->needsRehash($account->passwordHash)) {
            $this->runtime->accounts->updatePasswordHash($account->id, $this->runtime->passwordHasher->hash($password));
        }

        $this->runtime->throttle->clear($handle, $ip);

        // Session fixation: the anonymous session is replaced, not upgraded.
        $issue = $this->runtime->sessions->issue($account, $rawCookie, $userAgent, $ip);

        return $this->redirect('/', $issue);
    }

    private function logout(Request $request, ?AuthenticatedSession $session, ?string $rawCookie): Response
    {
        if (!$this->runtime->sessions->csrfMatches($session, $this->csrfToken($request))) {
            return $this->page('error', ['message' => 'Das CSRF-Token ist ungültig.'], 'Abmelden', 403);
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
        ], 'Übersicht');
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
        ], 'Passwort zurücksetzen', 200, $issue);
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
                'error' => 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.',
                'value' => $value,
            ], 'Passwort zurücksetzen', 403, $issue);
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
                'error' => 'Zu viele Anfragen. Bitte in ' . $retryAfter . ' Sekunden erneut versuchen.',
                'value' => $value,
            ], 'Passwort zurücksetzen', 429, $issue);
        }

        $error = null;
        try {
            $this->runtime->passwordReset->request($value);
        } catch (MailException) {
            $error = 'Die E-Mail konnte nicht versendet werden. Bitte später erneut versuchen.';
        }

        return $this->page('forgot-password', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session->session->csrfToken,
            'sent' => $error === null,
            'error' => $error,
            'value' => $value,
        ], 'Passwort zurücksetzen', $error === null ? 200 : 503, $issue);
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
            'error' => $valid ? null : 'Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.',
        ], 'Neues Passwort', $valid ? 200 : 400, $issue);
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
            ], 'Neues Passwort', $status, $issue);
        };

        if (!$this->runtime->sessions->csrfMatches($session, $this->csrfToken($request))) {
            return $render('Die Sitzung ist abgelaufen. Bitte erneut versuchen.', 403);
        }

        if ($password !== $confirm) {
            return $render('Die Passwörter stimmen nicht überein.', 422);
        }

        try {
            $this->runtime->passwordReset->reset($token, $password);
        } catch (PasswordResetException $exception) {
            return $render($exception->getMessage(), 422);
        }

        return $this->redirect('/login?reset=1');
    }

    private function appPage(?AuthenticatedSession $session): Response
    {
        $account = $session?->account;

        return $this->page('app', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session?->session->csrfToken ?? '',
            'handle' => $account?->handle ?? '',
            'displayName' => $account?->name() ?? '',
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
    ): Response {
        return $this->page('login', [
            'instance' => $this->runtime->config->instanceName,
            'csrf' => $session?->session->csrfToken ?? '',
            'error' => $error,
            'notice' => $notice,
            'handle' => '',
        ], 'Anmelden', $status, $issue);
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
