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
use AlohaSocial\Server\Accounts\Account;
use AlohaSocial\Server\Auth\AtprotoSessionRepository;
use AlohaSocial\Server\Auth\PasswordHasher;
use AlohaSocial\Server\Bootstrap\Runtime;
use JsonException;

/**
 * ATProto session endpoints for the optional native PDS.
 *
 * The core's PDS handler reads and writes records but deliberately leaves
 * authentication to the host. These routes provide the missing
 * `com.atproto.server.*` surface so a normal ATProto client can sign in with
 * the member's account, receive Bearer credentials, and then call
 * `com.atproto.identity.*`, `com.atproto.repo.*` and `com.atproto.sync.*`.
 *
 * Tokens are HS256 JWTs so standard clients can read their expiry, stored
 * only as SHA-256 hashes on this host, and scoped to the XRPC prefix. The
 * browser session remains the authority for the web UI.
 */
final class AtprotoSessionController
{
    private const PREFIX = '/xrpc/com.atproto.server.';

    private const ACCESS_LIFETIME_SECONDS = 3600;

    private const REFRESH_LIFETIME_SECONDS = 2_592_000;

    private const CREATE_SESSION_LIMIT = 20;

    private const CREATE_SESSION_WINDOW_SECONDS = 900;

    private readonly AtprotoSessionRepository $sessions;

