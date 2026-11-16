<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Federation;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Security\SecretBoxException;
use AlohaSocial\Core\Support\Time;

use function base64_decode;
use function count;
use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function rtrim;
use function str_pad;
use function str_starts_with;
use function strlen;
use function strtr;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;
use const STR_PAD_RIGHT;

/**
 * Bluesky accounts connected to local Aloha accounts.
 *
 * The host owns the login because the core's AT Protocol port is deliberately
 * credential-agnostic: it only asks "which PDS and token belong to this local
 * user". This service answers that question and keeps the tokens sealed.
 *
 * Connection uses an app password rather than interactive OAuth. An app
 * password is a scoped credential the user can revoke from Bluesky at any
 * time, it works against any PDS, and it needs no browser callback, which
 * keeps a self-hosted instance reachable from behind any domain.
 */
final class BlueskyAccounts implements AtprotoAccountResolver
{
    public const PUBLIC_API = 'https://public.api.bsky.app';

    private const REFRESH_SKEW_SECONDS = 120;

    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly HttpClient $http,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {
    }

    public function resolve(string $localUserId): ?AtprotoAccount
    {
        $row = $this->row($localUserId);
        if ($row === null || (int) ($row['active'] ?? 0) !== 1) {
            return null;
        }

        try {
            $accessToken = $this->box->open((string) $row['access_token']);
            $expiresAt = (int) ($row['expires_at'] ?? 0);
        } catch (SecretBoxException) {
            return null;
        }

        if ($expiresAt > 0 && $expiresAt <= $this->clock->now()->getTimestamp() + self::REFRESH_SKEW_SECONDS) {
            $refreshed = $this->refresh($localUserId, $row);
            if ($refreshed === null) {
                // The stored token is expired and the refresh was refused;
                // reporting the dead token would only fail later at the PDS.
                return null;
            }
            $accessToken = $refreshed;
        }

        try {
            return new AtprotoAccount(
                did: (string) $row['did'],
                handle: (string) $row['handle'],
                pdsUrl: (string) $row['pds_url'],
                accessToken: $accessToken,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{connected: bool, did?: string, handle?: string, pds_url?: string, connected_at?: string, updated_at?: string}
     */
    public function status(string $localUserId): array
    {
        $row = $this->row($localUserId);
        if ($row === null) {
            return ['connected' => false];
        }

        return [
            'connected' => (int) ($row['active'] ?? 0) === 1,
            'did' => (string) $row['did'],
            'handle' => (string) $row['handle'],
            'pds_url' => (string) $row['pds_url'],
            'connected_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Resolves the handle, discovers its PDS and creates a session there.
     *
     * @return array{connected: bool, did: string, handle: string, pds_url: string, connected_at: string, updated_at: string}
     */
    public function connect(string $localUserId, string $handle, string $appPassword): array
    {
        $handle = strtolower(ltrim(trim($handle), '@'));
        $appPassword = trim($appPassword);

        if (preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $handle) !== 1) {
            throw new BlueskyException(
                422,
                'bluesky_handle_invalid',
                'Please enter a valid Bluesky handle, for example name.bsky.social.',
            );
        }

        if ($appPassword === '') {
            throw new BlueskyException(
                422,
                'bluesky_password_missing',
                'A Bluesky app password is required. Create one in Bluesky settings under “App Passwords”.',
            );
        }

        if (strlen($appPassword) > 512) {
            throw new BlueskyException(422, 'bluesky_password_invalid', 'The app password is too long.');
        }

        $did = $this->resolveHandle($handle);
        $pdsUrl = $this->resolvePds($did);
        $session = $this->createSession($pdsUrl, $handle, $appPassword);

        $sessionDid = is_string($session['did'] ?? null) ? $session['did'] : $did;
        $sessionHandle = is_string($session['handle'] ?? null) ? strtolower($session['handle']) : $handle;
        $accessToken = is_string($session['accessJwt'] ?? null) ? $session['accessJwt'] : '';
        $refreshToken = is_string($session['refreshJwt'] ?? null) ? $session['refreshJwt'] : '';
        if ($accessToken === '' || $refreshToken === '') {
            throw new BlueskyException(502, 'bluesky_session_failed', 'Bluesky did not issue a complete session.');
        }

        $this->store(
            $localUserId,
            $sessionDid,
            $sessionHandle,
            $pdsUrl,
            $accessToken,
            $refreshToken,
            $this->expiresAt($accessToken),
        );

        return $this->status($localUserId) + ['connected' => true];
    }

    public function disconnect(string $localUserId): bool
    {
        return $this->db->execute(
            'DELETE FROM atproto_account WHERE account_id = :account_id',
            ['account_id' => $localUserId],
        ) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $localUserId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM atproto_account WHERE account_id = :account_id',
            ['account_id' => $localUserId],
        );
    }

    private function resolveHandle(string $handle): string
    {
        $response = $this->request(
            'GET',
            self::PUBLIC_API . '/xrpc/com.atproto.identity.resolveHandle?handle=' . rawurlencode($handle),
        );

        if ($response->status === 400 || $response->status === 404) {
            throw new BlueskyException(
                422,
                'bluesky_handle_unknown',
                'This Bluesky handle does not exist. Check the spelling.',
            );
        }
        if (!$response->isSuccessful()) {
            throw new BlueskyException(502, 'bluesky_unreachable', 'Bluesky could not resolve the handle.');
        }

        $data = $this->decode($response);
        $did = $data['did'] ?? null;
        if (!is_string($did) || preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $did) !== 1) {
            throw new BlueskyException(502, 'bluesky_identity_invalid', 'Bluesky did not return a valid DID.');
        }

        return $did;
    }

    private function resolvePds(string $did): string
    {
        if (str_starts_with($did, 'did:plc:')) {
            $url = 'https://plc.directory/' . rawurlencode($did);
        } elseif (str_starts_with($did, 'did:web:')) {
            $host = rawurldecode(substr($did, 8));
            if (preg_match('/^[a-z0-9.-]+(?::[0-9]+)?$/', $host) !== 1) {
                throw new BlueskyException(502, 'bluesky_identity_invalid', 'The DID does not contain a valid web host.');
            }
            $url = 'https://' . $host . '/.well-known/did.json';
        } else {
            throw new BlueskyException(422, 'bluesky_identity_unsupported', 'This DID type is not supported.');
        }

        $response = $this->request('GET', $url);
        if (!$response->isSuccessful()) {
            throw new BlueskyException(502, 'bluesky_identity_unavailable', 'The Bluesky identity document is unreachable.');
        }

        $document = $this->decode($response);
        $service = is_array($document['service'] ?? null) ? $document['service'] : [];
        foreach ($service as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';
            $endpoint = is_string($entry['serviceEndpoint'] ?? null) ? $entry['serviceEndpoint'] : '';
            if (($id === '#atproto_pds' || $type === 'AtprotoPersonalDataServer') && $this->isPdsUrl($endpoint)) {
                return rtrim($endpoint, '/');
            }
        }

        throw new BlueskyException(502, 'bluesky_pds_missing', 'The Bluesky profile does not specify a PDS.');
    }

    /**
     * @return array<string, mixed>
     */
    private function createSession(string $pdsUrl, string $handle, string $appPassword): array
    {
        $response = $this->request(
            'POST',
            $pdsUrl . '/xrpc/com.atproto.server.createSession',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode(['identifier' => $handle, 'password' => $appPassword], JSON_THROW_ON_ERROR),
        );

        if ($response->status === 401) {
            throw new BlueskyException(
                422,
                'bluesky_credentials_invalid',
                'Handle or app password is incorrect. Use an app password, not your regular password.',
            );
        }
        if (!$response->isSuccessful()) {
            throw new BlueskyException(502, 'bluesky_session_failed', 'Bluesky rejected the sign-in.');
        }

        return $this->decode($response);
    }

    /**
     * Refreshes an expired access token and returns the new token.
     *
     * A refresh failure deactivates the row instead of retrying forever: the
     * user has to reconnect, which is visible in the settings page.
     *
     * @param array<string, mixed> $row
     */
    private function refresh(string $localUserId, array $row): ?string
    {
        try {
            $refreshToken = $this->box->open((string) $row['refresh_token']);
        } catch (SecretBoxException) {
            return null;
        }

        try {
            $response = $this->request(
                'POST',
                rtrim((string) $row['pds_url'], '/') . '/xrpc/com.atproto.server.refreshSession',
                ['Authorization' => 'Bearer ' . $refreshToken, 'Accept' => 'application/json'],
            );
        } catch (BlueskyException) {
            return null;
        }

        if (!$response->isSuccessful()) {
            $this->db->execute(
                'UPDATE atproto_account SET active = 0, updated_at = :updated_at WHERE account_id = :account_id',
                ['account_id' => $localUserId, 'updated_at' => Time::format($this->clock->now())],
            );

            return null;
        }

        $data = $this->decode($response);
        $accessToken = is_string($data['accessJwt'] ?? null) ? $data['accessJwt'] : '';
        $newRefresh = is_string($data['refreshJwt'] ?? null) ? $data['refreshJwt'] : $refreshToken;
        if ($accessToken === '') {
            return null;
        }

        $this->store(
            $localUserId,
            (string) $row['did'],
            (string) $row['handle'],
            (string) $row['pds_url'],
            $accessToken,
            $newRefresh,
            $this->expiresAt($accessToken),
        );

        return $accessToken;
    }

    private function store(
        string $localUserId,
        string $did,
        string $handle,
        string $pdsUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresAt,
    ): void {
        $now = Time::format($this->clock->now());

        try {
            $this->db->execute(
                'INSERT INTO atproto_account (account_id, did, handle, pds_url, access_token, refresh_token, expires_at, active, created_at, updated_at)'
                . ' VALUES (:account_id, :did, :handle, :pds_url, :access_token, :refresh_token, :expires_at, 1, :created_at, :updated_at)'
                . ' ON CONFLICT (account_id) DO UPDATE SET'
                . ' did = excluded.did,'
                . ' handle = excluded.handle,'
                . ' pds_url = excluded.pds_url,'
                . ' access_token = excluded.access_token,'
                . ' refresh_token = excluded.refresh_token,'
                . ' expires_at = excluded.expires_at,'
                . ' active = 1,'
                . ' updated_at = excluded.updated_at',
                [
                    'account_id' => $localUserId,
                    'did' => $did,
                    'handle' => $handle,
                    'pds_url' => $pdsUrl,
                    'access_token' => $this->box->seal($accessToken),
                    'refresh_token' => $this->box->seal($refreshToken),
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        } catch (\PDOException) {
            throw new BlueskyException(
                409,
                'bluesky_already_connected',
                'This Bluesky account is already connected to another Aloha account.',
            );
        }
    }

    private function expiresAt(string $jwt): int
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return 0;
        }

        $payload = $parts[1];
        $remainder = strlen($payload) % 4;
        if ($remainder !== 0) {
            $payload .= str_pad('', 4 - $remainder, '=', STR_PAD_RIGHT);
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
            return 0;
        }

        $claims = json_decode($decoded, true);
        if (!is_array($claims) || !is_numeric($claims['exp'] ?? null)) {
            return 0;
        }

        return (int) $claims['exp'];
    }

    private function isPdsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            && (string) $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers = [], string $body = ''): HttpResponse
    {
        try {
            return $this->http->request($method, $url, $headers, $body, self::TIMEOUT_SECONDS);
        } catch (HttpClientException $exception) {
            throw new BlueskyException(
                502,
                'bluesky_unreachable',
                'Bluesky is unreachable: ' . $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BlueskyException(502, 'bluesky_response_invalid', 'Bluesky returned an invalid response.');
        }

        if (!is_array($data)) {
            throw new BlueskyException(502, 'bluesky_response_invalid', 'Bluesky returned an invalid response.');
        }

        return $data;
    }
}
