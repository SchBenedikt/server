<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Http;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Server\Auth\AuthenticatedSession;
use AlohaSocial\Server\Auth\MastodonOAuthRepository;
use AlohaSocial\Server\Bootstrap\Runtime;
use Throwable;

/** First-party OAuth 2 authorization-code/PKCE endpoints for Mastodon clients. */
final class MastodonOAuthController
{
    private const SUPPORTED_SCOPES = [
        'read', 'write', 'follow', 'read:accounts', 'read:bookmarks', 'read:filters',
        'read:follows', 'read:lists', 'read:mutes', 'read:notifications', 'read:search',
        'read:statuses', 'write:bookmarks', 'write:favourites', 'write:filters',
        'write:follows', 'write:lists', 'write:media', 'write:mutes',
        'write:notifications', 'write:statuses',
    ];

    private const ACCESS_TTL = 2_592_000;

    private const REFRESH_TTL = 7_776_000;

    private readonly MastodonOAuthRepository $oauth;

    public function __construct(private readonly Runtime $runtime)
    {
        $this->oauth = new MastodonOAuthRepository($runtime->database);
    }

    public function handle(Request $request, ?AuthenticatedSession $session): ?Response
    {
        $matches = ($request->method === 'POST' && $request->path === '/api/v1/apps')
            || ($request->path === '/oauth/authorize' && in_array($request->method, ['GET', 'POST'], true))
            || ($request->method === 'POST' && in_array($request->path, ['/oauth/token', '/oauth/revoke'], true));
        if (!$matches) {
            return null;
        }
        if (!$this->runtime->config->mastodonApi) {
            return $this->oauthError(503, 'mastodon_api_disabled', 'Mastodon-compatible OAuth is disabled on this instance.');
        }

        if ($request->method === 'POST' && $request->path === '/api/v1/apps') {
            return $this->registerApp($request);
        }
        if ($request->path === '/oauth/authorize' && $request->method === 'GET') {
            return $this->authorizePage($request, $session);
        }
        if ($request->path === '/oauth/authorize' && $request->method === 'POST') {
            return $this->authorize($request, $session);
        }
        if ($request->path === '/oauth/token' && $request->method === 'POST') {
            return $this->token($request);
        }
        if ($request->path === '/oauth/revoke' && $request->method === 'POST') {
            return $this->revoke($request);
        }

        return null;
    }