    private readonly PasswordHasher $hasher;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
        $this->sessions = new AtprotoSessionRepository($runtime->database);
        $this->hasher = new PasswordHasher();
    }

    public function handle(Request $request): ?Response
    {
        if (!str_starts_with($request->path, self::PREFIX)) {
            return null;
        }

        if (!$this->runtime->config->atprotoPds || $this->runtime->pds === null) {
            return $this->error(501, 'NotEnabled', 'This instance does not host an AT Protocol PDS.');
        }

        try {
            return match ($request->path) {
                self::PREFIX . 'createSession' => $this->createSession($request),
                self::PREFIX . 'getSession' => $this->getSession($request),
                self::PREFIX . 'refreshSession' => $this->refreshSession($request),
                self::PREFIX . 'deleteSession' => $this->deleteSession($request),
                default => null,
            };
        } catch (JsonException | \AlohaSocial\Core\Http\HttpException) {
            return $this->error(400, 'InvalidRequest', 'The request body is not valid JSON.');
        }
    }

    /**
     * Attach a valid access token to an XRPC request.
     *
     * A token is only accepted under `/xrpc/`; it must not turn into a
     * general-purpose bypass of the web session's CSRF protection.
     */
    public function authenticate(Request $request): Request
    {
        if (!str_starts_with($request->path, '/xrpc/')
            || !$this->runtime->config->atprotoPds
            || $this->runtime->pds === null
        ) {
            return $request;
        }

        $raw = $this->bearer($request);
        if ($raw === null) {
            return $request;
        }

        $accountId = $this->sessions->accountForAccess(
            hash('sha256', $raw),
            Time::format($this->runtime->clock->now()),
        );
        if ($accountId === null) {
            return $request;
        }

        $account = $this->runtime->accounts->find($accountId);
        if ($account === null || $account->isDisabled()) {
            return $request;
        }

        return $request->withUserId($account->id)->withScopes(['*']);
    }

    private function createSession(Request $request): Response
    {
        $body = $request->json();
        $identifier = is_string($body['identifier'] ?? null) ? trim($body['identifier']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        if ($identifier === '' || $password === '' || mb_strlen($identifier) > 255 || mb_strlen($password) > 1024) {
            return $this->error(400, 'InvalidRequest', 'identifier and password are required.');
        }

        $ip = $request->remoteAddress ?? 'unknown';
        if ($this->runtime->rateLimiter->attempt(
            'host.atproto.create_session',
            $ip,
            self::CREATE_SESSION_LIMIT,
            self::CREATE_SESSION_WINDOW_SECONDS,
        ) !== null) {
            return $this->error(429, 'RateLimitExceeded', 'Too many sign-in attempts. Try again later.');
        }

        if ($this->runtime->throttle->isLimited($identifier, $request->remoteAddress)) {
            return $this->error(429, 'RateLimitExceeded', 'Too many sign-in attempts. Try again later.');
        }

        $account = $this->runtime->accounts->findByHandle($identifier)
            ?? $this->runtime->accounts->findByEmail($identifier);
        if ($account === null
            || $account->isDisabled()
            || !$this->hasher->verify($password, $account->passwordHash)
        ) {
            $this->runtime->throttle->recordFailure($identifier, $request->remoteAddress);

            return $this->error(401, 'AuthenticationRequired', 'Invalid identifier or password.');
        }

        $identity = $this->runtime->pdsRepository->identityForUser($account->id);
        if ($identity === null) {
            return $this->error(
                409,
                'RepoNotFound',
                'Create your AT Protocol identity in settings before signing in with an ATProto client.',
            );
        }

        $this->runtime->throttle->clear($identifier, $request->remoteAddress);
        $tokens = $this->issue($account, $identity->did, $identity->handle);

        return Response::json($this->sessionPayload($account, $identity->did, $identity->handle, $tokens));
    }

    private function getSession(Request $request): Response
    {
        $raw = $this->bearer($request);
        if ($raw === null) {
            return $this->error(401, 'AuthenticationRequired', 'An access token is required.');
        }

        $now = Time::format($this->runtime->clock->now());
        $accountId = $this->sessions->accountForAccess(hash('sha256', $raw), $now);
        if ($accountId === null) {
            return $this->error(401, 'AuthenticationRequired', 'The access token is invalid or expired.');
        }

        $account = $this->runtime->accounts->find($accountId);
        if ($account === null || $account->isDisabled()) {
            return $this->error(401, 'AuthenticationRequired', 'The account is not active.');
        }

        $identity = $this->runtime->pdsRepository->identityForUser($account->id);
        if ($identity === null) {
            return $this->error(409, 'RepoNotFound', 'This account has no AT Protocol repository.');
        }

        $payload = [
            'did' => $identity->did,
            'handle' => $identity->handle,
            'email' => $account->email,
            'emailConfirmed' => true,
            'active' => true,
        ];

        try {
            $payload['didDoc'] = $this->runtime->pds?->resolveDid($identity->did);
        } catch (\Throwable) {
            // A DID document is helpful but not required to keep an already
            // authenticated client session alive.
        }

        return Response::json($payload);
    }

    private function refreshSession(Request $request): Response
    {
        $raw = $this->bearer($request);
        if ($raw === null) {
            return $this->error(401, 'AuthenticationRequired', 'A refresh token is required.');
        }

        $now = Time::format($this->runtime->clock->now());
        $refreshHash = hash('sha256', $raw);
        $accountId = $this->sessions->accountForRefresh($refreshHash, $now);
        if ($accountId === null) {
            return $this->error(401, 'AuthenticationRequired', 'The refresh token is invalid or expired.');
        }

        $account = $this->runtime->accounts->find($accountId);
        if ($account === null || $account->isDisabled()) {
            return $this->error(401, 'AuthenticationRequired', 'The account is not active.');
        }

        $identity = $this->runtime->pdsRepository->identityForUser($account->id);
        if ($identity === null) {
            return $this->error(409, 'RepoNotFound', 'This account has no AT Protocol repository.');
        }

        $this->sessions->deleteByRefresh($refreshHash);
        $tokens = $this->issue($account, $identity->did, $identity->handle);

        return Response::json($this->sessionPayload($account, $identity->did, $identity->handle, $tokens));
    }

    private function deleteSession(Request $request): Response
    {
        $raw = $this->bearer($request);
        if ($raw !== null) {
            // The presented token may be either half of the pair. Remove the
            // whole row so deleting a session with its access token cannot
            // leave a usable refresh token behind.
            $this->sessions->deleteByTokenHash(hash('sha256', $raw));
        }

        return Response::json([]);
    }

    /**
     * @return array{accessJwt: string, refreshJwt: string}
     */
    private function issue(Account $account, string $did, string $handle): array
    {
        $now = $this->runtime->clock->now();
        $accessExpires = $now->modify('+' . self::ACCESS_LIFETIME_SECONDS . ' seconds');
        $refreshExpires = $now->modify('+' . self::REFRESH_LIFETIME_SECONDS . ' seconds');
        $access = $this->token($did, $handle, $now, $accessExpires, 'access');
        $refresh = $this->token($did, $handle, $now, $refreshExpires, 'refresh');

        $this->sessions->deleteExpired(Time::format($now));
        $this->sessions->create(
            $account->id,
            hash('sha256', $access),
            hash('sha256', $refresh),
            Time::format($now),
            Time::format($accessExpires),
            Time::format($refreshExpires),
        );

        return ['accessJwt' => $access, 'refreshJwt' => $refresh];
    }

    private function token(string $did, string $handle, \DateTimeImmutable $now, \DateTimeImmutable $expires, string $kind): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'iss' => rtrim($this->runtime->config->instanceUrl, '/'),
            'sub' => $did,
            'aud' => rtrim($this->runtime->config->atprotoPdsUrl, '/'),
            'iat' => $now->getTimestamp(),
            'exp' => $expires->getTimestamp(),
            'jti' => bin2hex(random_bytes(12)),
            'scope' => $kind,
            'handle' => $handle,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac(
            'sha256',
            $header . '.' . $payload,
            $this->signingKey(),
            true,
        ));

        return $header . '.' . $payload . '.' . $signature;
    }

    private function signingKey(): string
    {
        $secret = $this->runtime->config->secretKey;
        if ($secret === null) {
            $secret = trim((string) @file_get_contents($this->runtime->config->secretKeyFile));
        }

        $raw = base64_decode(trim((string) $secret), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \RuntimeException('The instance secret is not available for ATProto token signing.');
        }

        return hash_hmac('sha256', 'atproto-session-jwt', $raw, true);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array{accessJwt: string, refreshJwt: string} $tokens
     *
     * @return array<string, mixed>
     */
    private function sessionPayload(Account $account, string $did, string $handle, array $tokens): array
    {
        return [
            'did' => $did,
            'handle' => $handle,
            'email' => $account->email,
            'emailConfirmed' => true,
            'active' => true,
            'accessJwt' => $tokens['accessJwt'],
            'refreshJwt' => $tokens['refreshJwt'],
        ];
    }

    private function bearer(Request $request): ?string
    {
        $header = trim((string) ($request->header('authorization') ?? ''));
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function error(int $status, string $code, string $message): Response
    {
        return Response::json(['error' => $code, 'message' => $message], $status);
    }
}