    /** Authenticate the compatibility prefix and standard API aliases, preserving granted scopes. */
    public function authenticate(Request $request): Request
    {
        $mastodonPrefix = rtrim($this->runtime->config->mastodonPrefix, '/');
        $isMastodonApiPath = str_starts_with($request->path, $mastodonPrefix . '/')
            || $request->path === '/api/v1/timelines/home'
            || $request->path === '/api/v1/streaming';
        if (!$this->runtime->config->mastodonApi || !$isMastodonApiPath) {
            return $request;
        }
        $authorization = trim((string) $request->header('authorization'));
        if ($authorization === '' || preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,128})$/i', $authorization, $matches) !== 1) {
            return $request;
        }

        $grant = $this->oauth->accountForAccess(hash('sha256', $matches[1]), Time::format($this->runtime->clock->now()));
        if ($grant === null) {
            return $request;
        }
        $account = $this->runtime->accounts->find($grant['account_id']);
        if ($account === null || $account->isDisabled()) {
            return $request;
        }
        if ($this->runtime->app->accountData?->deletionStatus($grant['account_id'])->blockedStatus() !== null) {
            return $request;
        }

        return $request
            ->withUserId($grant['account_id'])
            ->withScopes($grant['scopes'])
            ->withAttribute('mastodon_oauth', true);
    }

    private function registerApp(Request $request): Response
    {
        $limited = $this->enforceOAuthRateLimit($request, 'client_register', $request->remoteAddress ?? 'unknown', 10, 3600);
        if ($limited !== null) {
            return $limited;
        }
        $input = $this->input($request);
        $name = trim($this->string($input['client_name'] ?? null));
        $redirectUris = $this->redirectUris($input['redirect_uris'] ?? null);
        $scopes = $this->scopes($input['scopes'] ?? 'read');
        $website = $this->website($input['website'] ?? null);
        if ($name === '' || mb_strlen($name) > 200 || $redirectUris === null || $scopes === null || $website === false) {
            return $this->oauthError(422, 'invalid_request', 'Application name, redirect URIs, scopes or website are invalid.');
        }

        $clientId = bin2hex(random_bytes(32));
        $secret = bin2hex(random_bytes(32));
        try {
            $this->oauth->createApp(
                $clientId,
                hash('sha256', $secret),
                $name,
                $redirectUris,
                $scopes,
                $website,
                Time::format($this->runtime->clock->now()),
            );
        } catch (Throwable) {
            return $this->oauthError(503, 'server_error', 'The application could not be registered.');
        }

        return Response::json([
            'id' => $clientId,
            'name' => $name,
            'website' => $website,
            'redirect_uri' => implode("\n", $redirectUris),
            'client_id' => $clientId,
            'client_secret' => $secret,
        ], 200);
    }

    private function authorizePage(Request $request, ?AuthenticatedSession $session): Response
    {
        $params = $request->query;
        $validated = $this->authorizationParams($params);
        if ($validated === null) {
            return $this->oauthError(400, 'invalid_request', 'The authorization request is invalid.');
        }
        if ($session?->userId() === null) {
            $next = '/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            return new Response(303, ['Location' => '/login?next=' . rawurlencode($next), 'Cache-Control' => 'no-store']);
        }

        $app = $this->oauth->app($validated['client_id']);
        if ($app === null || !in_array($validated['redirect_uri'], $app['redirect_uris'], true)
            || array_diff($validated['scopes'], $app['scopes']) !== []) {
            return $this->oauthError(400, 'invalid_client', 'The client, redirect URI or requested scopes are not registered.');
        }

        $scopes = htmlspecialchars(implode(' ', $validated['scopes']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = htmlspecialchars($app['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $challenge = htmlspecialchars($validated['code_challenge'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $state = htmlspecialchars($validated['state'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $clientId = htmlspecialchars($validated['client_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $redirectUri = htmlspecialchars($validated['redirect_uri'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = htmlspecialchars($session->session->csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>Authorize ' . $name . '</title><main><h1>Authorize ' . $name . '?</h1>'
            . '<p>This app requests access to your Aloha Social account.</p><p>Requested scopes: <code>' . $scopes . '</code></p>'
            . '<form method="post" action="/oauth/authorize"><input type="hidden" name="_csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="client_id" value="' . $clientId . '">'
            . '<input type="hidden" name="redirect_uri" value="' . $redirectUri . '">'
            . '<input type="hidden" name="scope" value="' . $scopes . '">'
            . '<input type="hidden" name="code_challenge" value="' . $challenge . '">'
            . '<input type="hidden" name="code_challenge_method" value="S256">'
            . '<input type="hidden" name="response_type" value="code">'
            . '<input type="hidden" name="state" value="' . $state . '">'
            . '<button name="decision" value="deny" type="submit">Deny</button> '
            . '<button name="decision" value="approve" type="submit">Authorize</button></form></main></html>';

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ], $html);
    }

    private function authorize(Request $request, ?AuthenticatedSession $session): Response
    {
        $input = $this->input($request);
        $validated = $this->authorizationParams($input);
        if ($session?->userId() === null || $validated === null) {
            return $this->oauthError(400, 'invalid_request', 'The authorization request is invalid.');
        }
        $limited = $this->enforceOAuthRateLimit($request, 'authorize', $session->userId(), 30, 3600);
        if ($limited !== null) {
            return $limited;
        }
        $app = $this->oauth->app($validated['client_id']);
        if ($app === null || !in_array($validated['redirect_uri'], $app['redirect_uris'], true)
            || array_diff($validated['scopes'], $app['scopes']) !== []) {
            return $this->oauthError(400, 'invalid_client', 'The client, redirect URI or requested scopes are not registered.');
        }
        if (($input['decision'] ?? null) !== 'approve') {
            return $this->redirectWith($validated['redirect_uri'], ['error' => 'access_denied', 'state' => $validated['state']]);
        }

        $code = bin2hex(random_bytes(32));
        $now = $this->runtime->clock->now();
        try {
            $this->oauth->createCode(
                hash('sha256', $code),
                $validated['client_id'],
                $session->userId(),
                $validated['redirect_uri'],
                $validated['scopes'],
                $validated['code_challenge'],
                Time::format($now),
                Time::format($now->modify('+5 minutes')),
            );
        } catch (Throwable) {
            return $this->oauthError(503, 'server_error', 'Authorization could not be completed.');
        }

        return $this->redirectWith($validated['redirect_uri'], ['code' => $code, 'state' => $validated['state']]);
    }

    private function token(Request $request): Response
    {
        $limited = $this->enforceOAuthRateLimit($request, 'token', $request->remoteAddress ?? 'unknown', 60, 60);
        if ($limited !== null) {
            return $limited;
        }
        $this->oauth->deleteExpired(Time::format($this->runtime->clock->now()));
        $input = $this->input($request);
        $clientId = $this->string($input['client_id'] ?? null);
        $app = $this->oauth->app($clientId);
        if ($app === null || !$this->clientSecretMatches($app, $this->string($input['client_secret'] ?? null))) {
            return $this->oauthError(401, 'invalid_client', 'The client credentials are invalid.');
        }

        $grantType = $this->string($input['grant_type'] ?? null);
        if ($grantType === 'authorization_code') {
            return $this->exchangeCode($input, $app);
        }
        if ($grantType === 'refresh_token') {
            return $this->refresh($input, $app);
        }

        return $this->oauthError(400, 'unsupported_grant_type', 'Only authorization_code and refresh_token grants are supported.');
    }

    /** @param array<string, mixed> $input @param array{client_id:string,client_secret_hash:string,name:string,redirect_uris:list<string>,scopes:list<string>,website:?string} $app */
    private function exchangeCode(array $input, array $app): Response
    {
        $code = $this->string($input['code'] ?? null);
        $redirectUri = $this->string($input['redirect_uri'] ?? null);
        $verifier = $this->string($input['code_verifier'] ?? null);
        if ($code === '' || strlen($code) > 128 || $redirectUri === ''
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1) {
            return $this->oauthError(400, 'invalid_grant', 'The authorization code or PKCE verifier is invalid.');
        }
        $now = Time::format($this->runtime->clock->now());
        $grant = $this->oauth->code(hash('sha256', $code), $app['client_id'], $now);
        if ($grant === null || !hash_equals($grant['redirect_uri'], $redirectUri)) {
            return $this->oauthError(400, 'invalid_grant', 'The authorization code is invalid, expired or already used.');
        }
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals($grant['code_challenge'], $challenge)) {
            return $this->oauthError(400, 'invalid_grant', 'The PKCE verifier does not match.');
        }
        $grant = $this->oauth->consumeCode(hash('sha256', $code), $app['client_id'], $now);
        if ($grant === null) {
            return $this->oauthError(400, 'invalid_grant', 'The authorization code is invalid, expired or already used.');
        }

        return $this->issueToken($app['client_id'], $grant['account_id'], $grant['scopes']);
    }

    /** @param array<string, mixed> $input @param array{client_id:string,client_secret_hash:string,name:string,redirect_uris:list<string>,scopes:list<string>,website:?string} $app */
    private function refresh(array $input, array $app): Response
    {
        $refreshToken = $this->string($input['refresh_token'] ?? null);
        if ($refreshToken === '') {
            return $this->oauthError(400, 'invalid_grant', 'A refresh token is required.');
        }
        $grant = $this->oauth->consumeRefresh(hash('sha256', $refreshToken), $app['client_id'], Time::format($this->runtime->clock->now()));
        if ($grant === null) {
            return $this->oauthError(400, 'invalid_grant', 'The refresh token is invalid, expired or already used.');
        }

        return $this->issueToken($grant['client_id'], $grant['account_id'], $grant['scopes']);
    }

    private function issueToken(string $clientId, string $accountId, array $scopes): Response
    {
        $account = $this->runtime->accounts->find($accountId);
        if ($account === null || $account->isDisabled()
            || $this->runtime->app->accountData?->deletionStatus($accountId)->blockedStatus() !== null
        ) {
            return $this->oauthError(400, 'invalid_grant', 'The account is unavailable.');
        }

        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $now = $this->runtime->clock->now();
        try {
            $this->oauth->createToken(
                hash('sha256', $access),
                hash('sha256', $refresh),
                $clientId,
                $accountId,
                $scopes,
                Time::format($now),
                Time::format($now->modify('+' . self::ACCESS_TTL . ' seconds')),
                Time::format($now->modify('+' . self::REFRESH_TTL . ' seconds')),
            );
        } catch (Throwable) {
            return $this->oauthError(503, 'server_error', 'The access token could not be issued.');
        }

        return Response::json([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'scope' => implode(' ', $scopes),
            'created_at' => $now->getTimestamp(),
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function revoke(Request $request): Response
    {
        $input = $this->input($request);
        $app = $this->oauth->app($this->string($input['client_id'] ?? null));
        if ($app === null || !$this->clientSecretMatches($app, $this->string($input['client_secret'] ?? null))) {
            return $this->oauthError(401, 'invalid_client', 'The client credentials are invalid.');
        }
        $token = $this->string($input['token'] ?? null);
        if ($token !== '' && strlen($token) <= 128) {
            $this->oauth->revoke(hash('sha256', $token), $app['client_id'], Time::format($this->runtime->clock->now()));
        }

        return Response::json([], 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $params @return array{client_id:string,redirect_uri:string,scopes:list<string>,code_challenge:string,state:string}|null */
    private function authorizationParams(array $params): ?array
    {
        $clientId = $this->string($params['client_id'] ?? null);
        $redirectUri = $this->string($params['redirect_uri'] ?? null);
        $state = $this->string($params['state'] ?? null);
        $challenge = $this->string($params['code_challenge'] ?? null);
        $method = $this->string($params['code_challenge_method'] ?? 'S256');
        $scopes = $this->scopes($params['scope'] ?? 'read');
        if (preg_match('/^[a-f0-9]{64}$/', $clientId) !== 1
            || $redirectUri === '' || strlen($redirectUri) > 2048
            || $state === '' || strlen($state) > 512
            || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1
            || $method !== 'S256' || $scopes === null
            || ($params['response_type'] ?? null) !== 'code'
        ) {
            return null;
        }

        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'code_challenge' => $challenge,
            'state' => $state,
        ];
    }

    /** @return list<string>|null */
    private function scopes(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $scopes = array_values(array_unique(preg_split('/\s+/', trim($value)) ?: []));
        foreach ($scopes as $scope) {
            if (!in_array($scope, self::SUPPORTED_SCOPES, true)) {
                return null;
            }
        }

        return $scopes;
    }

    /** @return list<string>|null */
    private function redirectUris(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $uris = preg_split('/[\r\n\s]+/', trim($value)) ?: [];
        $uris = array_values(array_unique($uris));
        if ($uris === [] || count($uris) > 10) {
            return null;
        }
        foreach ($uris as $uri) {
            if (!$this->validRedirectUri($uri)) {
                return null;
            }
        }

        return $uris;
    }

    private function validRedirectUri(string $uri): bool
    {
        if (strlen($uri) > 2048 || preg_match('/[\x00-\x20\x7f]/', $uri) === 1) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'https') {
            return isset($parts['host']) && $parts['host'] !== '';
        }
        if ($scheme === 'http') {
            $host = strtolower((string) ($parts['host'] ?? ''));
            return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        }
        // Native clients use private URI schemes (for example, myapp://oauth).
        return preg_match('/^[a-z][a-z0-9+.-]{1,31}$/i', $scheme) === 1
            && !in_array($scheme, ['javascript', 'data', 'file', 'urn'], true);
    }

    /** @return string|false|null */
    private function website(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https'
            || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null
        ) {
            return false;
        }

        return $value;
    }

    /** @param array{client_id:string,client_secret_hash:string,name:string,redirect_uris:list<string>,scopes:list<string>,website:?string} $app */
    private function clientSecretMatches(array $app, string $secret): bool
    {
        return $secret === '' || hash_equals($app['client_secret_hash'], hash('sha256', $secret));
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $contentType = strtolower((string) $request->header('content-type'));
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($request->body, $params);
            return is_array($params) ? $params : [];
        }
        if (str_contains($contentType, 'application/json')) {
            try {
                $params = json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
                return is_array($params) ? $params : [];
            } catch (Throwable) {
                return [];
            }
        }
        return [];
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, scalar> $query */
    private function redirectWith(string $uri, array $query): Response
    {
        $separator = str_contains($uri, '?') ? '&' : '?';
        return new Response(303, [
            'Location' => $uri . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function oauthError(int $status, string $code, string $description): Response
    {
        return Response::json(['error' => $code, 'error_description' => $description], $status, ['Cache-Control' => 'no-store']);
    }

    private function enforceOAuthRateLimit(Request $request, string $operation, string $identity, int $limit, int $window): ?Response
    {
        $retryAfter = $this->runtime->rateLimiter->attempt('mastodon.oauth.' . $operation, $identity, $limit, $window);
        if ($retryAfter === null) {
            return null;
        }

        return Response::json(
            ['error' => 'slow_down', 'error_description' => 'Too many OAuth requests. Try again later.'],
            429,
            ['Cache-Control' => 'no-store', 'Retry-After' => (string) $retryAfter],
        );
    }
}
